/**
 * หน้า /trade — ซื้อขาย IDTC ผ่าน TPIX DEX (Uniswap V2 fork, ค่าธรรมเนียม 0.3%)
 *
 * คู่เทรด: IDTC/USDT (USDT_TPIX 6 ทศนิยม) และ IDTC/TPIX (TPIX ของเชน ผ่าน WTPIX)
 * ตัวเลขทุกตัวเป็น BigInt สูตรเดียวกับ TPIXDEXLibrary.getAmountOut แล้วยืนยันกับ router.getAmountsOut อีกชั้น
 * ลำดับ: เชื่อมกระเป๋า → อนุญาตเหรียญขาเข้าเท่ายอด (ถ้าไม่ใช่ TPIX) → swap พร้อม amountOutMin ตาม slippage → รอ receipt
 * RPC ของเชน TPIX ไม่ส่งเหตุผล revert กลับมา จึงจำลอง (estimateGas) ก่อนส่ง แล้วหาสาเหตุเองจากสถานะล่าสุด
 */
import Alpine from 'alpinejs';
import { Contract, Interface, ZeroAddress, getAddress } from 'ethers';
import {
    ABI, CFG, assertWallet, chainNow, connectWallet, contractAddress, decodeRevert, explorerAddress, explorerTx,
    formatAmount, injected, isMobile, isRejected, parseAmount, readProvider, sendTx, short, syncChainClock,
    unitsToInput, waitReceipt, walletAccount, walletError, watchToken, withTimeout,
} from './chain';
import { t } from './i18n';

const DEX = CFG.dex || {};
const FEE_BPS = BigInt(DEX.fee_bps ?? 30);
const MAX_SLIP = Number(DEX.max_slippage_bps ?? 1500);
const WARN_BPS = Number(DEX.impact_warn_bps ?? 300);
const CONFIRM_BPS = Number(DEX.impact_confirm_bps ?? 1500);
const DEADLINE_S = Number(DEX.deadline_minutes ?? 20) * 60;
const TOKEN_DEC = Number(CFG.token?.decimals ?? 18);
const PAY_DEC = Number(CFG.sale?.pay_decimals ?? 6);

const ERC20_ABI = [...ABI.erc20, ...ABI.errors, 'event Transfer(address indexed from, address indexed to, uint256 value)', 'function paused() view returns (bool)'];
const PAIR_ABI = ['function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)', 'function token0() view returns (address)'];
const FACTORY_ABI = ['function getPair(address, address) view returns (address)'];
const ROUTER_ABI = [
    'function getAmountsOut(uint256 amountIn, address[] path) view returns (uint256[] amounts)',
    'function swapExactTokensForTokens(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline) returns (uint256[] amounts)',
    'function swapExactETHForTokens(uint256 amountOutMin, address[] path, address to, uint256 deadline) payable returns (uint256[] amounts)',
    'function swapExactTokensForETH(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline) returns (uint256[] amounts)',
];
const SALE_ABI = ['function isActive() view returns (bool)', 'function remaining() view returns (uint256)', 'function rateNumerator() view returns (uint256)', 'function rateDenominator() view returns (uint256)', 'function saleToken() view returns (address)'];
const transferIface = new Interface(ERC20_ABI);

/** เหรียญฝั่งตรงข้ามของ IDTC */
const COUNTERS = {
    usdt: { key: 'usdt', symbol: 'USDT', decimals: PAY_DEC, native: false, pairKey: 'pair_usdt' },
    tpix: { key: 'tpix', symbol: 'TPIX', decimals: 18, native: true, pairKey: 'pair_tpix' },
};

/** สูตรของ TPIXDEXLibrary.getAmountOut (ปัดลง) */
export function amountOut(amountIn, reserveIn, reserveOut, feeBps = FEE_BPS) {
    if (amountIn <= 0n || reserveIn <= 0n || reserveOut <= 0n) return 0n;
    const inWithFee = amountIn * (10000n - feeBps);
    return (inWithFee * reserveOut) / (reserveIn * 10000n + inWithFee);
}

/** ผลกระทบราคาเป็น bps ไม่รวมค่าธรรมเนียม: 1 − ได้จริง ÷ ได้ตามราคากลาง (หลังหักค่าธรรมเนียม) */
export function impactBps(amountIn, out, reserveIn, reserveOut, feeBps = FEE_BPS) {
    if (amountIn <= 0n || reserveIn <= 0n) return 0;
    const ideal = (amountIn * (10000n - feeBps) * reserveOut) / (reserveIn * 10000n);
    if (ideal <= 0n) return 0;
    return Number(((ideal - out) * 10000n) / ideal);
}

export const minOut = (out, slipBps) => (out * BigInt(10000 - slipBps)) / 10000n;

const halt = (code) => Object.assign(new Error(code), { code });
const SLIP_KEY = 'idtc:trade:slippage';

Alpine.data('trade', () => ({
    tokenAddr: contractAddress('token'),
    routerAddr: contractAddress('dex_router'),
    factoryAddr: contractAddress('dex_factory'),
    wtpixAddr: contractAddress('wtpix'),
    usdtAddr: contractAddress('usdt'),
    saleAddr: contractAddress('presale'),

    side: 'buy',            // 'buy' = จ่ายเหรียญฝั่งตรงข้าม รับ IDTC · 'sell' = จ่าย IDTC รับเหรียญฝั่งตรงข้าม
    counterKey: 'usdt',
    input: '',

    // พูล
    pairAddr: null,
    reserveIdtc: 0n,
    reserveCounter: 0n,
    poolLoaded: false,
    poolLoading: false,
    poolError: '',
    chainQuote: null,
    chainQuoteFor: '',

    // presale ใช้เทียบราคาตอนซื้อด้วย USDT
    sale: null,             // { num, den, remaining }

    // ตั้งค่า
    slipBps: Number(DEX.slippage_bps ?? 100),
    slipInput: '',
    showSettings: false,
    impactAck: false,

    // กระเป๋า
    account: null,
    walletChainOk: true,
    balIdtc: null,
    balCounter: null,
    allowance: null,
    noWallet: false,

    // ธุรกรรม
    busy: false,
    phase: '',
    flowId: 0,
    flowAccount: null,
    error: '',
    errorTx: '',
    notice: '',
    success: null,

    short,
    explorerTx,
    explorerAddress,

    init() {
        try {
            const s = Number(localStorage.getItem(SLIP_KEY));
            if (Number.isInteger(s) && s >= 1 && s <= MAX_SLIP) this.slipBps = s;
        } catch { /* storage ปิดอยู่ — ใช้ค่าเริ่มต้น */ }
        const q = new URLSearchParams(location.search);
        if (q.get('side') === 'sell') this.side = 'sell';
        if (COUNTERS[q.get('with')]) this.counterKey = q.get('with');

        this._poll = setInterval(() => { if (!document.hidden && !this.busy) this.refresh(); }, 15000);
        this._onAccount = (e) => this.setAccount(e.detail || null);
        window.addEventListener('idtc:account', this._onAccount);
        const eth = injected();
        if (eth?.on) {
            this._onAccounts = (a) => this.setAccount(a?.[0] || null);
            this._onChain = (cid) => { this.walletChainOk = Number.parseInt(cid, 16) === Number(CFG.chain.id); };
            eth.on('accountsChanged', this._onAccounts);
            eth.on('chainChanged', this._onChain);
            eth.request({ method: 'eth_chainId' }).then(this._onChain).catch(() => {});
        }
        walletAccount().then((a) => a && this.setAccount(a));
        if (this.tokenAddr) syncChainClock();
        this.loadPool();
        this.loadSale();
    },

    destroy() {
        clearInterval(this._poll); clearTimeout(this._qt);
        window.removeEventListener('idtc:account', this._onAccount);
        const eth = injected();
        if (eth?.removeListener && this._onAccounts) { eth.removeListener('accountsChanged', this._onAccounts); eth.removeListener('chainChanged', this._onChain); }
    },

    refresh() { this.loadPool(); this.loadUser(); },

    /* ───────── ค่าพื้นฐาน ───────── */
    get counter() { return COUNTERS[this.counterKey]; },
    get counterAddr() { return this.counter.native ? this.wtpixAddr : this.usdtAddr; },
    get ready() { return !!(this.tokenAddr && this.routerAddr && this.factoryAddr && this.counterAddr); },
    get inDec() { return this.side === 'buy' ? this.counter.decimals : TOKEN_DEC; },
    get outDec() { return this.side === 'buy' ? TOKEN_DEC : this.counter.decimals; },
    get inSymbol() { return this.side === 'buy' ? this.counter.symbol : 'IDTC'; },
    get outSymbol() { return this.side === 'buy' ? 'IDTC' : this.counter.symbol; },
    get path() { return this.side === 'buy' ? [this.counterAddr, this.tokenAddr] : [this.tokenAddr, this.counterAddr]; },
    get reserveIn() { return this.side === 'buy' ? this.reserveCounter : this.reserveIdtc; },
    get reserveOut() { return this.side === 'buy' ? this.reserveIdtc : this.reserveCounter; },
    get balIn() { return this.side === 'buy' ? this.balCounter : this.balIdtc; },
    get balOut() { return this.side === 'buy' ? this.balIdtc : this.balCounter; },
    get needsApprove() { return !(this.side === 'buy' && this.counter.native) && (this.allowance === null || this.allowance < this.inUnits); },

    setSide(s) {
        if (this.busy || s === this.side) return;
        this.side = s;
        this.input = '';
        this.resetFlow();
        this.loadUser();
    },
    flip() { this.setSide(this.side === 'buy' ? 'sell' : 'buy'); },
    setCounter(k) {
        if (this.busy || k === this.counterKey || !COUNTERS[k]) return;
        this.counterKey = k;
        this.input = '';
        this.resetFlow();
        this.pairAddr = null; this.poolLoaded = false; this.reserveIdtc = 0n; this.reserveCounter = 0n;
        this.loadPool();
        this.loadUser();
    },
    resetFlow() { this.success = null; this.error = ''; this.errorTx = ''; this.notice = ''; this.impactAck = false; this.chainQuote = null; },

    /* ───────── อ่านพูล ───────── */
    /** อ่านพูล — ถ้ากำลังอ่านอยู่ รอรอบเดิมให้จบ (ขั้นซื้อขายต้องได้ค่าสดเสมอ ไม่ใช่ข้ามไป) */
    loadPool() {
        if (!this.ready) return Promise.resolve();
        const key = this.counterKey;
        if (this._poolP && this._poolKey === key) return this._poolP;
        const p = this.readPool().finally(() => { if (this._poolP === p) this._poolP = null; });
        this._poolP = p; this._poolKey = key;
        return p;
    },

    async readPool() {
        this.poolLoading = true;
        const key = this.counterKey;
        try {
            let pair = contractAddress(this.counter.pairKey);
            if (!pair) {
                const found = await withTimeout(new Contract(this.factoryAddr, FACTORY_ABI, readProvider()).getPair(this.tokenAddr, this.counterAddr), 10000);
                pair = found && found !== ZeroAddress ? getAddress(found) : null;
            }
            if (key !== this.counterKey) return;
            if (!pair) {
                Object.assign(this, { pairAddr: null, reserveIdtc: 0n, reserveCounter: 0n, poolLoaded: true, poolError: '' });
                return;
            }
            const p = new Contract(pair, PAIR_ABI, readProvider());
            const [[r0, r1], token0] = await withTimeout(Promise.all([p.getReserves(), p.token0()]), 10000);
            if (key !== this.counterKey) return;
            const idtcIs0 = token0.toLowerCase() === this.tokenAddr.toLowerCase();
            Object.assign(this, {
                pairAddr: pair,
                reserveIdtc: idtcIs0 ? r0 : r1,
                reserveCounter: idtcIs0 ? r1 : r0,
                poolLoaded: true,
                poolError: '',
            });
            this.checkQuote();
        } catch {
            if (key === this.counterKey) this.poolError = t('trade.load_net');
        } finally {
            this.poolLoading = false;
        }
    },

    async loadSale() {
        if (!this.saleAddr || !this.tokenAddr) return;
        try {
            const s = new Contract(this.saleAddr, SALE_ABI, readProvider());
            const [active, remaining, num, den, saleToken] = await withTimeout(Promise.all([s.isActive(), s.remaining(), s.rateNumerator(), s.rateDenominator(), s.saleToken()]), 10000);
            this.sale = active && remaining > 0n && saleToken.toLowerCase() === this.tokenAddr.toLowerCase() ? { num, den, remaining } : null;
        } catch { this.sale = null; }
    },

    async loadUser() {
        const acct = this.account;
        if (!acct || !this.ready) return;
        const side = this.side; const key = this.counterKey;
        try {
            const idtc = new Contract(this.tokenAddr, ERC20_ABI, readProvider());
            const c = this.counter;
            const inToken = side === 'buy' ? (c.native ? null : this.counterAddr) : this.tokenAddr;
            const [bi, bc, al] = await withTimeout(Promise.all([
                idtc.balanceOf(acct),
                c.native ? readProvider().getBalance(acct) : new Contract(this.counterAddr, ERC20_ABI, readProvider()).balanceOf(acct),
                inToken ? new Contract(inToken, ERC20_ABI, readProvider()).allowance(acct, this.routerAddr) : null,
            ]), 15000);
            if (acct !== this.account || side !== this.side || key !== this.counterKey) return;
            Object.assign(this, { balIdtc: bi, balCounter: bc, allowance: al });
        } catch { /* RPC ช้า — คงค่าเดิม */ }
    },

    setAccount(a) {
        const next = a ? String(a) : null;
        if ((next || '').toLowerCase() === (this.account || '').toLowerCase()) return;
        if (this.busy && this.flowAccount && (next || '').toLowerCase() !== this.flowAccount) {
            this.flowId++;
            this.busy = false; this.phase = '';
            this.error = walletError({ code: 'ACCOUNT_CHANGED' });
        }
        this.account = next;
        this.balIdtc = null; this.balCounter = null; this.allowance = null;
        if (!next) this.success = null;
        this.loadUser();
    },

    /* ───────── ราคา ───────── */
    get hasPool() { return !!this.pairAddr && this.reserveIdtc > 0n && this.reserveCounter > 0n; },
    get parsed() { return parseAmount(this.input, this.inDec); },
    get inUnits() { return this.parsed.units ?? 0n; },
    get localOut() { return amountOut(this.inUnits, this.reserveIn, this.reserveOut); },
    get outUnits() {
        const fresh = this.chainQuote !== null && this.chainQuoteFor === `${this.side}:${this.counterKey}:${this.inUnits}`;
        return fresh ? this.chainQuote : this.localOut;
    },
    get minOutUnits() { return minOut(this.outUnits, this.slipBps); },
    get impact() { return impactBps(this.inUnits, this.outUnits, this.reserveIn, this.reserveOut); },
    get impactText() { return this.impact < 1 ? '< 0.01%' : `${(this.impact / 100).toFixed(2)}%`; },
    get impactLevel() { return this.impact >= CONFIRM_BPS ? 'high' : this.impact >= WARN_BPS ? 'warn' : 'ok'; },
    get slipText() { return `${(this.slipBps / 100).toFixed(2).replace(/\.?0+$/, '')}%`; },

    /** ราคากลางของพูล: 1 IDTC = ? เหรียญฝั่งตรงข้าม (แสดงทศนิยมพอให้เห็นราคาเล็ก ๆ) */
    get priceText() {
        if (!this.hasPool) return '—';
        const scaled = (this.reserveCounter * 10n ** BigInt(TOKEN_DEC + 12)) / this.reserveIdtc; // หน่วย counter × 1e12
        return formatAmount(scaled, this.counter.decimals + 12, 12, { trim: true });
    },
    /** 1 เหรียญฝั่งตรงข้าม = ? IDTC */
    get inverseText() {
        if (!this.hasPool) return '—';
        const v = (this.reserveIdtc * 10n ** BigInt(this.counter.decimals)) / this.reserveCounter;
        return formatAmount(v, TOKEN_DEC, 2);
    },

    /** ซื้อผ่าน presale ได้เหรียญมากกว่าไหม (เฉพาะซื้อด้วย USDT ตอน presale เปิดอยู่) */
    get presaleOut() {
        if (!this.sale || this.side !== 'buy' || this.counterKey !== 'usdt' || this.inUnits <= 0n) return null;
        const out = (this.inUnits * this.sale.num) / this.sale.den;
        return out <= this.sale.remaining ? out : null;
    },
    get presaleBetter() { return this.presaleOut !== null && this.presaleOut > this.outUnits; },

    onInput() {
        this.success = null; this.impactAck = false;
        if (this.error && !this.busy) { this.error = ''; this.errorTx = ''; }
        clearTimeout(this._qt);
        this._qt = setTimeout(() => this.checkQuote(), 350);
    },
    setPct(pct) {
        if (this.busy || this.balIn === null) return;
        this.input = unitsToInput((this.balIn * BigInt(pct)) / 100n, this.inDec);
        this.onInput();
    },

    async checkQuote() {
        if (!this.hasPool || this.inUnits <= 0n) { this.chainQuote = null; return; }
        const tag = `${this.side}:${this.counterKey}:${this.inUnits}`;
        try {
            const amounts = await withTimeout(new Contract(this.routerAddr, ROUTER_ABI, readProvider()).getAmountsOut(this.inUnits, this.path), 10000);
            if (tag === `${this.side}:${this.counterKey}:${this.inUnits}`) { this.chainQuote = amounts[amounts.length - 1]; this.chainQuoteFor = tag; }
        } catch { /* ใช้ค่าที่คำนวณเอง */ }
    },

    /* ───────── slippage ───────── */
    setSlip(bps) {
        this.slipBps = bps; this.slipInput = '';
        try { localStorage.setItem(SLIP_KEY, String(bps)); } catch { /* storage ปิดอยู่ */ }
    },
    onSlipInput() {
        const v = Number(String(this.slipInput).replace(',', '.'));
        if (!Number.isFinite(v)) return;
        const bps = Math.round(v * 100);
        if (bps < 1 || bps > MAX_SLIP) return;
        const typed = this.slipInput;
        this.setSlip(bps);
        this.slipInput = typed; // คงข้อความที่พิมพ์ไว้ในช่อง
    },
    get slipProblem() {
        if (this.slipInput === '') return '';
        const v = Number(String(this.slipInput).replace(',', '.'));
        if (!Number.isFinite(v) || v * 100 < 1 || v * 100 > MAX_SLIP) return t('trade.slip_range', { max: MAX_SLIP / 100 });
        return '';
    },

    /* ───────── ตรวจยอด ───────── */
    get problem() {
        const p = this.parsed;
        if (p.error) return p.error;
        if (p.empty) return '';
        if (p.units === 0n) return t('trade.problem.zero');
        if (!this.hasPool) return '';
        if (this.localOut === 0n) return t('trade.problem.too_small');
        if (this.outUnits >= this.reserveOut) return t('trade.problem.liquidity');
        if (this.account && this.balIn !== null && p.units > this.balIn) return t('trade.problem.balance', { bal: formatAmount(this.balIn, this.inDec, 2), sym: this.inSymbol });
        return '';
    },
    get amountOk() { return !this.parsed.empty && !this.problem && this.inUnits > 0n && this.outUnits > 0n; },
    get impactBlocked() { return this.impactLevel === 'high' && !this.impactAck; },
    get canSwap() { return this.ready && this.hasPool && this.amountOk && !this.busy && !!this.account && !this.impactBlocked; },
    get status() {
        if (!this.tokenAddr) return 'nodeploy';
        if (!this.poolLoaded) return this.poolError ? 'error' : 'loading';
        return this.hasPool ? 'live' : 'nopool';
    },

    /* ───────── ตัวช่วยแสดงผล ───────── */
    amt(v, dec, d = 2) { return v === null || v === undefined ? '—' : formatAmount(v, dec, d); },
    get outText() { return this.inUnits > 0n && this.hasPool ? formatAmount(this.outUnits, this.outDec, this.outDec === TOKEN_DEC ? 2 : 6, { trim: this.outDec !== TOKEN_DEC }) : '0'; },
    get minOutText() { return formatAmount(this.minOutUnits, this.outDec, this.outDec === TOKEN_DEC ? 2 : 6, { trim: this.outDec !== TOKEN_DEC }); },
    get phaseText() { return this.phase ? t(`trade.phase.${this.phase}`) : ''; },
    get mobileNoWallet() { return this.noWallet && isMobile(); },

    /* ───────── กระเป๋าและธุรกรรม ───────── */
    async connect() {
        if (this.busy) return;
        this.error = ''; this.errorTx = ''; this.noWallet = false;
        this.busy = true; this.phase = 'connect';
        try {
            const { account } = await connectWallet();
            this.walletChainOk = true;
            this.setAccount(account);
            window.dispatchEvent(new CustomEvent('idtc:account', { detail: account }));
        } catch (e) {
            this.noWallet = e?.code === 'NO_WALLET';
            this.error = walletError(e);
        } finally {
            this.busy = false; this.phase = '';
        }
    },

    cancelFlow() {
        if (!this.busy || this.phase.endsWith('-wait')) return;
        this.flowId++;
        this.busy = false; this.phase = '';
        this.notice = t('trade.cancelled');
    },

    async swap() {
        if (!this.canSwap) return;
        const id = ++this.flowId;
        const alive = () => { if (id !== this.flowId) throw halt('CANCELLED'); };
        const account = this.account;
        const side = this.side; const c = this.counter;
        const amountIn = this.inUnits;
        this.busy = true; this.error = ''; this.errorTx = ''; this.notice = ''; this.success = null;
        this.flowAccount = account.toLowerCase();
        try {
            this.phase = 'prepare';
            const { signer, account: walletAcct } = await connectWallet();
            alive();
            if (walletAcct.toLowerCase() !== this.flowAccount) throw halt('ACCOUNT_CHANGED');

            // อ่านพูลและยอดสดก่อนคำนวณยอดขั้นต่ำ กันใช้ราคาเก่า
            await Promise.all([this.loadPool(), this.loadUser()]);
            await this.checkQuote();
            alive();
            if (this.side !== side || this.counter !== c || this.inUnits !== amountIn) throw halt('CANCELLED');
            if (!this.hasPool) throw Object.assign(new Error(t('trade.no_pool')), { code: 'PRECHECK' });
            if (this.problem) throw Object.assign(new Error(this.problem), { code: 'PRECHECK' });
            if (this.impactBlocked) throw Object.assign(new Error(t('trade.impact_confirm')), { code: 'PRECHECK' });
            const quoted = this.outUnits;
            const min = this.minOutUnits;
            const path = this.path;
            const router = new Contract(this.routerAddr, ROUTER_ABI, readProvider());

            if (this.needsApprove) {
                this.phase = 'approve';
                const inToken = new Contract(path[0], ERC20_ABI, readProvider());
                const tx = await inToken.approve.populateTransaction(this.routerAddr, amountIn);
                const hash = await this.send(signer, account, tx);
                alive();
                this.phase = 'approve-wait'; this.errorTx = hash;
                await waitReceipt(hash);
                this.errorTx = '';
                await this.loadUser();
                alive();
            }

            this.phase = 'swap';
            const deadline = BigInt(chainNow() + DEADLINE_S);
            let tx;
            if (side === 'buy' && c.native) tx = { ...(await router.swapExactETHForTokens.populateTransaction(min, path, account, deadline)), value: amountIn };
            else if (side === 'sell' && c.native) tx = await router.swapExactTokensForETH.populateTransaction(amountIn, min, path, account, deadline);
            else tx = await router.swapExactTokensForTokens.populateTransaction(amountIn, min, path, account, deadline);
            const hash = await this.send(signer, account, tx);
            alive();
            this.phase = 'swap-wait'; this.errorTx = hash;
            const receipt = await waitReceipt(hash);
            this.errorTx = '';

            // ยอดที่ได้จริง: Transfer ของเหรียญขาออกเข้ากระเป๋าผู้ใช้ (TPIX ของเชนไม่มี log → ใช้ยอดประมาณ)
            let got = null;
            if (!(side === 'sell' && c.native)) {
                const outToken = path[path.length - 1].toLowerCase();
                for (const log of receipt.logs) {
                    if (log.address.toLowerCase() !== outToken) continue;
                    try {
                        const ev = transferIface.parseLog(log);
                        if (ev?.name === 'Transfer' && ev.args.to.toLowerCase() === account.toLowerCase()) got = (got ?? 0n) + ev.args.value;
                    } catch { /* log อื่น */ }
                }
            }
            this.success = { hash, side, sym: c.symbol, paid: amountIn, got: got ?? quoted, exact: got !== null };
            this.input = '';
        } catch (e) {
            if (e?.code === 'CANCELLED') return;
            this.error = e?.code === 'SIM_REVERT' ? await this.diagnose(e.step, amountIn) : this.describe(e);
            if (id !== this.flowId) return;
            this.errorTx = e?.hash || (this.phase.endsWith('-wait') ? this.errorTx : '');
        } finally {
            if (id === this.flowId) { this.busy = false; this.phase = ''; this.flowAccount = null; }
            this.loadPool(); this.loadUser();
        }
    },

    async send(signer, account, tx) {
        await assertWallet(account);
        let gas;
        try {
            gas = await withTimeout(readProvider().estimateGas({ ...tx, from: account }), 15000);
        } catch (e) {
            if (e?.code === 'CALL_EXCEPTION' && !decodeRevert(e)) throw Object.assign(new Error('simulation reverted'), { code: 'SIM_REVERT', step: this.phase, cause: e });
            throw e;
        }
        return sendTx(signer, { ...tx, gasLimit: (gas * 12n) / 10n + 10000n });
    },

    /** สัญญาปฏิเสธตอนจำลอง — อ่านสถานะใหม่แล้วไล่ตามเงื่อนไขของ router/pair/ERC20 */
    async diagnose(step, amountIn) {
        const before = this.outUnits;
        await Promise.all([this.loadPool(), this.loadUser()]);
        await this.checkQuote();
        const inToken = this.side === 'buy' && this.counter.native ? null : this.path[0];
        for (const addr of [inToken, this.path[this.path.length - 1]]) {
            if (!addr) continue;
            try { if (await withTimeout(new Contract(addr, ERC20_ABI, readProvider()).paused(), 8000)) return t('trade.token_paused'); } catch { /* ไม่มี paused() */ }
        }
        if (step === 'swap') {
            if (!this.hasPool) return t('trade.no_pool');
            if (this.balIn !== null && amountIn > this.balIn) return t('trade.problem.balance', { bal: formatAmount(this.balIn, this.inDec, 2), sym: this.inSymbol });
            if (this.needsApprove) return t('trade.allowance_low');
            if (minOut(before, this.slipBps) > this.outUnits) return t('trade.price_moved', { slip: this.slipText });
            return t('trade.swap_rejected');
        }
        return t('trade.approve_rejected');
    },

    describe(e) {
        if (e?.code === 'PRECHECK') return e.message;
        if (e?.code === 'TIMEOUT' && e?.hash) return t('trade.tx_slow');
        const reason = e?.reason || e?.shortMessage || e?.info?.error?.message || e?.message || '';
        if (/INSUFFICIENT_OUTPUT_AMOUNT/.test(reason)) return t('trade.price_moved', { slip: this.slipText });
        if (/EXPIRED/.test(reason)) return t('trade.expired');
        if (/INSUFFICIENT_LIQUIDITY/.test(reason)) return t('trade.problem.liquidity');
        if (isRejected(e)) return t('trade.rejected');
        return walletError(e);
    },

    async addToken() {
        if (!this.tokenAddr) return;
        try { await watchToken(this.tokenAddr); this.notice = t('trade.token_added'); } catch (e) { this.error = walletError(e); }
    },

    again() { this.resetFlow(); },
}));

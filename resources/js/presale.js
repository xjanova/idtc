/**
 * หน้า /presale — ซื้อ IDTC ราคาคงที่ด้วย USDT (สัญญา FixedPriceSale)
 *
 * ตัวเลขทุกตัวคิดด้วย BigInt ตามสูตรในสัญญา: tokensOut = payAmount × rateNumerator ÷ rateDenominator (ปัดลง)
 * ก่อนสัญญาขึ้นเชน ใช้อัตราจาก config (แผน) · พอมีที่อยู่สัญญา อ่านค่าจริงจากสัญญาเองทั้งหมด
 * ลำดับการซื้อ: เชื่อมกระเป๋า → อนุญาต USDT เท่ายอดที่ซื้อ (ถ้ายังไม่พอ) → buy(ยอด) → รอ receipt
 * ข้อความทุกตัวมาจากพจนานุกรมตามภาษาของหน้า (./i18n → lang/{th,en}/js.php)
 */
import Alpine from 'alpinejs';
import { Contract, Interface } from 'ethers';
import {
    ABI, CFG, assertWallet, chainNow, connectWallet, contractAddress, decodeRevert, explorerAddress, explorerTx,
    formatAmount, injected, isMobile, isRejected, parseAmount, readProvider, sendTx, short, splitDuration,
    syncChainClock, unitsToInput, waitReceipt, walletAccount, walletError, watchToken, withTimeout,
} from './chain';
import { BIG_EXP, BIG_UNIT, localDate, t } from './i18n';

const PAY_DEC = Number(CFG.sale?.pay_decimals ?? 6);
const TOKEN_DEC = Number(CFG.token?.decimals ?? 18);
const SALE_ABI = [...ABI.sale, ...ABI.errors, 'function payToken() view returns (address)', 'function saleToken() view returns (address)'];
const ERC20_ABI = [...ABI.erc20, ...ABI.errors];
const saleIface = new Interface(SALE_ABI);

const big = (v, fallback = 0n) => { try { return BigInt(v); } catch { return fallback; } };
const PLAN = {
    num: big(CFG.sale?.rate_num, 2000000000000000000n),
    den: big(CFG.sale?.rate_den, 3n),
    cap: big(CFG.sale?.cap, 100000000000n) * 10n ** BigInt(TOKEN_DEC),
    min: parseAmount(String(CFG.sale?.min_purchase_usdt ?? '0'), PAY_DEC).units ?? 0n,
};

const halt = (code) => Object.assign(new Error(code), { code });

Alpine.data('presale', () => ({
    saleAddr: contractAddress('presale'),
    payAddr: contractAddress('usdt'),
    tokenAddr: contractAddress('token'),

    // สถานะสัญญา
    loaded: false,
    loading: false,
    loadError: '',
    num: PLAN.num,
    den: PLAN.den,
    cap: PLAN.cap,
    sold: 0n,
    raised: 0n,
    min: PLAN.min,
    maxPerWallet: 0n,
    start: 0,
    end: 0,
    paused: false,
    treasury: null,
    saleStock: null,
    rateMismatch: false,
    payMismatch: false,
    tokenMismatch: false,
    now: chainNow(),

    // ช่องกรอก + ราคาจากสัญญา
    input: '100',
    chainQuote: null,
    chainQuoteFor: null,

    // กระเป๋า
    account: null,
    walletChainOk: true,
    usdtBal: null,
    allowance: null,
    purchased: 0n,
    tokenBal: null,
    noWallet: false,

    // ขั้นตอนธุรกรรม
    busy: false,
    phase: '',          // '' | 'connect' | 'approve' | 'approve-wait' | 'buy' | 'buy-wait'
    flowId: 0,
    flowAccount: null,
    error: '',
    errorTx: '',
    notice: '',
    success: null,

    short,
    explorerAddress,
    explorerTx,

    get deployed() { return !!this.saleAddr; },

    init() {
        this.tick = this.tick.bind(this);
        this._tick = setInterval(this.tick, 1000);
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
        if (this.deployed) syncChainClock().then(() => this.tick());
        this.loadSale();
    },

    destroy() {
        clearInterval(this._tick); clearInterval(this._poll); clearTimeout(this._qt);
        window.removeEventListener('idtc:account', this._onAccount);
        const eth = injected();
        if (eth?.removeListener && this._onAccounts) { eth.removeListener('accountsChanged', this._onAccounts); eth.removeListener('chainChanged', this._onChain); }
    },

    tick() { this.now = chainNow(); },

    refresh() { this.loadSale(); this.loadUser(); },

    /* ───────── อ่านสัญญา ───────── */
    saleC() { return this._sale || (this._sale = new Contract(this.saleAddr, SALE_ABI, readProvider())); },
    payC() { return this.payAddr ? new Contract(this.payAddr, ERC20_ABI, readProvider()) : null; },

    async loadSale() {
        if (!this.deployed || this.loading) return;
        this.loading = true;
        const s = this.saleC();
        try {
            const [num, den, cap, sold, raised, min, maxW, start, end, paused, treasury, payToken, saleToken] = await withTimeout(Promise.all([
                s.rateNumerator(), s.rateDenominator(), s.saleCap(), s.sold(), s.raised(), s.minPurchase(), s.maxPerWallet(),
                s.startTime(), s.endTime(), s.paused(), s.treasury(), s.payToken(), s.saleToken(),
            ]), 15000);
            Object.assign(this, { num, den, cap, sold, raised, min, maxPerWallet: maxW, start: Number(start), end: Number(end), paused, treasury });
            // ค่าในสัญญาคือความจริง — ถ้าไม่ตรงแผน แจ้งให้เห็น แต่ใช้ค่าของสัญญา
            this.rateMismatch = num * PLAN.den !== PLAN.num * den;
            this.payMismatch = !!this.payAddr && payToken.toLowerCase() !== this.payAddr.toLowerCase();
            this.payAddr = payToken;
            // เหรียญที่สัญญาขายจริงคือ saleToken — ถ้าไม่ใช่ IDTC ที่ตั้งค่าไว้ ต้องเตือนผู้ซื้อ (ยอดคงเหลือ/เพิ่มเหรียญใช้ตัวที่สัญญาขาย)
            this.tokenMismatch = !!this.tokenAddr && saleToken.toLowerCase() !== this.tokenAddr.toLowerCase();
            this.tokenAddr = saleToken;
            this.saleStock = await withTimeout(new Contract(saleToken, ERC20_ABI, readProvider()).balanceOf(this.saleAddr), 10000).catch(() => null);
            this.loaded = true;
            this.loadError = '';
            this.checkQuote();
        } catch (e) {
            this.loadError = e?.code === 'BAD_DATA' || e?.code === 'CALL_EXCEPTION'
                ? t('presale.load_bad')
                : t('presale.load_net');
        } finally {
            this.loading = false;
        }
    },

    async loadUser() {
        const acct = this.account;
        if (!acct) return;
        const pay = this.payC();
        try {
            const [bal, allow, bought, tok] = await withTimeout(Promise.all([
                pay ? pay.balanceOf(acct) : null,
                pay && this.deployed ? pay.allowance(acct, this.saleAddr) : null,
                this.deployed ? this.saleC().purchased(acct) : 0n,
                this.tokenAddr ? new Contract(this.tokenAddr, ERC20_ABI, readProvider()).balanceOf(acct) : null,
            ]), 15000);
            if (acct !== this.account) return; // เปลี่ยนบัญชีระหว่างรอ — ทิ้งผลเก่า
            Object.assign(this, { usdtBal: bal, allowance: allow, purchased: bought ?? 0n, tokenBal: tok });
        } catch { /* RPC ช้า — คงค่าเดิม รอรอบถัดไป */ }
    },

    setAccount(a) {
        const next = a ? String(a) : null;
        if ((next || '').toLowerCase() === (this.account || '').toLowerCase()) return;
        if (this.busy && this.flowAccount && (next || '').toLowerCase() !== this.flowAccount) {
            // เปลี่ยนบัญชีกลางทาง: หยุดขั้นที่เหลือทันที (ธุรกรรมที่ส่งไปแล้วยังไปต่อบนเชนตามปกติ)
            this.flowId++;
            this.busy = false;
            this.phase = '';
            this.error = walletError({ code: 'ACCOUNT_CHANGED' });
        }
        this.account = next;
        this.usdtBal = null; this.allowance = null; this.purchased = 0n; this.tokenBal = null;
        if (!next) this.success = null;
        this.loadUser();
    },

    /* ───────── ช่องกรอกและราคา ───────── */
    get parsed() { return parseAmount(this.input, PAY_DEC); },
    get payUnits() { return this.parsed.units ?? 0n; },
    get localQuote() { return this.den > 0n ? (this.payUnits * this.num) / this.den : 0n; },
    get quoteUnits() {
        return this.chainQuote !== null && this.chainQuoteFor === this.payUnits ? this.chainQuote : this.localQuote;
    },
    get quoteMismatch() { return this.chainQuote !== null && this.chainQuoteFor === this.payUnits && this.chainQuote !== this.localQuote; },
    get quoteText() { return formatAmount(this.quoteUnits, TOKEN_DEC, 2); },
    /** อัตราต่อ 1 USDT ปัดทศนิยม 2 ตำแหน่ง (666,666.67) — เป็นอัตรา ไม่ใช่ยอดที่ได้ จึงปัดแบบปกติได้ */
    get rateText() {
        if (this.den <= 0n) return '—';
        const scale = this.den * 10n ** BigInt(TOKEN_DEC);
        const cents = (10n ** BigInt(PAY_DEC) * this.num * 100n + scale / 2n) / scale;
        return formatAmount(cents, 2, 2);
    },

    onInput() {
        this.success = null;
        if (this.error && !this.busy) { this.error = ''; this.errorTx = ''; }
        clearTimeout(this._qt);
        this._qt = setTimeout(() => this.checkQuote(), 350);
    },

    setQuick(v) {
        if (this.busy) return;
        this.input = typeof v === 'bigint' ? unitsToInput(v, PAY_DEC) : String(v);
        this.onInput();
    },

    /** ยืนยันตัวเลขกับ sale.quote() ของจริง — ถ้าไม่ตรงกับที่คำนวณ ให้ใช้ของสัญญาและแจ้งเตือน */
    async checkQuote() {
        if (!this.deployed || !this.loaded) return;
        const u = this.payUnits;
        if (u <= 0n) { this.chainQuote = null; return; }
        try {
            const q = await withTimeout(this.saleC().quote(u), 10000);
            if (u === this.payUnits) { this.chainQuote = q; this.chainQuoteFor = u; }
        } catch { /* ใช้ค่าที่คำนวณเอง (สูตรเดียวกับสัญญา) */ }
    },

    /* ───────── เพดานและการตรวจยอด ───────── */
    get remaining() { return this.cap > this.sold ? this.cap - this.sold : 0n; },
    get walletLeft() {
        if (this.maxPerWallet === 0n) return null;
        return this.maxPerWallet > this.purchased ? this.maxPerWallet - this.purchased : 0n;
    },
    /** จ่ายได้สูงสุดเท่าไรโดยได้เหรียญไม่เกิน t: P ≤ ((t+1)·den − 1) ÷ num */
    maxPayFor(t) { return this.num > 0n ? ((t + 1n) * this.den - 1n) / this.num : 0n; },
    get maxPay() {
        if (!this.account) return null; // ปุ่ม "สูงสุด" มีความหมายเมื่อรู้ยอดในกระเป๋าแล้วเท่านั้น
        const caps = [];
        if (this.deployed && this.loaded) caps.push(this.maxPayFor(this.remaining));
        if (this.walletLeft !== null && this.account) caps.push(this.maxPayFor(this.walletLeft));
        if (this.usdtBal !== null) caps.push(this.usdtBal);
        return caps.length ? caps.reduce((a, b) => (b < a ? b : a)) : null;
    },

    /** ข้อความเตือนใต้ช่องกรอก ('' = ยอดนี้ซื้อได้) */
    get problem() {
        const p = this.parsed;
        if (p.error) return p.error;
        if (p.empty) return '';
        const u = p.units;
        if (u === 0n) return t('presale.problem.zero');
        if (this.min > 0n && u < this.min) return t('presale.problem.min', { min: formatAmount(this.min, PAY_DEC, 2, { trim: true }) });
        if (this.localQuote === 0n) return t('presale.problem.too_small');
        if (this.deployed && this.loaded) {
            if (this.quoteUnits > this.remaining) return t('presale.problem.over_cap', { max: formatAmount(this.maxPayFor(this.remaining), PAY_DEC, 2, { trim: true }) });
            if (this.account && this.walletLeft !== null && this.quoteUnits > this.walletLeft) return t('presale.problem.over_wallet', { left: formatAmount(this.walletLeft, TOKEN_DEC, 2, { trim: true }) });
            if (this.saleStock !== null && this.quoteUnits > this.saleStock) return t('presale.problem.stock');
        }
        if (this.account && this.usdtBal !== null && u > this.usdtBal) return t('presale.problem.balance', { bal: formatAmount(this.usdtBal, PAY_DEC, 2) });
        return '';
    },

    get amountOk() { return !this.parsed.empty && !this.problem && this.payUnits > 0n; },
    get needsApprove() { return this.allowance === null || this.allowance < this.payUnits; },
    get canApprove() { return this.status === 'live' && this.amountOk && !this.busy && !!this.account && this.needsApprove; },
    get canBuy() { return this.status === 'live' && this.amountOk && !this.busy && !!this.account && !this.needsApprove; },

    /* ───────── สถานะการขาย ───────── */
    get status() {
        if (!this.deployed) return 'nodeploy';
        if (!this.loaded) return this.loadError ? 'error' : 'loading';
        if (this.sold >= this.cap) return 'soldout';
        if (this.paused) return 'paused';
        if (this.end && this.now >= this.end) return 'ended';
        if (this.now < this.start) return 'upcoming';
        return 'live';
    },
    get statusLabel() { return t(`presale.status.${this.status}`); },
    get statusNote() {
        switch (this.status) {
            case 'nodeploy': return t('presale.note.nodeploy');
            case 'upcoming': return t('presale.note.upcoming', { time: this.countdown(this.start) });
            case 'live': return this.end ? t('presale.note.live', { time: this.countdown(this.end) }) : '';
            case 'ended': return t('presale.note.ended', { date: localDate(this.end, true) });
            case 'paused': return t('presale.note.paused');
            default: return '';
        }
    },
    countdown(target) {
        const { d, h, m, s } = splitDuration(target - this.now);
        const hms = [h, m, s].map((x) => String(x).padStart(2, '0')).join(':');
        return d > 0 ? t('presale.days', { d: d.toLocaleString('en-US'), hms }) : hms;
    },

    /* ───────── แถบความคืบหน้า ───────── */
    get pct() { return this.cap > 0n ? Number((this.sold * 10000n) / this.cap) / 100 : 0; },
    get pctText() { return this.pct > 0 && this.pct < 0.01 ? '< 0.01' : this.pct.toFixed(2); },

    /* ───────── ตัวช่วยแสดงผล ───────── */
    usdt(v, d = 2) { return v === null || v === undefined ? '—' : formatAmount(v, PAY_DEC, d); },
    usdtShort(v) { return v === null || v === undefined ? '—' : formatAmount(v, PAY_DEC, PAY_DEC, { trim: true }); },
    idtc(v, d = 2) { return v === null || v === undefined ? '—' : formatAmount(v, TOKEN_DEC, d); },
    /** จำนวนเหรียญก้อนใหญ่ตามภาษา: "100,000 ล้าน" · "100 billion" */
    big(v) { return v === null || v === undefined ? '—' : `${formatAmount(v, TOKEN_DEC + BIG_EXP, 2, { trim: true })} ${BIG_UNIT}`; },
    get raisedText() { return t('presale.raised', { v: this.usdt(this.raised) }); },

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

    /** ปุ่ม "ยกเลิก" ระหว่างรอกระเป๋า — กระเป๋าบางตัวปิดหน้าต่างแล้วไม่ตอบกลับเลย */
    cancelFlow() {
        if (!this.busy || this.phase.endsWith('-wait')) return;
        this.flowId++;
        this.busy = false; this.phase = '';
        this.notice = t('presale.cancelled');
    },

    /** ขั้นเดียวจบ: อนุญาต USDT (ถ้ายอดอนุญาตยังไม่พอ) แล้วซื้อต่อทันที */
    async purchase() {
        if (this.busy || !this.account || !this.amountOk || this.status !== 'live') return;
        const id = ++this.flowId;
        const amount = this.payUnits;
        const account = this.account;
        const alive = () => { if (id !== this.flowId) throw halt('CANCELLED'); };
        this.busy = true; this.error = ''; this.errorTx = ''; this.notice = ''; this.success = null;
        this.flowAccount = account.toLowerCase();
        try {
            this.phase = 'prepare';
            const { signer, account: walletAcct } = await connectWallet();
            alive();
            if (walletAcct.toLowerCase() !== this.flowAccount) throw halt('ACCOUNT_CHANGED');

            // อ่านค่าสดก่อนเริ่ม กันซื้อด้วยข้อมูลเก่า
            await Promise.all([this.loadSale(), this.loadUser()]);
            alive();
            if (this.status !== 'live') throw Object.assign(new Error(t('presale.not_live', { status: this.statusLabel })), { code: 'NOT_LIVE' });
            if (this.payUnits !== amount) throw halt('CANCELLED');
            if (this.problem) throw Object.assign(new Error(this.problem), { code: 'PRECHECK' });

            if (this.allowance === null || this.allowance < amount) {
                this.phase = 'approve';
                const tx = await this.payC().approve.populateTransaction(this.saleAddr, amount);
                const hash = await this.send(signer, account, tx);
                alive();
                this.phase = 'approve-wait'; this.errorTx = hash;
                await waitReceipt(hash);
                this.errorTx = '';
                await this.loadUser();
                alive();
            }

            this.phase = 'buy';
            const tx = await this.saleC().buy.populateTransaction(amount);
            const hash = await this.send(signer, account, tx);
            alive();
            this.phase = 'buy-wait'; this.errorTx = hash;
            const receipt = await waitReceipt(hash);
            this.errorTx = '';
            let out = null;
            for (const log of receipt.logs) {
                if (log.address.toLowerCase() !== this.saleAddr.toLowerCase()) continue;
                try { const p = saleIface.parseLog(log); if (p?.name === 'Purchased') out = p.args.tokensOut; } catch { /* log อื่น */ }
            }
            this.success = { hash, pay: amount, out: out ?? this.quoteUnits };
        } catch (e) {
            if (e?.code === 'CANCELLED') return;
            this.error = e?.code === 'SIM_REVERT' ? await this.diagnose(e.step, amount) : this.describe(e);
            if (id !== this.flowId) return;
            // ส่งธุรกรรมไปแล้วแต่รอผลไม่สำเร็จ → เก็บลิงก์ไว้ให้ตามดูบน explorer
            this.errorTx = e?.hash || (this.phase.endsWith('-wait') ? this.errorTx : '');
        } finally {
            if (id === this.flowId) { this.busy = false; this.phase = ''; this.flowAccount = null; }
            this.loadSale(); this.loadUser();
        }
    },

    /** จำลองบนเชนก่อน (รู้ก่อนว่าสัญญาจะปฏิเสธ โดยไม่ต้องเปิดกระเป๋า) แล้วค่อยส่งให้กระเป๋าเซ็น */
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

    /**
     * RPC ของเชน TPIX ไม่บอกเหตุผลที่สัญญาปฏิเสธ (ไม่มี revert data) — อ่านสถานะใหม่แล้วหาสาเหตุเอง
     * ตามเงื่อนไขเดียวกับที่ FixedPriceSale.buy() ตรวจ
     */
    async diagnose(step, amount) {
        await Promise.all([this.loadSale(), this.loadUser()]);
        if (this.payAddr) {
            try {
                const paused = await withTimeout(new Contract(this.payAddr, ['function paused() view returns (bool)'], readProvider()).paused(), 8000);
                if (paused) return t('presale.usdt_paused');
            } catch { /* USDT บางตัวไม่มี paused() */ }
        }
        if (step === 'buy') {
            if (this.status !== 'live') return t('presale.not_live', { status: this.statusLabel });
            if (this.problem) return this.problem;
            if (this.allowance !== null && this.allowance < amount) return t('presale.allowance_low');
            return t('presale.sale_rejected');
        }
        return t('presale.approve_rejected');
    },

    describe(e) {
        if (['NOT_LIVE', 'PRECHECK'].includes(e?.code)) return e.message;
        if (e?.code === 'TIMEOUT' && e?.hash) return t('presale.tx_slow');
        const d = decodeRevert(e);
        if (d?.name === 'ERC20InsufficientBalance') {
            return String(d.args?.[0] || '').toLowerCase() === this.saleAddr.toLowerCase()
                ? t('presale.sale_stock_low')
                : t('presale.usdt_low');
        }
        if (d?.name === 'EnforcedPause') return this.paused ? t('presale.paused') : t('presale.usdt_paused');
        if (isRejected(e)) return t('presale.rejected');
        return walletError(e);
    },

    async addToken() {
        if (!this.tokenAddr) return;
        try {
            await watchToken(this.tokenAddr);
            this.notice = t('presale.token_added');
        } catch (e) { this.error = walletError(e); }
    },

    async addUsdt() {
        if (!this.payAddr) return;
        try {
            await watchToken(this.payAddr, { symbol: 'USDT', decimals: PAY_DEC, image: undefined });
            this.notice = t('presale.usdt_added');
        } catch (e) { this.error = walletError(e); }
    },

    again() {
        this.success = null; this.error = ''; this.errorTx = ''; this.notice = '';
    },

    get phaseText() { return this.phase ? t(`presale.phase.${this.phase}`) : ''; },

    get mobileNoWallet() { return this.noWallet && isMobile(); },
}));

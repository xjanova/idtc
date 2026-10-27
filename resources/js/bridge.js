/**
 * หน้า /bridge — เติม USDT จาก BNB Chain เข้าเชน TPIX และถอนกลับ ผ่านสะพานของ tpix.online
 *
 * เติม (BSC → TPIX): ผู้ใช้เรียก USDT.transfer(vault, ยอด) บน BSC จากกระเป๋าของตัวเองตรง ๆ
 *   ตัวสแกนของสะพาน mint USDT_TPIX ให้ "ที่อยู่ผู้ส่ง" บนเชน TPIX หลังบล็อก final (~15 confirmations)
 *   ต้องเป็นกระเป๋าบุคคล (EOA) ส่งเอง — สัญญา/multisig/ถอนจากกระดานเทรด จะค้างรอทีมงานตรวจ
 * ถอน (TPIX → BSC): USDT_TPIX.bridgeBurn(ยอด, 56, ที่อยู่ปลายทาง) แล้วสะพานจ่าย USDT จริงบน BSC หักค่าธรรมเนียม
 *
 * ที่อยู่ vault มาจาก /api/bridge/info ซึ่งเซิร์ฟเวอร์ตรวจเทียบกับค่าที่ตรึงไว้แล้วเท่านั้น
 * ยอดทุกตัวเป็น BigInt · รับทศนิยมได้ 6 ตำแหน่งตาม USDT_TPIX (เศษละเอียดกว่านี้จะค้างใน vault)
 */
import Alpine from 'alpinejs';
import { BrowserProvider, Contract, Interface, JsonRpcProvider, getAddress, isAddress } from 'ethers';
import {
    CFG, assertWallet, connectWallet, contractAddress, explorerTx, formatAmount, injected, isMobile, isRejected,
    parseAmount, readProvider, sendTx, short, unitsToInput, waitReceipt, walletAccount, walletError, watchToken, withTimeout,
} from './chain';
import { t } from './i18n';

const B = CFG.bridge || {};
const BSC = B.chain || { id: 56, hex: '0x38' };
const AMT_DEC = Number(B.amount_decimals ?? 6);
const BSC_USDT_DEC = Number(B.usdt_decimals ?? 18);
const SCALE = 10n ** BigInt(BSC_USDT_DEC - AMT_DEC);
const TPIX_USDT_DEC = 6;

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function transfer(address to, uint256 amount) returns (bool)',
    'function paused() view returns (bool)',
];
const USDT_TPIX_ABI = [...ERC20_ABI, 'function bridgeBurn(uint256 amount, uint256 targetChainId, string targetAddress)'];
const erc20Iface = new Interface(ERC20_ABI);

/** สถานะที่จบแล้ว ไม่ต้องถามซ้ำ */
const DONE = new Set(['minted', 'paid', 'rejected', 'review', 'failed', 'not_a_deposit', 'not_a_withdrawal', 'ignored', 'below_fee', 'chain_disabled', 'unsupported_chain', 'invalid_hash']);
const TRACK_KEY = 'idtc:bridge:tracked';
const HASH = /^0x[0-9a-fA-F]{64}$/;

const halt = (code) => Object.assign(new Error(code), { code });
const toUnits = (s) => (s === null || s === undefined ? null : parseAmount(String(s), AMT_DEC).units);

let _bsc;
function bscRead() {
    if (!_bsc) _bsc = new JsonRpcProvider(BSC.rpc, BSC.id, { staticNetwork: true });
    return _bsc;
}

/** สลับกระเป๋าไป BNB Chain (เพิ่มเชนให้ถ้ายังไม่มี) */
async function ensureBsc(eth) {
    try {
        await eth.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: BSC.hex }] });
    } catch (e) {
        const unknown = e?.code === 4902 || e?.data?.originalError?.code === 4902 || /unrecognized|not added|unknown chain/i.test(e?.message || '');
        if (!unknown) throw e;
        await eth.request({
            method: 'wallet_addEthereumChain',
            params: [{ chainId: BSC.hex, chainName: BSC.name, nativeCurrency: BSC.native, rpcUrls: [BSC.rpc], blockExplorerUrls: [BSC.explorer] }],
        });
    }
    const cid = await eth.request({ method: 'eth_chainId' });
    if (Number.parseInt(cid, 16) !== Number(BSC.id)) throw Object.assign(new Error('wrong chain'), { code: 'WRONG_CHAIN_BSC' });
}

function loadTracked() {
    try {
        const list = JSON.parse(localStorage.getItem(TRACK_KEY) || '[]');
        return Array.isArray(list) ? list.filter((x) => x && HASH.test(x.hash) && ['deposit', 'withdraw'].includes(x.kind)).slice(0, 10) : [];
    } catch { return []; }
}

function saveTracked(list) {
    try { localStorage.setItem(TRACK_KEY, JSON.stringify(list.slice(0, 10))); } catch { /* storage ปิดอยู่ — ติดตามได้เฉพาะหน้านี้ */ }
}

Alpine.data('bridge', () => ({
    tab: 'deposit',
    usdtTpix: contractAddress('usdt'),

    info: null,
    infoError: '',
    infoLoading: false,

    account: null,
    noWallet: false,
    chainId: null,

    // เติม
    depIn: '',
    bscUsdt: null,
    bscGas: null,
    senderOk: null,     // null = ยังไม่ตรวจ · false = เป็นสัญญา (จะค้างรอตรวจ)

    // ถอน
    wdIn: '',
    wdTarget: '',
    tpixUsdt: null,

    busy: false,
    phase: '',
    flowId: 0,
    error: '',
    errorTx: '',
    notice: '',

    tracked: [],
    lookup: '',

    short,
    explorerTx,

    init() {
        const q = new URLSearchParams(location.search);
        if (q.get('tab') === 'withdraw') this.tab = 'withdraw';
        this.tracked = loadTracked();
        this.loadInfo();
        this._infoPoll = setInterval(() => { if (!document.hidden) this.loadInfo(); }, 60000);
        this._trackPoll = setInterval(() => { if (!document.hidden) this.pollTracked(); }, 8000);
        this.pollTracked();
        this._onAccount = (e) => this.setAccount(e.detail || null);
        window.addEventListener('idtc:account', this._onAccount);
        const eth = injected();
        if (eth?.on) {
            this._onAccounts = (a) => this.setAccount(a?.[0] || null);
            this._onChain = (cid) => { this.chainId = Number.parseInt(cid, 16); };
            eth.on('accountsChanged', this._onAccounts);
            eth.on('chainChanged', this._onChain);
            eth.request({ method: 'eth_chainId' }).then(this._onChain).catch(() => {});
        }
        walletAccount().then((a) => a && this.setAccount(a));
    },

    destroy() {
        clearInterval(this._infoPoll); clearInterval(this._trackPoll);
        window.removeEventListener('idtc:account', this._onAccount);
        const eth = injected();
        if (eth?.removeListener && this._onAccounts) { eth.removeListener('accountsChanged', this._onAccounts); eth.removeListener('chainChanged', this._onChain); }
    },

    setTab(tab) {
        if (this.busy || tab === this.tab) return;
        this.tab = tab;
        this.error = ''; this.errorTx = ''; this.notice = '';
        this.loadBalances();
    },

    /* ───────── ข้อมูลสะพาน ───────── */
    async loadInfo() {
        if (this.infoLoading) return;
        this.infoLoading = true;
        try {
            const res = await withTimeout(fetch(`${B.api}/info`, { headers: { Accept: 'application/json' } }), 12000);
            if (!res.ok) throw new Error(String(res.status));
            this.info = await res.json();
            this.infoError = '';
        } catch {
            this.infoError = t('bridge.info_net');
        } finally {
            this.infoLoading = false;
        }
    },

    get open() { return !!this.info?.open && !!this.info?.vault; },
    get reasonText() {
        const r = this.info?.reason;
        if (!r) return '';
        return t(`bridge.reason.${['vault_mismatch', 'usdt_mismatch', 'usdt_tpix_mismatch', 'chain_missing'].includes(r) ? 'mismatch' : r}`);
    },
    lim(group, key) { return toUnits(this.info?.[group]?.[key]); },
    usd(v, d = 2) { return v === null || v === undefined ? '—' : formatAmount(v, AMT_DEC, d); },
    usdTrim(v) { return v === null || v === undefined ? '—' : formatAmount(v, AMT_DEC, AMT_DEC, { trim: true }); },

    /* ───────── กระเป๋า ───────── */
    setAccount(a) {
        const next = a ? getAddress(String(a)) : null;
        if ((next || '').toLowerCase() === (this.account || '').toLowerCase()) return;
        if (this.busy) {
            this.flowId++;
            this.busy = false; this.phase = '';
            this.error = walletError({ code: 'ACCOUNT_CHANGED' });
        }
        this.account = next;
        this.bscUsdt = null; this.bscGas = null; this.tpixUsdt = null; this.senderOk = null;
        if (next && (!this.wdTarget || !isAddress(this.wdTarget))) this.wdTarget = next;
        this.loadBalances();
    },

    async connect() {
        if (this.busy) return;
        this.error = ''; this.noWallet = false;
        const eth = injected();
        if (!eth) { this.noWallet = true; this.error = walletError({ code: 'NO_WALLET' }); return; }
        this.busy = true; this.phase = 'connect';
        try {
            const [a] = await eth.request({ method: 'eth_requestAccounts' });
            this.setAccount(a);
            window.dispatchEvent(new CustomEvent('idtc:account', { detail: getAddress(a) }));
        } catch (e) {
            this.error = walletError(e);
        } finally {
            this.busy = false; this.phase = '';
        }
    },

    async loadBalances() {
        const acct = this.account;
        if (!acct) return;
        try {
            if (this.tab === 'deposit') {
                const usdt = new Contract(B.usdt, ERC20_ABI, bscRead());
                const [bal, gas, code] = await withTimeout(Promise.all([usdt.balanceOf(acct), bscRead().getBalance(acct), bscRead().getCode(acct)]), 15000);
                if (acct !== this.account) return;
                // EOA ปกติ = "0x" · EIP-7702 (กระเป๋า MetaMask แบบ smart account) = 0xef0100… สะพานรับทั้งคู่
                this.senderOk = code === '0x' || /^0xef0100/i.test(code);
                Object.assign(this, { bscUsdt: bal, bscGas: gas });
            } else if (this.usdtTpix) {
                const bal = await withTimeout(new Contract(this.usdtTpix, ERC20_ABI, readProvider()).balanceOf(acct), 15000);
                if (acct === this.account) this.tpixUsdt = bal;
            }
        } catch { /* RPC ช้า — คงค่าเดิม */ }
    },

    /* ───────── เติม: ตรวจยอด ───────── */
    get dep() { return parseAmount(this.depIn, AMT_DEC); },
    get depUnits() { return this.dep.units ?? 0n; },
    /** ยอดสูงสุดที่ส่งได้ตอนนี้ = min(ต่อครั้ง, โควตา mint ที่เหลือวันนี้, ยอดในกระเป๋า) */
    get depMax() {
        if (!this.account || this.bscUsdt === null) return null; // ปุ่ม "สูงสุด" มีความหมายเมื่อรู้ยอดในกระเป๋าแล้วเท่านั้น
        const caps = [this.lim('deposit', 'max_per_tx'), this.lim('deposit', 'remaining_today')].filter((x) => x !== null);
        if (this.bscUsdt !== null) caps.push(this.bscUsdt / SCALE);
        return caps.length ? caps.reduce((a, b) => (b < a ? b : a)) : null;
    },
    get depProblem() {
        const p = this.dep;
        if (p.error) return p.error;
        if (p.empty) return '';
        const u = p.units;
        const min = this.lim('deposit', 'min');
        const max = this.lim('deposit', 'max_per_tx');
        const left = this.lim('deposit', 'remaining_today');
        if (u === 0n) return t('bridge.problem.zero');
        if (min !== null && u < min) return t('bridge.problem.dep_min', { min: this.usdTrim(min) });
        if (max !== null && u > max) return t('bridge.problem.dep_max', { max: this.usdTrim(max) });
        if (left !== null && u > left) return t('bridge.problem.dep_today', { left: this.usdTrim(left) });
        if (this.account && this.bscUsdt !== null && u * SCALE > this.bscUsdt) return t('bridge.problem.bsc_balance', { bal: formatAmount(this.bscUsdt, BSC_USDT_DEC, 2) });
        return '';
    },
    get depOk() { return !this.dep.empty && !this.depProblem && this.depUnits > 0n; },
    get noGas() { return this.bscGas !== null && this.bscGas === 0n; },
    get canDeposit() { return this.open && this.depOk && !!this.account && !this.busy && this.senderOk !== false && !this.noGas; },
    get depReceive() { const fee = this.lim('deposit', 'fee') ?? 0n; return this.depUnits > fee ? this.depUnits - fee : 0n; },

    setDepMax() {
        if (this.busy || this.depMax === null) return;
        this.depIn = unitsToInput(this.depMax, AMT_DEC);
        this.error = '';
    },

    async deposit() {
        if (!this.canDeposit) return;
        const id = ++this.flowId;
        const alive = () => { if (id !== this.flowId) throw halt('CANCELLED'); };
        const account = this.account;
        const amount = this.depUnits;
        this.busy = true; this.error = ''; this.errorTx = ''; this.notice = '';
        try {
            this.phase = 'dep-prepare';
            const eth = injected();
            if (!eth) throw halt('NO_WALLET');
            await ensureBsc(eth);
            alive();
            const [cur] = await eth.request({ method: 'eth_accounts' });
            if (!cur || cur.toLowerCase() !== account.toLowerCase()) throw halt('ACCOUNT_CHANGED');

            // อ่านค่าสดทั้งสะพานและกระเป๋าก่อนส่งเงินจริง
            await Promise.all([this.loadInfo(), this.loadBalances()]);
            alive();
            if (!this.open) throw Object.assign(new Error(this.reasonText || t('bridge.reason.closed')), { code: 'PRECHECK' });
            if (this.depUnits !== amount) throw halt('CANCELLED');
            if (this.depProblem) throw Object.assign(new Error(this.depProblem), { code: 'PRECHECK' });
            if (this.senderOk === false) throw Object.assign(new Error(t('bridge.sender_contract')), { code: 'PRECHECK' });
            if (this.noGas) throw Object.assign(new Error(t('bridge.no_bnb')), { code: 'PRECHECK' });

            const vault = getAddress(this.info.vault);
            const data = erc20Iface.encodeFunctionData('transfer', [vault, amount * SCALE]);
            const tx = { to: getAddress(B.usdt), data, from: account };
            await withTimeout(bscRead().estimateGas(tx), 15000).catch((e) => { throw Object.assign(new Error('sim'), { code: 'SIM_REVERT_BSC', cause: e }); });
            alive();

            this.phase = 'dep-sign';
            const signer = await new BrowserProvider(eth, 'any').getSigner();
            // แก๊สบน BSC ให้กระเป๋าตั้งเอง (ไม่ใช่ 0 แบบเชน TPIX)
            const sent = await signer.sendTransaction({ to: tx.to, data });
            alive();
            this.track('deposit', sent.hash, amount);
            this.phase = 'dep-wait'; this.errorTx = sent.hash;
            const r = await withTimeout(bscRead().waitForTransaction(sent.hash, 1, 180000), 185000);
            if (!r || r.status !== 1) throw Object.assign(new Error('reverted'), { code: r ? 'TX_REVERTED' : 'TIMEOUT', hash: sent.hash });
            this.errorTx = '';
            this.depIn = '';
            this.notice = t('bridge.dep_sent', { min: this.info?.eta_minutes ?? 2 });
            this.pollTracked();
        } catch (e) {
            if (e?.code === 'CANCELLED') return;
            this.error = this.describe(e);
            if (id === this.flowId) this.errorTx = e?.hash || (this.phase.endsWith('-wait') ? this.errorTx : '');
        } finally {
            if (id === this.flowId) { this.busy = false; this.phase = ''; }
            this.loadBalances();
        }
    },

    /* ───────── ถอน: ตรวจยอด ───────── */
    get wd() { return parseAmount(this.wdIn, TPIX_USDT_DEC); },
    get wdUnits() { return this.wd.units ?? 0n; },
    get wdFee() { return this.lim('withdraw', 'fee'); },
    get wdReceive() { const f = this.wdFee ?? 0n; return this.wdUnits > f ? this.wdUnits - f : 0n; },
    get targetOk() { return isAddress(this.wdTarget || ''); },
    get targetProblem() {
        const a = (this.wdTarget || '').trim();
        if (a === '') return t('bridge.problem.target_empty');
        if (!isAddress(a)) return t('bridge.problem.target_bad');
        const lower = a.toLowerCase();
        const bad = [this.info?.vault, B.usdt, this.usdtTpix, '0x0000000000000000000000000000000000000000'].filter(Boolean).map((x) => x.toLowerCase());
        if (bad.includes(lower)) return t('bridge.problem.target_internal');
        return '';
    },
    get targetIsSelf() { return this.account && this.targetOk && this.wdTarget.toLowerCase() === this.account.toLowerCase(); },
    get wdProblem() {
        const p = this.wd;
        if (p.error) return p.error;
        if (p.empty) return '';
        const u = p.units;
        const min = this.lim('withdraw', 'min');
        const max = this.lim('withdraw', 'max_per_tx');
        const fee = this.wdFee ?? 0n;
        if (u === 0n) return t('bridge.problem.zero');
        if (min !== null && u < min) return t('bridge.problem.wd_min', { min: this.usdTrim(min) });
        if (u <= fee) return t('bridge.problem.wd_fee', { fee: this.usdTrim(fee) });
        if (max !== null && u > max) return t('bridge.problem.wd_max', { max: this.usdTrim(max) });
        if (this.account && this.tpixUsdt !== null && u > this.tpixUsdt) return t('bridge.problem.tpix_balance', { bal: this.usd(this.tpixUsdt) });
        return '';
    },
    get canWithdraw() { return this.open && !!this.usdtTpix && !this.wd.empty && !this.wdProblem && !this.targetProblem && !!this.account && !this.busy; },

    setWdMax() {
        if (this.busy || this.tpixUsdt === null) return;
        const max = this.lim('withdraw', 'max_per_tx');
        this.wdIn = unitsToInput(max !== null && max < this.tpixUsdt ? max : this.tpixUsdt, TPIX_USDT_DEC);
        this.error = '';
    },

    async withdraw() {
        if (!this.canWithdraw) return;
        const id = ++this.flowId;
        const alive = () => { if (id !== this.flowId) throw halt('CANCELLED'); };
        const account = this.account;
        const amount = this.wdUnits;
        const target = getAddress(this.wdTarget.trim());
        this.busy = true; this.error = ''; this.errorTx = ''; this.notice = '';
        try {
            this.phase = 'wd-prepare';
            const { signer, account: walletAcct } = await connectWallet();
            alive();
            if (walletAcct.toLowerCase() !== account.toLowerCase()) throw halt('ACCOUNT_CHANGED');
            await Promise.all([this.loadInfo(), this.loadBalances()]);
            alive();
            if (!this.open) throw Object.assign(new Error(this.reasonText || t('bridge.reason.closed')), { code: 'PRECHECK' });
            if (this.wdUnits !== amount) throw halt('CANCELLED');
            if (this.wdProblem || this.targetProblem) throw Object.assign(new Error(this.wdProblem || this.targetProblem), { code: 'PRECHECK' });

            const token = new Contract(this.usdtTpix, USDT_TPIX_ABI, readProvider());
            if (await withTimeout(token.paused(), 8000).catch(() => false)) throw Object.assign(new Error(t('bridge.usdt_paused')), { code: 'PRECHECK' });
            const tx = await token.bridgeBurn.populateTransaction(amount, BigInt(BSC.id), target);

            await assertWallet(account);
            let gas;
            try { gas = await withTimeout(readProvider().estimateGas({ ...tx, from: account }), 15000); } catch (e) { throw Object.assign(new Error('sim'), { code: 'SIM_REVERT_TPIX', cause: e }); }
            alive();
            this.phase = 'wd-sign';
            const hash = await sendTx(signer, { ...tx, gasLimit: (gas * 12n) / 10n + 10000n });
            alive();
            this.track('withdraw', hash, amount);
            this.phase = 'wd-wait'; this.errorTx = hash;
            await waitReceipt(hash);
            this.errorTx = '';
            this.wdIn = '';
            this.notice = t('bridge.wd_sent', { min: this.info?.eta_minutes ?? 2 });
            this.pollTracked();
        } catch (e) {
            if (e?.code === 'CANCELLED') return;
            this.error = this.describe(e);
            if (id === this.flowId) this.errorTx = e?.hash || (this.phase.endsWith('-wait') ? this.errorTx : '');
        } finally {
            if (id === this.flowId) { this.busy = false; this.phase = ''; }
            this.loadBalances();
        }
    },

    cancelFlow() {
        if (!this.busy || this.phase.endsWith('-wait')) return;
        this.flowId++;
        this.busy = false; this.phase = '';
        this.notice = t('bridge.cancelled');
    },

    describe(e) {
        if (e?.code === 'PRECHECK') return e.message;
        if (e?.code === 'WRONG_CHAIN_BSC') return t('bridge.wrong_chain_bsc');
        if (e?.code === 'SIM_REVERT_BSC') return t('bridge.sim_bsc');
        if (e?.code === 'SIM_REVERT_TPIX') return t('bridge.sim_tpix');
        if (e?.code === 'TIMEOUT' && e?.hash) return t('bridge.tx_slow');
        if (isRejected(e)) return t('bridge.rejected');
        if (e?.code === 'INSUFFICIENT_FUNDS' || /insufficient funds/i.test(e?.shortMessage || e?.message || '')) return this.phase.startsWith('dep') ? t('bridge.no_bnb') : walletError(e);
        return walletError(e);
    },

    /* ───────── ติดตามรายการ ───────── */
    track(kind, hash, units) {
        const item = { kind, hash: hash.toLowerCase(), amount: unitsToInput(units, AMT_DEC), state: kind === 'deposit' ? 'pending' : 'confirming', at: Date.now(), data: null };
        this.tracked = [item, ...this.tracked.filter((x) => x.hash !== item.hash)].slice(0, 10);
        saveTracked(this.tracked);
    },

    forget(hash) {
        this.tracked = this.tracked.filter((x) => x.hash !== hash);
        saveTracked(this.tracked);
    },

    async pollTracked() {
        // ถามไม่เกิน 3 รายการต่อรอบ — สะพานจำกัดจำนวนคำขอ (รายการที่เหลือได้คิวรอบถัดไป)
        const live = this.tracked.filter((x) => !DONE.has(x.state)).sort((a, b) => (a.checked || 0) - (b.checked || 0)).slice(0, 3);
        await Promise.all(live.map((x) => this.check(x)));
    },

    async check(item) {
        try {
            const path = item.kind === 'deposit' ? 'deposit' : 'withdrawal';
            const res = await withTimeout(fetch(`${B.api}/${path}/${item.hash}`, { headers: { Accept: 'application/json' } }), 12000);
            const body = await res.json();
            const next = { ...item, state: String(body.state || 'unknown'), data: body, checked: Date.now() };
            this.tracked = this.tracked.map((x) => (x.hash === item.hash ? next : x));
            saveTracked(this.tracked);
        } catch { /* ลองรอบถัดไป */ }
    },

    lookupProblem() {
        const h = this.lookup.trim();
        return h === '' || HASH.test(h) ? '' : t('bridge.lookup_bad');
    },

    async doLookup(kind) {
        const h = this.lookup.trim().toLowerCase();
        if (!HASH.test(h)) return;
        const item = { kind, hash: h, amount: '', state: 'unknown', at: Date.now(), data: null };
        this.tracked = [item, ...this.tracked.filter((x) => x.hash !== h)].slice(0, 10);
        this.lookup = '';
        await this.check(item);
    },

    stateText(item) { return t(`bridge.state.${item.state}`) || item.state; },
    stateTone(item) {
        if (['minted', 'paid'].includes(item.state)) return 'ok';
        if (['review', 'rejected', 'failed', 'not_a_deposit', 'not_a_withdrawal', 'ignored', 'below_fee', 'chain_disabled', 'unsupported_chain', 'awaiting_liquidity'].includes(item.state)) return 'bad';
        return 'wait';
    },
    itemLinks(item) {
        const d = item.data || {};
        const links = [];
        if (item.kind === 'deposit') {
            links.push({ label: t('bridge.link.bsc_tx'), href: `${BSC.explorer}/tx/${item.hash}` });
            if (d.mint_tx) links.push({ label: t('bridge.link.tpix_tx'), href: explorerTx(d.mint_tx) });
        } else {
            links.push({ label: t('bridge.link.tpix_tx'), href: explorerTx(item.hash) });
            if (d.payout_tx) links.push({ label: t('bridge.link.bsc_tx'), href: `${BSC.explorer}/tx/${d.payout_tx}` });
        }
        return links;
    },
    itemAmount(item) {
        const d = item.data || {};
        const v = item.kind === 'deposit' ? (d.mint_amount || d.amount || item.amount) : (d.payout_amount || item.amount);
        return v ? `${v} USDT` : '';
    },

    async addUsdtTpix() {
        if (!this.usdtTpix) return;
        try {
            await watchToken(this.usdtTpix, { symbol: 'USDT', decimals: TPIX_USDT_DEC, image: undefined });
            this.notice = t('bridge.usdt_added');
        } catch (e) { this.error = walletError(e); }
    },

    get phaseText() { return this.phase ? t(`bridge.phase.${this.phase}`) : ''; },
    get mobileNoWallet() { return this.noWallet && isMobile(); },
    get onBsc() { return this.chainId === Number(BSC.id); },
    bscAmt(v) { return v === null || v === undefined ? '—' : formatAmount(v, BSC_USDT_DEC, 2); },
    bnb(v) { return v === null || v === undefined ? '—' : formatAmount(v, 18, 5); },
}));

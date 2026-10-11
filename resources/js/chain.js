/**
 * งานบนเชนฝั่งเบราว์เซอร์ของ idtc.online
 *
 * - อ่านข้อมูล: JsonRpcProvider ตรงไปที่ rpc1.tpix.online (เปิด CORS ให้ idtc.online แล้ว)
 * - เขียนข้อมูล: ผ่านกระเป๋าของผู้ใช้ (window.ethereum) — เว็บไม่เคยถือคีย์ใคร
 * - ค่าเชน/ที่อยู่สัญญามาจาก window.IDTC (App\Support\Idtc::publicConfig)
 */
import { BrowserProvider, Contract, Interface, JsonRpcProvider, formatUnits, getAddress } from 'ethers';

export const CFG = window.IDTC || { chain: {}, token: {}, contracts: {}, sale: {} };

export const ABI = {
    erc20: [
        'function balanceOf(address) view returns (uint256)',
        'function allowance(address owner, address spender) view returns (uint256)',
        'function approve(address spender, uint256 amount) returns (bool)',
        'function decimals() view returns (uint8)',
        'function symbol() view returns (string)',
        'function totalSupply() view returns (uint256)',
    ],
    sale: [
        'function quote(uint256 payAmount) view returns (uint256)',
        'function isActive() view returns (bool)',
        'function remaining() view returns (uint256)',
        'function sold() view returns (uint256)',
        'function raised() view returns (uint256)',
        'function saleCap() view returns (uint256)',
        'function startTime() view returns (uint64)',
        'function endTime() view returns (uint64)',
        'function minPurchase() view returns (uint256)',
        'function maxPerWallet() view returns (uint256)',
        'function purchased(address) view returns (uint256)',
        'function paused() view returns (bool)',
        'function treasury() view returns (address)',
        'function owner() view returns (address)',
        'function rateNumerator() view returns (uint256)',
        'function rateDenominator() view returns (uint256)',
        'function buy(uint256 payAmount) returns (uint256 tokensOut)',
        'event Purchased(address indexed buyer, uint256 payAmount, uint256 tokensOut)',
    ],
    vesting: [
        'function token() view returns (address)',
        'function beneficiary() view returns (address)',
        'function start() view returns (uint64)',
        'function period() view returns (uint64)',
        'function totalAllocation() view returns (uint256)',
        'function released() view returns (uint256)',
        'function releasable() view returns (uint256)',
        'function lockedAmount() view returns (uint256)',
        'function isFunded() view returns (bool)',
        'function nextUnlock() view returns (uint64 at, uint256 amount)',
        'function trancheCount() view returns (uint256)',
        'function tranche(uint256) view returns (tuple(uint256 amount, uint32 cliffPeriods, uint32 releasePeriods))',
        'function vestedAmount(uint64 timestamp) view returns (uint256)',
        'function release()',
    ],
};

let _read;
/** provider อ่านอย่างเดียว ใช้ร่วมกันทั้งหน้า */
export function readProvider() {
    if (!_read) _read = new JsonRpcProvider(CFG.chain.rpc, CFG.chain.id, { staticNetwork: true, batchMaxCount: 10 });
    return _read;
}

export function contractAddress(key) {
    const a = CFG.contracts?.[key];
    return a && /^0x[0-9a-fA-F]{40}$/.test(a) ? getAddress(a) : null;
}

/** สัญญาแบบอ่านอย่างเดียว — คืน null ถ้ายังไม่ deploy */
export function reader(key, abiName) {
    const addr = contractAddress(key);
    return addr ? new Contract(addr, ABI[abiName], readProvider()) : null;
}

export function injected() {
    return typeof window !== 'undefined' && window.ethereum ? window.ethereum : null;
}

export function isMobile() {
    return /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
}

export const fmt = (n, d = 0) => Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
export const units = (v, dec = 18) => Number(formatUnits(v ?? 0n, dec));
export const short = (a) => (a ? `${a.slice(0, 6)}…${a.slice(-4)}` : '');

const chainParams = () => ({
    chainId: CFG.chain.hex,
    chainName: CFG.chain.name,
    nativeCurrency: CFG.chain.native,
    rpcUrls: [CFG.chain.rpc],
    blockExplorerUrls: [CFG.chain.explorer],
    iconUrls: [CFG.token.logo],
});

/** สลับไปเชน TPIX — ถ้ากระเป๋ายังไม่รู้จักเชนนี้ ให้เพิ่มก่อน */
export async function ensureChain(eth = injected()) {
    if (!eth) throw Object.assign(new Error('ไม่พบกระเป๋าในเบราว์เซอร์นี้'), { code: 'NO_WALLET' });
    try {
        await eth.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: CFG.chain.hex }] });
    } catch (e) {
        const unknown = e?.code === 4902 || e?.data?.originalError?.code === 4902 || /unrecognized|not added|unknown chain/i.test(e?.message || '');
        if (!unknown) throw e;
        await eth.request({ method: 'wallet_addEthereumChain', params: [chainParams()] });
    }
}

/**
 * ปุ่มเดียว: เพิ่มเชน TPIX แล้วเพิ่มเหรียญ IDTC (ถ้า deploy แล้ว)
 * @returns {Promise<{chain: boolean, token: boolean}>}
 */
export async function addNetworkAndToken() {
    const eth = injected();
    await ensureChain(eth);
    const token = contractAddress('token');
    if (!token) return { chain: true, token: false };
    await eth.request({
        method: 'wallet_watchAsset',
        params: { type: 'ERC20', options: { address: token, symbol: CFG.token.symbol, decimals: CFG.token.decimals, image: CFG.token.logo } },
    });
    return { chain: true, token: true };
}

/** เชื่อมกระเป๋า + สลับเชน แล้วคืน signer */
export async function connectWallet() {
    const eth = injected();
    if (!eth) throw Object.assign(new Error('ไม่พบกระเป๋าในเบราว์เซอร์นี้'), { code: 'NO_WALLET' });
    const [account] = await eth.request({ method: 'eth_requestAccounts' });
    await ensureChain(eth);
    const provider = new BrowserProvider(eth, 'any');
    return { account: getAddress(account), provider, signer: await provider.getSigner() };
}

/** ผู้ใช้กดยกเลิกในกระเป๋า (แต่ละกระเป๋าส่งรูปแบบต่างกัน) */
export function isRejected(e) {
    const codes = [e?.code, e?.info?.error?.code, e?.error?.code, e?.data?.originalError?.code];
    if (codes.includes(4001) || codes.includes('ACTION_REJECTED')) return true;
    return /user (rejected|denied|cancel+ed)|rejected by (the )?user|request rejected/i.test(e?.message || e?.info?.error?.message || '');
}

/** แปลง error ของกระเป๋า/สัญญาเป็นข้อความไทยที่บอกว่าต้องทำอะไรต่อ */
export function walletError(e) {
    if (e?.code === 'NO_WALLET') return isMobile() ? 'เปิดเว็บนี้ในแอปกระเป๋า (MetaMask, Trust, OKX) แล้วลองอีกครั้ง' : 'ติดตั้ง MetaMask หรือกระเป๋าที่รองรับ EVM ก่อน';
    if (isRejected(e)) return 'คุณยกเลิกในกระเป๋า กดอีกครั้งเมื่อพร้อม';
    if (e?.code === -32002) return 'กระเป๋ามีคำขอค้างอยู่ เปิดกระเป๋าแล้วกดยืนยันหรือยกเลิกก่อน';
    if (e?.code === 4100) return 'กระเป๋ายังไม่อนุญาตบัญชีนี้ให้เว็บใช้ กดเชื่อมกระเป๋าอีกครั้ง';
    if (e?.code === 4902) return 'กระเป๋ายังไม่มีเชน TPIX กด "เพิ่มเชน + เหรียญ" ก่อน';
    if (e?.code === 'WRONG_CHAIN') return 'กระเป๋ายังไม่อยู่บนเชน TPIX กดอีกครั้งแล้วยืนยันการสลับเชนในกระเป๋า';
    if (e?.code === 'ACCOUNT_CHANGED') return 'บัญชีในกระเป๋าเปลี่ยนระหว่างทำรายการ ระบบหยุดให้ก่อน ตรวจยอดแล้วเริ่มใหม่';
    if (e?.code === 'TIMEOUT') return 'เชนตอบช้าเกินไป ตรวจอินเทอร์เน็ตแล้วลองใหม่อีกครั้ง';
    if (e?.code === 'TX_REVERTED') return 'รายการถูกปฏิเสธบนเชน ไม่มีการตัดเงินนอกจากค่าแก๊ส (ซึ่งเป็น 0)';
    const dec = decodeRevert(e);
    if (dec && REVERT_TH[dec.name]) return REVERT_TH[dec.name];
    const reason = (dec?.name === 'Error' ? String(dec.args?.[0] ?? '') : '') || e?.reason || e?.shortMessage || e?.info?.error?.message || e?.message || '';
    if (e?.code === 'INSUFFICIENT_FUNDS' || /insufficient funds/i.test(reason)) return 'กระเป๋าตั้งค่าแก๊สไว้มากกว่า 0 แต่ไม่มี TPIX จ่าย ลองตั้งค่าแก๊สในกระเป๋าเป็น 0 แล้วกดใหม่';
    if (['NETWORK_ERROR', 'SERVER_ERROR'].includes(e?.code) || /failed to fetch|network error|load failed/i.test(reason)) return 'เชื่อมต่อเชน TPIX ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองใหม่';
    const map = {
        NotActive: 'ยังไม่อยู่ในช่วงเวลาขาย',
        BelowMinimum: 'ยอดซื้อต่ำกว่าขั้นต่ำ',
        CapExceeded: 'ยอดนี้เกินจำนวนที่เหลือใน presale',
        WalletLimitExceeded: 'ยอดนี้เกินเพดานต่อกระเป๋า',
        EnforcedPause: 'presale หยุดชั่วคราวอยู่',
        ZeroOutput: 'ยอดซื้อน้อยเกินไป',
        NothingToRelease: 'ยังไม่มีงวดที่ถึงเวลาปลด',
        'insufficient allowance': 'ต้องกดอนุญาต USDT ก่อน',
        'transfer amount exceeds balance': 'USDT ในกระเป๋าไม่พอ',
    };
    for (const [k, v] of Object.entries(map)) if (reason.includes(k)) return v;
    // RPC ของเชน TPIX (Polygon Edge) ไม่ส่ง revert data กลับมา — บอกได้แค่ว่าสัญญาปฏิเสธ
    if (e?.code === 'CALL_EXCEPTION' || /execution (was )?reverted|missing revert data|unable to apply transaction/i.test(reason)) return 'สัญญาปฏิเสธรายการนี้ ตรวจยอดและเงื่อนไขแล้วลองใหม่อีกครั้ง';
    return reason ? `ทำรายการไม่สำเร็จ: ${reason.slice(0, 140)}` : 'ทำรายการไม่สำเร็จ ลองใหม่อีกครั้ง';
}

/* ───────────── ส่วนเสริมสำหรับหน้า presale และความโปร่งใส ───────────── */

/** custom error ของสัญญาที่หน้าเว็บเรียก (FixedPriceSale, TrancheVesting, OpenZeppelin v5 ERC20/Pausable) */
ABI.errors = [
    'error NotActive()',
    'error BelowMinimum()',
    'error ZeroOutput()',
    'error CapExceeded()',
    'error WalletLimitExceeded()',
    'error SaleNotOver()',
    'error EnforcedPause()',
    'error ExpectedPause()',
    'error NothingToRelease()',
    'error NotBeneficiary()',
    'error ReentrancyGuardReentrantCall()',
    'error SafeERC20FailedOperation(address token)',
    'error OwnableUnauthorizedAccount(address account)',
    'error ERC20InsufficientBalance(address sender, uint256 balance, uint256 needed)',
    'error ERC20InsufficientAllowance(address spender, uint256 allowance, uint256 needed)',
    'error ERC20InvalidSender(address sender)',
    'error ERC20InvalidReceiver(address receiver)',
    'error ERC20InvalidSpender(address spender)',
];

const REVERT_TH = {
    NotActive: 'ยังไม่อยู่ในช่วงเวลาขาย',
    BelowMinimum: 'ยอดซื้อต่ำกว่าขั้นต่ำของ presale',
    ZeroOutput: 'ยอดซื้อน้อยเกินไป',
    CapExceeded: 'ยอดนี้เกินจำนวนที่เหลือใน presale',
    WalletLimitExceeded: 'ยอดนี้เกินเพดานต่อกระเป๋า',
    EnforcedPause: 'ระบบหยุดชั่วคราวอยู่ (presale หรือ USDT) ลองใหม่ภายหลัง',
    NothingToRelease: 'ยังไม่มีงวดที่ถึงเวลาปลด',
    ERC20InsufficientBalance: 'ยอดเหรียญในกระเป๋าไม่พอ',
    ERC20InsufficientAllowance: 'ยอดอนุญาต USDT ไม่พอ กดอนุญาต USDT ก่อน',
    ReentrancyGuardReentrantCall: 'สัญญาปฏิเสธรายการซ้อน ลองใหม่อีกครั้ง',
    SafeERC20FailedOperation: 'โอนเหรียญไม่สำเร็จ',
    Panic: 'สัญญาปฏิเสธรายการนี้',
};

const errorsIface = new Interface(ABI.errors);

/** หา revert data (0x…) ที่ซ่อนอยู่ใน error ของ ethers/กระเป๋า แล้วถอดเป็นชื่อ error ของสัญญา */
export function decodeRevert(e) {
    if (e?.revert?.name) return { name: e.revert.name, args: e.revert.args ?? [] };
    const seen = new Set();
    const walk = (x, depth) => {
        if (!x || depth > 5) return null;
        if (typeof x === 'string') {
            if (/^0x[0-9a-fA-F]{8}/.test(x)) return x;
            if (x.startsWith('{')) { try { return walk(JSON.parse(x), depth + 1); } catch { return null; } }
            return null;
        }
        if (typeof x !== 'object' || seen.has(x)) return null;
        seen.add(x);
        for (const k of ['data', 'error', 'info', 'originalError', 'cause', 'body']) {
            const hit = walk(x[k], depth + 1);
            if (hit) return hit;
        }
        return null;
    };
    const data = walk(e, 0);
    if (!data) return null;
    try {
        const d = errorsIface.parseError(data);
        return d ? { name: d.name, args: d.args } : null;
    } catch { return null; }
}

/** ตัดสัญญาที่ค้าง: ถ้าเชนไม่ตอบภายในเวลา ให้ throw TIMEOUT แทนการรอไม่รู้จบ */
export function withTimeout(promise, ms = 15000) {
    let t;
    const timer = new Promise((_, rej) => { t = setTimeout(() => rej(Object.assign(new Error('timeout'), { code: 'TIMEOUT' })), ms); });
    return Promise.race([promise, timer]).finally(() => clearTimeout(t));
}

/**
 * แปลงจำนวนที่ผู้ใช้พิมพ์ (ข้อความทศนิยม) เป็นหน่วยเล็กสุดแบบ BigInt — ไม่ผ่าน float เลย
 * @returns {{ units: bigint|null, error: string, empty: boolean }}
 */
export function parseAmount(raw, decimals) {
    let s = String(raw ?? '').trim().replace(/\s+/g, '');
    if (s === '') return { units: null, error: '', empty: true };
    if (s.startsWith('-')) return { units: null, error: 'จำนวนต้องไม่ติดลบ', empty: false };
    if (s.includes(',')) {
        // รับเฉพาะจุลภาคคั่นหลักพัน (1,000.50) — "10,5" แบบยุโรปจะกลายเป็น 105 จึงไม่รับ
        if (!/^\d{1,3}(,\d{3})+(\.\d*)?$/.test(s)) return { units: null, error: 'ใช้จุด (.) เป็นทศนิยม เช่น 10.5', empty: false };
        s = s.replace(/,/g, '');
    }
    if (!/^(\d+\.?\d*|\.\d+)$/.test(s)) return { units: null, error: 'กรอกเป็นตัวเลขเท่านั้น', empty: false };
    const [int = '', frac = ''] = s.split('.');
    if (frac.length > decimals) return { units: null, error: `ทศนิยมได้ไม่เกิน ${decimals} ตำแหน่ง`, empty: false };
    if (int.replace(/^0+/, '').length > 15) return { units: null, error: 'จำนวนมากเกินไป', empty: false };
    const units = BigInt((int || '0') + frac.padEnd(decimals, '0'));
    return { units, error: '', empty: false };
}

/** แสดง BigInt เป็นตัวเลขคั่นหลัก ตัดทศนิยมทิ้ง (ไม่ปัดขึ้น — ไม่โชว์เกินที่ได้จริง) */
export function formatAmount(value, decimals, shown = 2, { trim = false } = {}) {
    const v = BigInt(value ?? 0n);
    const neg = v < 0n;
    const a = neg ? -v : v;
    const base = 10n ** BigInt(decimals);
    const int = (a / base).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    let frac = '';
    if (shown > 0) {
        frac = (a % base).toString().padStart(decimals, '0').slice(0, shown).padEnd(shown, '0');
        if (trim) frac = frac.replace(/0+$/, '');
    }
    return (neg ? '-' : '') + int + (frac ? `.${frac}` : '');
}

/** BigInt เป็นข้อความทศนิยมสำหรับใส่ช่องกรอก (ไม่มีจุลภาค) */
export function unitsToInput(value, decimals) {
    return formatAmount(value, decimals, decimals, { trim: true }).replace(/,/g, '');
}

let _skew = null;
/** เวลาปัจจุบันตามเชน (วินาที) — เทียบนาฬิกาเครื่องกับเวลาของบล็อกล่าสุดครั้งเดียว กันนาฬิกาเครื่องเพี้ยน */
export async function syncChainClock() {
    try {
        const b = await withTimeout(readProvider().getBlock('latest'), 10000);
        const diff = Number(b.timestamp) - Date.now() / 1000;
        // บล็อกล่าสุดเก่ากว่านาฬิกาจริงได้ไม่กี่วินาที — เชื่อเฉพาะตอนต่างกันมากพอจะมีผลกับการนับถอยหลัง
        _skew = Math.abs(diff) > 30 ? diff : 0;
    } catch { if (_skew === null) _skew = 0; }
    return _skew;
}
export const chainNow = () => Math.floor(Date.now() / 1000 + (_skew || 0));

/** ส่งธุรกรรมผ่านกระเป๋า — บังคับ gasPrice 0 (เชน TPIX ไม่มี EIP-1559 และตั้งแก๊สเป็น 0 ใน genesis) แล้วคืน tx hash ทันที */
export async function sendTx(signer, tx) {
    return signer.sendUncheckedTransaction({ ...tx, gasPrice: 0n });
}

/** รอ receipt ผ่าน RPC ของเราเอง (ไม่พึ่ง RPC ของกระเป๋า) */
export async function waitReceipt(hash, timeoutMs = 180000) {
    const p = readProvider();
    p.pollingInterval = 1500;
    const r = await p.waitForTransaction(hash, 1, timeoutMs);
    if (!r) throw Object.assign(new Error('timeout'), { code: 'TIMEOUT', hash });
    if (r.status !== 1) throw Object.assign(new Error('reverted'), { code: 'TX_REVERTED', hash, receipt: r });
    return r;
}

/** บัญชีที่เว็บนี้ได้รับอนุญาตอยู่แล้ว (ไม่เด้งถามผู้ใช้) */
export async function walletAccount() {
    const eth = injected();
    if (!eth) return null;
    try {
        const [a] = await eth.request({ method: 'eth_accounts' });
        return a ? getAddress(a) : null;
    } catch { return null; }
}

/** ตรวจว่ากระเป๋ายังอยู่บนเชน TPIX และยังเป็นบัญชีเดิม — ใช้ก่อนส่งทุกธุรกรรม */
export async function assertWallet(account) {
    const eth = injected();
    if (!eth) throw Object.assign(new Error('no wallet'), { code: 'NO_WALLET' });
    await ensureChain(eth);
    const cid = await eth.request({ method: 'eth_chainId' });
    if (Number.parseInt(cid, 16) !== Number(CFG.chain.id)) throw Object.assign(new Error('wrong chain'), { code: 'WRONG_CHAIN' });
    const [cur] = await eth.request({ method: 'eth_accounts' });
    if (!cur || cur.toLowerCase() !== account.toLowerCase()) throw Object.assign(new Error('account changed'), { code: 'ACCOUNT_CHANGED' });
}

/** เพิ่มเหรียญ ERC-20 ลงรายการในกระเป๋า (wallet_watchAsset) */
export async function watchToken(address, { symbol = CFG.token.symbol, decimals = CFG.token.decimals, image = CFG.token.logo } = {}) {
    const eth = injected();
    if (!eth) throw Object.assign(new Error('no wallet'), { code: 'NO_WALLET' });
    return eth.request({ method: 'wallet_watchAsset', params: { type: 'ERC20', options: { address: getAddress(address), symbol, decimals, ...(image ? { image } : {}) } } });
}

export const explorerTx = (hash) => `${String(CFG.chain.explorer || '').replace(/\/$/, '')}/tx/${hash}`;
export const explorerAddress = (a) => `${String(CFG.chain.explorer || '').replace(/\/$/, '')}/address/${a}`;

/** นับถอยหลังเป็น วัน/ชม./นาที/วินาที */
export function splitDuration(seconds) {
    let s = Math.max(0, Math.floor(seconds));
    const d = Math.floor(s / 86400); s -= d * 86400;
    const h = Math.floor(s / 3600); s -= h * 3600;
    const m = Math.floor(s / 60); s -= m * 60;
    return { d, h, m, s };
}

/** วันที่แบบไทย (พ.ศ.) */
export function thaiDate(ts, withTime = false) {
    if (!ts) return '—';
    const opt = withTime ? { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'long', year: 'numeric' };
    return new Date(Number(ts) * 1000).toLocaleString('th-TH', opt);
}

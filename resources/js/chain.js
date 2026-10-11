/**
 * งานบนเชนฝั่งเบราว์เซอร์ของ idtc.online
 *
 * - อ่านข้อมูล: JsonRpcProvider ตรงไปที่ rpc1.tpix.online (เปิด CORS ให้ idtc.online แล้ว)
 * - เขียนข้อมูล: ผ่านกระเป๋าของผู้ใช้ (window.ethereum) — เว็บไม่เคยถือคีย์ใคร
 * - ค่าเชน/ที่อยู่สัญญามาจาก window.IDTC (App\Support\Idtc::publicConfig)
 */
import { BrowserProvider, Contract, JsonRpcProvider, formatUnits, getAddress } from 'ethers';

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

/** แปลง error ของกระเป๋า/สัญญาเป็นข้อความไทยที่บอกว่าต้องทำอะไรต่อ */
export function walletError(e) {
    if (e?.code === 'NO_WALLET') return isMobile() ? 'เปิดเว็บนี้ในแอปกระเป๋า (MetaMask, Trust, OKX) แล้วลองอีกครั้ง' : 'ติดตั้ง MetaMask หรือกระเป๋าที่รองรับ EVM ก่อน';
    if (e?.code === 4001 || e?.code === 'ACTION_REJECTED' || e?.info?.error?.code === 4001) return 'คุณยกเลิกในกระเป๋า กดอีกครั้งเมื่อพร้อม';
    if (e?.code === -32002) return 'กระเป๋ามีคำขอค้างอยู่ เปิดกระเป๋าแล้วกดยืนยันหรือยกเลิกก่อน';
    const reason = e?.reason || e?.shortMessage || e?.info?.error?.message || e?.message || '';
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
    return reason ? `ทำรายการไม่สำเร็จ: ${reason.slice(0, 140)}` : 'ทำรายการไม่สำเร็จ ลองใหม่อีกครั้ง';
}

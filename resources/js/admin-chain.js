/**
 * คำสั่งบนเชนของหลังบ้าน IDTC — เซ็นด้วยกระเป๋าแอดมินในเบราว์เซอร์เท่านั้น (เซิร์ฟเวอร์ไม่ถือคีย์)
 *
 * ลำดับทุกคำสั่ง: ตรวจค่าที่กรอก → เชื่อมกระเป๋า + สลับเชน → ตรวจสิทธิ์บนสัญญา (owner/beneficiary)
 *   → จำลองธุรกรรม (staticCall) กัน revert → กล่องยืนยันทวนผล + ที่อยู่ปลายทาง → ตรวจว่าบัญชี/เชนไม่เปลี่ยน
 *   → ส่งธุรกรรม → บันทึก tx hash ลงบันทึกการทำงาน → รอยืนยัน → ให้เซิร์ฟเวอร์ตรวจผลจากเชนเอง
 *
 * ชื่อคำสั่งต้องตรงกับ App\Services\Admin\ChainActions ฝั่ง PHP
 */
import { Contract, ZeroAddress, getAddress, isAddress, parseUnits } from 'ethers';
import { ABI, CFG, connectWallet, contractAddress, fmt, injected, readProvider, short, walletError } from './chain';

export class AdminError extends Error {}

const fail = (msg) => { throw new AdminError(msg); };

export const ADMIN_ABI = {
    sale: [
        ...ABI.sale,
        'function pendingOwner() view returns (address)',
        'function saleToken() view returns (address)',
        'function payToken() view returns (address)',
        'function setTreasury(address treasury_)',
        'function setWindow(uint64 startTime_, uint64 endTime_)',
        'function setLimits(uint256 minPurchase_, uint256 maxPerWallet_)',
        'function pause()',
        'function unpause()',
        'function withdrawUnsold(address to)',
        'function transferOwnership(address newOwner)',
        'function acceptOwnership()',
        'error ZeroAddress()',
        'error BadConfig()',
        'error NotActive()',
        'error BelowMinimum()',
        'error ZeroOutput()',
        'error CapExceeded()',
        'error WalletLimitExceeded()',
        'error SaleNotOver()',
        'error OwnableUnauthorizedAccount(address account)',
        'error OwnableInvalidOwner(address owner)',
        'error EnforcedPause()',
        'error ExpectedPause()',
        'error ReentrancyGuardReentrantCall()',
        'error SafeERC20FailedOperation(address token)',
    ],
    vesting: [
        ...ABI.vesting,
        'function pendingBeneficiary() view returns (address)',
        'function label() view returns (string)',
        'function transferBeneficiary(address newBeneficiary)',
        'function acceptBeneficiary()',
        'function sweepExcess(address other)',
        'error ZeroAddress()',
        'error BadSchedule()',
        'error NothingToRelease()',
        'error NotBeneficiary()',
        'error NotPendingBeneficiary()',
        'error NoExcess()',
        'error ReentrancyGuardReentrantCall()',
        'error SafeERC20FailedOperation(address token)',
    ],
};

const REVERTS = {
    OwnableUnauthorizedAccount: 'กระเป๋านี้ไม่ใช่เจ้าของสัญญา (owner) — สลับไปกระเป๋าเจ้าของแล้วลองใหม่',
    OwnableInvalidOwner: 'ที่อยู่เจ้าของใหม่ไม่ถูกต้อง',
    SaleNotOver: 'ยังถอนไม่ได้: ต้องรอปิดรอบ หรือกดหยุดขายชั่วคราวก่อน',
    BadConfig: 'ค่าไม่ถูกต้อง: เวลาปิดขายต้องอยู่หลังเวลาเปิด',
    ZeroAddress: 'ที่อยู่ปลายทางเป็นที่อยู่ว่าง (0x000…) ไม่ได้',
    EnforcedPause: 'สัญญาหยุดชั่วคราวอยู่แล้ว',
    ExpectedPause: 'สัญญาไม่ได้หยุดอยู่ (เปิดขายอยู่แล้ว)',
    NotBeneficiary: 'กระเป๋านี้ไม่ใช่ผู้รับเหรียญ (beneficiary) ของสัญญาล็อก',
    NotPendingBeneficiary: 'กระเป๋านี้ไม่ใช่ผู้รับรายใหม่ที่รอรับสิทธิ์',
    NothingToRelease: 'ยังไม่มีงวดที่ถึงเวลาปลด',
    NoExcess: 'ไม่มีเหรียญส่วนเกินให้คืน',
    SafeERC20FailedOperation: 'โอนเหรียญไม่สำเร็จ (เหรียญในสัญญาไม่พอหรือเหรียญปฏิเสธการโอน)',
    ReentrancyGuardReentrantCall: 'สัญญาปฏิเสธการเรียกซ้อน',
};

/** error ใด ๆ → ข้อความไทยที่บอกว่าเกิดอะไรและต้องทำอะไรต่อ */
export function adminError(e) {
    if (e instanceof AdminError) return e.message;
    const name = e?.revert?.name || e?.error?.revert?.name || e?.info?.error?.data?.revert?.name
        || (/reverted(?: with custom error)?:?\s*"?(\w+)\(/.exec(e?.shortMessage || e?.message || '') || [])[1];
    if (name && REVERTS[name]) return REVERTS[name];
    if (e?.code === 'NETWORK_ERROR' || /network changed/i.test(e?.message || '')) return 'เชนในกระเป๋าเปลี่ยนระหว่างทำรายการ ยกเลิกแล้ว — สลับกลับเป็น TPIX Chain แล้วเริ่มใหม่';
    if (e?.code === 'TIMEOUT') return 'รอยืนยันนานเกินไป — ดูสถานะในบันทึกการทำงาน ระบบจะตรวจผลจากเชนให้เอง';
    if (e?.code === 'CALL_EXCEPTION') return 'สัญญาปฏิเสธคำสั่งนี้ (revert) — ตรวจค่าที่กรอกและสถานะสัญญาอีกครั้ง';
    return walletError(e);
}

/* ───────────── ตัวช่วยตรวจค่าที่กรอก ───────────── */

function amount(value, decimals, label, { allowZero = true } = {}) {
    const s = String(value ?? '').trim().replace(/,/g, '');
    if (s === '') fail(`กรอก${label}`);
    if (!/^\d+(\.\d+)?$/.test(s)) fail(`${label}ต้องเป็นตัวเลข (ใช้จุดทศนิยม ไม่ใส่หน่วย)`);
    const frac = (s.split('.')[1] || '').replace(/0+$/, '');
    if (frac.length > decimals) fail(`${label}มีทศนิยมได้ไม่เกิน ${decimals} ตำแหน่ง`);
    const v = parseUnits(s, decimals);
    if (!allowZero && v === 0n) fail(`${label}ต้องมากกว่า 0`);
    return v;
}

function address(value, label) {
    const s = String(value ?? '').trim();
    if (!s) fail(`กรอก${label}`);
    if (!isAddress(s)) fail(`${label}ไม่ถูกต้อง — ต้องเป็น 0x ตามด้วยตัวอักษรฐานสิบหก 40 ตัว (ถ้าตัวพิมพ์ผสม ต้องตรง checksum)`);
    const a = getAddress(s);
    if (a === ZeroAddress) fail(`${label}เป็นที่อยู่ว่างไม่ได้`);
    for (const key of ['presale', 'vesting', 'token', 'pair_usdt', 'pair_tpix']) {
        if (contractAddress(key) === a) fail(`${label}เป็นที่อยู่ของสัญญาโครงการเอง (${key}) — เหรียญจะติดอยู่ในสัญญา ใช้ไม่ได้`);
    }
    return a;
}

/** ค่า datetime-local (YYYY-MM-DDTHH:mm) ตีความเป็นเวลาไทย (UTC+7) เสมอ ไม่ขึ้นกับเขตเวลาของเครื่อง */
function bangkokTime(value, label) {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(String(value ?? '').trim());
    if (!m) fail(`กรอก${label}ให้ครบ (วันที่และเวลา)`);
    const [, y, mo, d, h, mi] = m.map(Number);
    return Math.floor((Date.UTC(y, mo - 1, d, h, mi) - 7 * 3600 * 1000) / 1000);
}

const TH_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
export function thaiTime(unix) {
    const d = new Date((unix + 7 * 3600) * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getUTCDate()} ${TH_MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear() + 543} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())} น.`;
}

/* ───────────── รายการคำสั่ง ─────────────
 * args(f)    → อาร์กิวเมนต์ของฟังก์ชัน (โยน AdminError ถ้ากรอกผิด)
 * params(a)  → ค่าที่ส่งไปบันทึก (ชื่อตรงกับ ChainActions::ALL[...]['params'])
 * confirm()  → เนื้อหาในกล่องยืนยัน { title, lines, target?, targetLabel?, warnings? }
 */
export const ACTIONS = {
    'presale.pause': {
        contract: 'presale', abi: 'sale', fn: 'pause', guard: 'owner', destructive: true,
        confirm: () => ({
            title: 'หยุดขาย presale ชั่วคราว',
            lines: ['ผู้ซื้อทุกคนจะกดซื้อไม่ได้ทันที จนกว่าจะกด "เปิดขายต่อ"', 'ระหว่างหยุด เจ้าของถอนเหรียญที่เหลือออกจากสัญญาได้ — ใช้เมื่อมีเหตุผิดปกติเท่านั้น'],
        }),
    },
    'presale.unpause': {
        contract: 'presale', abi: 'sale', fn: 'unpause', guard: 'owner',
        confirm: () => ({ title: 'เปิดขาย presale ต่อ', lines: ['ผู้ซื้อกลับมากดซื้อได้ทันที (ถ้าอยู่ในช่วงเวลาขายและยังไม่ครบเพดาน)'] }),
    },
    'presale.setLimits': {
        contract: 'presale', abi: 'sale', fn: 'setLimits', guard: 'owner',
        args: (f) => [amount(f.min, 6, 'ยอดซื้อขั้นต่ำ'), amount(f.max === '' || f.max == null ? '0' : f.max, 18, 'เพดานต่อกระเป๋า')],
        params: ([min, max]) => ({ minPurchase: min.toString(), maxPerWallet: max.toString() }),
        confirm: (f, [min, max]) => ({
            title: 'ตั้งยอดซื้อขั้นต่ำ / เพดานต่อกระเป๋า',
            lines: [
                min === 0n ? 'ไม่กำหนดยอดซื้อขั้นต่ำ' : `ซื้อขั้นต่ำ ${f.min} USDT ต่อครั้ง`,
                max === 0n ? 'ไม่จำกัดจำนวนที่ซื้อได้ต่อกระเป๋า' : `แต่ละกระเป๋าซื้อรวมได้ไม่เกิน ${fmt(Number(String(f.max).replace(/,/g, '')))} IDTC`,
                'มีผลกับการซื้อครั้งถัดไปทันที (ไม่กระทบรายการที่ซื้อไปแล้ว)',
            ],
        }),
    },
    'presale.setWindow': {
        contract: 'presale', abi: 'sale', fn: 'setWindow', guard: 'owner',
        args: (f) => {
            const start = bangkokTime(f.start, 'เวลาเปิดขาย');
            const end = bangkokTime(f.end, 'เวลาปิดขาย');
            if (end <= start) fail('เวลาปิดขายต้องอยู่หลังเวลาเปิดขาย');
            return [BigInt(start), BigInt(end)];
        },
        params: ([s, e]) => ({ startTime: s.toString(), endTime: e.toString() }),
        confirm: (f, [s, e]) => {
            const now = Date.now() / 1000;
            const warnings = [];
            if (Number(e) <= now) warnings.push('เวลาปิดอยู่ในอดีต — การขายจะปิดทันทีหลังยืนยัน');
            else if (Number(s) <= now) warnings.push('เวลาเปิดอยู่ในอดีต — ถ้าไม่ได้หยุดชั่วคราว การขายจะเปิดทันที');
            return { title: 'ตั้งช่วงเวลาขาย', lines: [`เปิดขาย ${thaiTime(Number(s))}`, `ปิดขาย ${thaiTime(Number(e))}`, 'เวลาไทย (UTC+7)'], warnings };
        },
    },
    'presale.setTreasury': {
        contract: 'presale', abi: 'sale', fn: 'setTreasury', guard: 'owner', destructive: true, checkTarget: true,
        args: (f) => [address(f.address, 'ที่อยู่กระเป๋ารับเงินใหม่')],
        params: ([a]) => ({ treasury: a }),
        confirm: (f, [a]) => ({
            title: 'เปลี่ยนกระเป๋ารับเงิน (treasury)',
            lines: ['USDT จากการซื้อทุกครั้งหลังจากนี้จะโอนเข้ากระเป๋าใหม่ทันที', 'ตรวจให้แน่ว่าโครงการควบคุมกระเป๋านี้ได้จริง (มีคีย์หรือเป็น multisig ของโครงการ)'],
            target: a, targetLabel: 'กระเป๋ารับเงินใหม่',
        }),
    },
    'presale.withdrawUnsold': {
        contract: 'presale', abi: 'sale', fn: 'withdrawUnsold', guard: 'owner', destructive: true, checkTarget: true,
        args: (f) => [address(f.to, 'ที่อยู่ปลายทาง')],
        params: ([a]) => ({ to: a }),
        confirm: (f, [a]) => ({
            title: 'ถอนเหรียญ IDTC ที่ขายไม่หมด',
            lines: [`IDTC ทั้งหมดที่เหลือในสัญญา presale (${f.balanceText || 'ทั้งหมด'}) จะโอนไปที่ปลายทาง`, 'หลังถอน presale จะไม่มีเหรียญให้ขาย แม้จะเปิดขายต่อ', 'ผู้ที่ซื้อไปแล้วไม่ได้รับผลกระทบ (ได้เหรียญไปตั้งแต่ตอนซื้อ)'],
            target: a, targetLabel: 'ปลายทางที่จะได้รับ IDTC',
        }),
    },
    'presale.transferOwnership': {
        contract: 'presale', abi: 'sale', fn: 'transferOwnership', guard: 'owner', destructive: true, checkTarget: true,
        args: (f) => [address(f.address, 'ที่อยู่เจ้าของใหม่')],
        params: ([a]) => ({ newOwner: a }),
        confirm: (f, [a]) => ({
            title: 'เริ่มโอนสิทธิ์เจ้าของสัญญา presale',
            lines: ['ขั้นที่ 1 จาก 2 — กระเป๋าใหม่ต้องเข้ามากด "รับสิทธิ์เจ้าของ" เองอีกครั้ง', 'เมื่อรับแล้ว กระเป๋าปัจจุบันจะหมดสิทธิ์สั่งงาน presale ทั้งหมด (หยุดขาย ตั้งค่า ถอนเหรียญ)', 'ก่อนกระเป๋าใหม่รับ คุณยกเลิกได้ด้วยการโอนซ้ำไปที่กระเป๋าตัวเอง'],
            target: a, targetLabel: 'เจ้าของใหม่ (ต้องกดรับเอง)',
        }),
    },
    'presale.acceptOwnership': {
        contract: 'presale', abi: 'sale', fn: 'acceptOwnership', guard: 'pendingOwner',
        confirm: () => ({ title: 'รับสิทธิ์เจ้าของสัญญา presale', lines: ['กระเป๋าที่เชื่อมอยู่จะเป็นเจ้าของ presale คนใหม่ทันที', 'เจ้าของเดิมจะหมดสิทธิ์สั่งงาน'] }),
    },
    'vesting.release': {
        contract: 'vesting', abi: 'vesting', fn: 'release', guard: 'anyone',
        confirm: (f) => ({ title: 'ปลดงวดที่ถึงเวลา', lines: [`IDTC ${f.amountText || 'ที่ถึงเวลา'} จะโอนไปที่ผู้รับ ${short(f.beneficiary || '')} ตามตารางในสัญญา`, 'ใครกดก็ได้ เหรียญไปที่ผู้รับเท่านั้น'] }),
    },
    'vesting.transferBeneficiary': {
        contract: 'vesting', abi: 'vesting', fn: 'transferBeneficiary', guard: 'beneficiary', destructive: true, checkTarget: true,
        args: (f) => [address(f.address, 'ที่อยู่ผู้รับรายใหม่')],
        params: ([a]) => ({ newBeneficiary: a }),
        confirm: (f, [a]) => ({
            title: 'เริ่มเปลี่ยนผู้รับเหรียญที่ปลดล็อก',
            lines: ['ขั้นที่ 1 จาก 2 — กระเป๋าใหม่ต้องเข้ามากด "รับสิทธิ์ผู้รับ" เองอีกครั้ง', 'เมื่อรับแล้ว งวดที่ปลดหลังจากนั้นทั้งหมดจะไปที่กระเป๋าใหม่ และกระเป๋าเดิมหมดสิทธิ์'],
            target: a, targetLabel: 'ผู้รับรายใหม่ (ต้องกดรับเอง)',
        }),
    },
    'vesting.acceptBeneficiary': {
        contract: 'vesting', abi: 'vesting', fn: 'acceptBeneficiary', guard: 'pendingBeneficiary',
        confirm: () => ({ title: 'รับสิทธิ์ผู้รับเหรียญที่ปลดล็อก', lines: ['กระเป๋าที่เชื่อมอยู่จะเป็นผู้รับเหรียญทุกงวดที่ปลดหลังจากนี้'] }),
    },
    'vesting.sweepExcess': {
        contract: 'vesting', abi: 'vesting', fn: 'sweepExcess', guard: 'beneficiary', destructive: true,
        args: (f) => [address(f.token, 'ที่อยู่เหรียญ')],
        params: ([a]) => ({ token: a }),
        confirm: (f, [a]) => ({
            title: 'คืนเหรียญส่วนเกินจากสัญญาล็อก',
            lines: ['โอนเฉพาะส่วนที่เกินตารางล็อก (หรือเหรียญอื่นที่ส่งผิดเข้ามา) ไปที่ผู้รับ', 'ยอดที่ต้องล็อกตามตารางไม่ถูกแตะ — สัญญาบังคับเอง'],
            target: a, targetLabel: 'เหรียญที่จะคืน',
        }),
    },
};

const GUARDS = {
    owner: ['owner', 'เจ้าของ (owner)'],
    pendingOwner: ['pendingOwner', 'เจ้าของใหม่ที่รอรับสิทธิ์'],
    beneficiary: ['beneficiary', 'ผู้รับเหรียญ (beneficiary)'],
    pendingBeneficiary: ['pendingBeneficiary', 'ผู้รับรายใหม่ที่รอรับสิทธิ์'],
};

async function checkGuard(guard, reader, account) {
    if (guard === 'anyone') return;
    const [fn, label] = GUARDS[guard];
    let expected;
    try {
        expected = await reader[fn]();
    } catch {
        fail('อ่านสิทธิ์จากสัญญาไม่ได้ (เชนตอบช้าหรือหลุด) ลองใหม่อีกครั้ง');
    }
    if (expected === ZeroAddress && guard.startsWith('pending')) {
        fail(guard === 'pendingOwner' ? 'ยังไม่มีการโอนสิทธิ์เจ้าของที่รอรับ' : 'ยังไม่มีผู้รับรายใหม่ที่รอรับสิทธิ์');
    }
    if (expected.toLowerCase() !== account.toLowerCase()) {
        fail(`กระเป๋าที่เชื่อมอยู่ (${short(account)}) ไม่ใช่${label} ของสัญญานี้ — ต้องใช้กระเป๋า ${short(expected)} สลับบัญชีในกระเป๋าแล้วลองใหม่`);
    }
}

async function assertSameAccount(account) {
    const eth = injected();
    const [now] = await eth.request({ method: 'eth_accounts' });
    const chain = await eth.request({ method: 'eth_chainId' });
    if (!now || now.toLowerCase() !== account.toLowerCase()) fail('บัญชีในกระเป๋าเปลี่ยนระหว่างทำรายการ ยกเลิกแล้ว — เริ่มใหม่อีกครั้ง');
    if (parseInt(chain, 16) !== Number(CFG.chain.id)) fail('เชนในกระเป๋าเปลี่ยนระหว่างทำรายการ ยกเลิกแล้ว — สลับกลับเป็น TPIX Chain แล้วเริ่มใหม่');
}

/* ───────────── คุยกับเซิร์ฟเวอร์ ───────────── */

const ADMIN = () => window.IDTC_ADMIN || {};

export async function api(url, body = null, method = 'POST') {
    let res;
    try {
        res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': ADMIN().csrf || '' },
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch {
        throw new AdminError('ติดต่อเซิร์ฟเวอร์ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองใหม่');
    }
    let data = {};
    try { data = await res.json(); } catch { /* ไม่ใช่ JSON */ }
    if (!res.ok) {
        const msg = data?.message && !/^Server Error|^CSRF|^Unauthenticated/i.test(data.message) ? data.message
            : res.status === 419 ? 'หน้านี้เปิดค้างนานเกินไป รีเฟรชหน้าแล้วลองใหม่'
                : res.status === 401 ? 'หมดเวลาเข้าระบบ เข้าสู่ระบบใหม่อีกครั้ง'
                    : res.status === 429 ? 'ลองบ่อยเกินไป รอสักครู่แล้วลองใหม่'
                        : `เซิร์ฟเวอร์ตอบผิดพลาด (${res.status}) ลองใหม่อีกครั้ง`;
        throw Object.assign(new AdminError(msg), { status: res.status, data });
    }
    return data;
}

async function recordTx(action, contract, params, hash, from) {
    const body = { action, contract, params, tx_hash: hash, from };
    let last;
    for (let i = 0; i < 4; i++) {
        try { return await api(ADMIN().routes.tx, body); } catch (e) {
            last = e;
            if (e.status && e.status < 500 && e.status !== 429) break; // ข้อมูลผิด — ลองซ้ำก็ไม่ผ่าน
            await new Promise((r) => setTimeout(r, 1500 * (i + 1)));
        }
    }
    throw Object.assign(new AdminError(`ส่งธุรกรรมแล้ว แต่บันทึกลงระบบไม่สำเร็จ (${last?.message || 'เครือข่าย'}) — เก็บ tx hash นี้ไว้: ${hash}`), { recorded: false });
}

export async function checkTx(id) {
    return api(String(ADMIN().routes.txCheck).replace('__ID__', id));
}

/* ───────────── ลำดับการทำคำสั่ง ─────────────
 * ui.stage(state, message, extra) — state: check | wallet | confirm | sign | pending | confirmed | failed | cancelled
 */
export async function runAction(name, fields, ui, confirmFn) {
    const spec = ACTIONS[name];
    if (!spec) fail('ไม่รู้จักคำสั่งนี้');
    const target = contractAddress(spec.contract);
    if (!target) fail('สัญญานี้ยังไม่ได้ตั้งที่อยู่ (รอ deploy)');

    // 1) ค่าที่กรอก — ผิดตั้งแต่ตรงนี้ ไม่ต้องเปิดกระเป๋า
    const args = spec.args ? spec.args(fields) : [];

    // 2) กระเป๋า + เชน
    ui.stage('wallet', 'เปิดกระเป๋า… เลือกบัญชีและยืนยันการสลับไป TPIX Chain');
    const { account, signer } = await connectWallet();

    // 3) สิทธิ์บนสัญญา (อ่านสดจากเชน ไม่ใช้ค่าที่แคชไว้)
    ui.stage('check', 'ตรวจสิทธิ์บนสัญญา…');
    const reader = new Contract(target, ADMIN_ABI[spec.abi], readProvider());
    await checkGuard(spec.guard, reader, account);

    // 4) จำลองธุรกรรมก่อน — ถ้าสัญญาจะปฏิเสธ บอกเหตุผลเลย ไม่ต้องเสียลายเซ็น
    const writer = new Contract(target, ADMIN_ABI[spec.abi], signer);
    await writer[spec.fn].staticCall(...args);

    // 5) ทวนผล + ที่อยู่ปลายทาง
    const box = spec.confirm(fields, args);
    const warnings = [...(box.warnings || [])];
    if (spec.checkTarget && box.target) {
        try {
            const code = await readProvider().getCode(box.target);
            if (code && code !== '0x') warnings.push('ปลายทางเป็นสัญญา (เช่น multisig) — ตรวจว่าสัญญานั้นรับและใช้เหรียญนี้ได้จริง');
        } catch { /* อ่านไม่ได้ก็ไม่บล็อก */ }
        if (box.target.toLowerCase() === account.toLowerCase()) warnings.push('ปลายทางคือกระเป๋าที่คุณเชื่อมอยู่เอง');
    }
    ui.stage('confirm', 'รอยืนยันในหน้านี้…');
    const ok = await confirmFn({ ...box, warnings, danger: !!spec.destructive });
    if (!ok) { ui.stage('cancelled', 'ยกเลิกแล้ว ไม่มีอะไรถูกส่ง'); return null; }

    // 6) บัญชี/เชนต้องยังเหมือนเดิม
    await assertSameAccount(account);

    // 7) ส่ง
    ui.stage('sign', 'ยืนยันธุรกรรมในกระเป๋า… (ค่าแก๊ส 0)');
    const tx = await writer[spec.fn](...args);

    // 8) บันทึก
    ui.stage('pending', 'ส่งธุรกรรมแล้ว รอยืนยันบนเชน…', { hash: tx.hash });
    const params = spec.params ? spec.params(args) : {};
    let log = null;
    try {
        log = await recordTx(name, target, params, tx.hash, account);
    } catch (e) {
        ui.stage('pending', adminError(e), { hash: tx.hash });
    }

    // 9) รอผล แล้วให้เซิร์ฟเวอร์ตรวจจากเชนเอง
    let receipt = null;
    try { receipt = await tx.wait(1, 180000); } catch (e) {
        if (e?.code !== 'CALL_EXCEPTION' && e?.code !== 'TIMEOUT') throw e;
    }
    if (log?.id) {
        for (let i = 0; i < 5; i++) {
            try {
                const r = await checkTx(log.id);
                if (r.status !== 'pending') {
                    ui.stage(r.status, r.status === 'confirmed' ? 'สำเร็จ ยืนยันบนเชนแล้ว' : `ธุรกรรมล้มเหลว: ${r.reason || 'สัญญาปฏิเสธ'}`, { hash: tx.hash });
                    return r;
                }
            } catch { /* ลองอีกรอบ */ }
            await new Promise((r) => setTimeout(r, 2500));
        }
    }
    const status = receipt ? (receipt.status === 1 ? 'confirmed' : 'failed') : 'pending';
    ui.stage(status, status === 'confirmed' ? 'สำเร็จ ยืนยันบนเชนแล้ว' : status === 'failed' ? 'ธุรกรรมล้มเหลว — สัญญาปฏิเสธ' : 'ยังรอยืนยัน — ดูผลในบันทึกการทำงาน', { hash: tx.hash });
    return { status };
}

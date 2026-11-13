/**
 * หน้า /binary — แผน Binary โปรโมชั่น: เชื่อมกระเป๋า → เซ็นข้อความ (เข้าระบบ หรือผูกผู้ชวน) → ฝาก USDT → ดูสองขาและรางวัล
 *
 * การเข้าระบบ/ผูกผู้ชวนเป็น personal_sign ไม่เสียค่าแก๊ส ไม่โอนเหรียญ
 * ธุรกรรมของหน้านี้มีสองอย่าง:
 *  1) ฝาก: โอน USDT (ERC-20 transfer) จากกระเป๋าของสมาชิกเองไปกระเป๋า Binary ของโครงการ
 *  2) รับ: claimMany บนสัญญา GridRewards ของแต่ละสาย (IDTC ที่ซื้อ / รางวัล USDT) — เหรียญเข้ากระเป๋าของสมาชิกเท่านั้น
 *   — ที่อยู่ปลายทางและสัญญา USDT มาจากเซิร์ฟเวอร์ (/binary/me) ทุกครั้ง · จำลองก่อนส่ง · ต้องติ๊กยืนยันก่อน
 *   — เซิร์ฟเวอร์ไม่แตะเงิน: อ่านรายการจากเชนเองภายหลัง หน้านี้แค่รอแล้วโหลดข้อมูลใหม่
 * ข้อความทุกตัวจาก lang/{th,en}/js.php (binary.*)
 */
import Alpine from 'alpinejs';
import { Contract, Interface } from 'ethers';
import { ABI, assertWallet, connectWallet, explorerTx, fmt, formatAmount, parseAmount, readProvider, sendTx, short, waitReceipt, walletError } from './chain';
import { localDate, t } from './i18n';

const USDT_DECIMALS = 6;
const ERC20 = new Interface(['function transfer(address to, uint256 amount) returns (bool)']);
const REWARDS = new Interface(['function claimMany(uint256[] roundIds, address account, uint256[] amounts, bytes32[][] proofs)']);

Alpine.data('binaryMember', (cfg = {}) => ({
    ref: cfg.ref || null,
    side: cfg.side || null, // ขาที่ลิงก์ชวนระบุ (L | R)
    me: null,
    account: null,
    busy: false,
    phase: '',
    error: '',
    flow: 0,
    copiedKey: '',
    savingSide: false,
    // ฝาก
    amount: '',
    agree: false,
    depositing: false,
    depMsg: '',
    depErr: '',
    depHash: '',
    balance: null, // bigint หน่วยเล็กสุดของ USDT
    // รับจากรอบจ่าย
    claiming: false,
    claimMsg: '',
    claimErr: '',
    claimHash: '',

    short,
    explorerTx,

    get refIsMe() {
        const mine = this.me?.address || this.account;
        return !!(mine && this.ref && mine.toLowerCase() === this.ref.toLowerCase());
    },

    init() {
        this.load();
    },

    async api(url, body) {
        const res = await fetch(url, {
            method: body ? 'POST' : 'GET',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });
        let data = {};
        try { data = await res.json(); } catch { /* ไม่ใช่ JSON (เช่น หน้า 419) */ }
        return { ok: res.ok, status: res.status, data };
    },

    async load() {
        try {
            const r = await this.api(cfg.routes.me);
            if (r.ok) { this.me = r.data; this.readBalance(); } else if (r.status !== 401) this.error = t('binary.failed');
        } catch {
            this.error = t('binary.failed');
        }
    },

    /** ยอด USDT ของกระเป๋าที่เข้าระบบ (อ่านผ่าน RPC ของเว็บ ไม่เปิดกระเป๋า) — อ่านไม่ได้ = ไม่แสดง */
    async readBalance() {
        if (!this.me?.usdt) return;
        try {
            this.balance = await new Contract(this.me.usdt, ABI.erc20, readProvider()).balanceOf(this.me.address);
        } catch { this.balance = null; }
    },

    /** purpose: 'login' | 'bind' */
    async signIn(purpose) {
        if (this.busy) return;
        const id = ++this.flow;
        this.busy = true;
        this.error = '';
        try {
            this.phase = t('binary.phase.connect');
            const { account, signer } = await connectWallet();
            if (id !== this.flow) return;
            this.account = account;
            if (purpose === 'bind' && this.refIsMe) purpose = 'login';

            const n = await this.api(cfg.routes.nonce, {
                address: account, purpose,
                referrer: purpose === 'bind' ? this.ref : undefined,
                side: purpose === 'bind' ? this.side || undefined : undefined,
            });
            if (!n.ok) { this.error = n.data.message || t('binary.failed'); return; }
            this.phase = t('binary.phase.sign');
            const signature = await signer.signMessage(n.data.message);
            if (id !== this.flow) return;

            this.phase = t('binary.phase.check');
            const v = await this.api(cfg.routes.verify, { message: n.data.message, signature });
            if (!v.ok && !v.data.signed_in) { this.error = v.data.message || t('binary.failed'); return; }
            if (!v.ok) this.error = v.data.message || '';
            if (v.data.bound) this.ref = null;
            await this.load();
        } catch (e) {
            this.error = walletError(e);
        } finally {
            if (id === this.flow) { this.busy = false; this.phase = ''; }
        }
    },

    // ───────────── ฝาก USDT ─────────────

    get parsed() { return parseAmount(this.amount, USDT_DECIMALS); },
    /** IDTC ที่ได้โดยประมาณ (ตัวเลขจริงตัดสินตอนระบบอ่านรายการจากเชน) */
    get willGet() {
        const price = Number(this.me?.plan?.price || 0);
        const u = this.parsed.units;
        return price > 0 && u ? Number(u) / 1e6 / price : 0;
    },
    /** เหตุที่ยังฝากไม่ได้ ('' = ฝากได้) */
    get depositBlock() {
        if (!this.me) return '';
        if (!this.me.open || !this.me.wallet || !this.me.usdt) return t('binary.dep.closed');
        const p = this.parsed;
        if (p.empty) return ' ';
        if (p.error) return p.error;
        if (Number(p.units) / 1e6 + 1e-9 < Number(this.me.plan.min_usdt)) return t('binary.dep.min', { min: fmt(this.me.plan.min_usdt) });
        if (this.willGet > Number(this.me.quota_left) + 1e-6) return t('binary.dep.quota');
        if (this.balance !== null && p.units > this.balance) return t('binary.dep.balance', { have: formatAmount(this.balance, USDT_DECIMALS, 2) });
        if (!this.agree) return t('binary.dep.agree');
        return '';
    },

    async deposit() {
        if (this.depositing || this.claiming || this.busy || this.depositBlock) return;
        const id = ++this.flow;
        const to = this.me.wallet;
        const token = this.me.usdt;
        const units = this.parsed.units;
        const before = this.me.deposits.length ? this.me.deposits[0].tx : null;
        this.depositing = true;
        this.depErr = '';
        this.depHash = '';
        try {
            this.depMsg = t('binary.dep.connect');
            const { account, signer } = await connectWallet();
            if (id !== this.flow) return;
            // ยอดนับให้กระเป๋าที่โอน — ต้องเป็นกระเป๋าเดียวกับที่เข้าระบบ
            if (account.toLowerCase() !== this.me.address.toLowerCase()) {
                this.depMsg = '';
                this.depErr = t('binary.dep.other_account', { a: short(this.me.address) });
                return;
            }
            this.depMsg = t('binary.dep.check');
            const data = ERC20.encodeFunctionData('transfer', [to, units]);
            try {
                await readProvider().call({ to: token, data, from: account });
            } catch {
                this.depMsg = '';
                this.depErr = t('binary.dep.rejected');
                return;
            }
            await assertWallet(account);
            if (id !== this.flow) return;
            this.depMsg = t('binary.dep.sign');
            const hash = await sendTx(signer, { to: token, data });
            this.depHash = hash;
            this.depMsg = t('binary.dep.pending');
            await waitReceipt(hash);
            if (id !== this.flow) return;
            this.depMsg = t('binary.dep.done', { n: formatAmount(units, USDT_DECIMALS, 2) });
            this.amount = '';
            this.agree = false;
            this.watch(before);
        } catch (e) {
            if (id !== this.flow) return;
            this.depMsg = '';
            this.depErr = e?.code === 'TIMEOUT' ? t('binary.dep.timeout') : walletError(e);
        } finally {
            if (id === this.flow) this.depositing = false;
        }
    },

    /** ตัวอ่านเชนของเว็บรันทุกนาที — โหลดซ้ำเป็นระยะจนเห็นรายการใหม่ (ไม่เกิน ~4 นาที) */
    watch(before, tries = 0) {
        clearTimeout(this._w);
        if (tries >= 16) return;
        const id = this.flow;
        this._w = setTimeout(async () => {
            if (id !== this.flow || !this.me) return;
            await this.load();
            if (id !== this.flow || !this.me) return;
            const latest = this.me.deposits.length ? this.me.deposits[0].tx : null;
            if (latest !== before) { this.depMsg = t('binary.dep.seen'); return; }
            this.watch(before, tries + 1);
        }, 15000);
    },

    // ───────────── รับจากรอบจ่าย (สัญญา GridRewards ของแต่ละสาย) ─────────────

    get hasClaims() {
        const c = this.me?.claims;
        return !!c && ['usdt', 'idtc'].some((k) => c[k]?.contract && c[k].items.length);
    },
    /** รอบที่รับได้ตอนนี้ของสาย kind ('usdt' | 'idtc') — claimed === null = อ่านไม่ได้ ไม่นับ */
    claimable(kind) { return (this.me?.claims?.[kind]?.items || []).filter((c) => c.claimed === false); },
    claimedOf(kind) { return (this.me?.claims?.[kind]?.items || []).filter((c) => c.claimed === true); },
    claimText(kind, list) {
        const sum = list.reduce((s, c) => s + Number(c.value || 0), 0);
        return kind === 'usdt' ? this.usd(sum) : this.idtc(sum);
    },

    async claim(kind) {
        const items = this.claimable(kind);
        const to = this.me?.claims?.[kind]?.contract;
        if (this.claiming || this.depositing || this.busy || !items.length || !to) return;
        const id = ++this.flow;
        this.claiming = true;
        this.claimErr = '';
        this.claimHash = '';
        try {
            this.claimMsg = t('binary.claim.connect');
            const { account, signer } = await connectWallet();
            if (id !== this.flow) return;
            // เหรียญโอนเข้ากระเป๋าที่เข้าระบบไว้เท่านั้น
            if (account.toLowerCase() !== this.me.address.toLowerCase()) {
                this.claimMsg = '';
                this.claimErr = t('binary.claim.other_account', { a: short(this.me.address) });
                return;
            }
            const data = REWARDS.encodeFunctionData('claimMany', [
                items.map((c) => BigInt(c.round)), this.me.address, items.map((c) => BigInt(c.amount)), items.map((c) => c.proof),
            ]);
            // จำลองก่อน — สัญญาจะปฏิเสธก็รู้เลย ไม่ต้องเปิดกระเป๋าเซ็น
            this.claimMsg = t('binary.claim.check');
            try {
                await readProvider().call({ to, data, from: account });
            } catch {
                this.claimMsg = '';
                this.claimErr = t('binary.claim.rejected');
                await this.load();
                return;
            }
            await assertWallet(account);
            if (id !== this.flow) return;
            this.claimMsg = t('binary.claim.sign');
            const hash = await sendTx(signer, { to, data });
            this.claimHash = hash;
            this.claimMsg = t('binary.claim.pending');
            await waitReceipt(hash);
            if (id !== this.flow) return;
            this.claimMsg = t('binary.claim.done', { n: this.claimText(kind, items), unit: kind === 'usdt' ? 'USDT' : 'IDTC' });
            await this.load();
        } catch (e) {
            if (id !== this.flow) return;
            this.claimMsg = '';
            this.claimErr = e?.code === 'TIMEOUT' ? t('binary.claim.timeout') : walletError(e);
        } finally {
            if (id === this.flow) this.claiming = false;
        }
    },

    async logout() {
        this.flow++;
        clearTimeout(this._w);
        await this.api(cfg.routes.logout, {});
        Object.assign(this, { me: null, account: null, busy: false, phase: '', depositing: false, depMsg: '', depErr: '', depHash: '', amount: '', agree: false, balance: null, claiming: false, claimMsg: '', claimErr: '', claimHash: '' });
    },

    async copyText(text, key) {
        try {
            await navigator.clipboard.writeText(text);
            this.copiedKey = key;
            clearTimeout(this._k);
            this._k = setTimeout(() => { this.copiedKey = ''; }, 1600);
        } catch { /* เบราว์เซอร์ไม่ให้คัดลอก — ผู้ใช้เลือกข้อความในช่องเองได้ */ }
    },

    /** ขาที่คนจากลิงก์ที่ไม่ระบุขาจะลง ('' = อัตโนมัติ) */
    async setSide(value) {
        if (this.savingSide || !this.me) return;
        const id = this.flow;
        this.savingSide = true;
        this.error = '';
        try {
            const r = await this.api(cfg.routes.side, { side: value });
            if (id !== this.flow || !this.me) return;
            if (r.ok) this.me.pref = r.data.pref || null;
            else { this.error = r.data.message || t('binary.failed'); await this.load(); }
        } catch {
            if (id === this.flow) this.error = t('binary.failed');
        } finally {
            this.savingSide = false;
        }
    },

    destroy() { clearTimeout(this._k); clearTimeout(this._w); this.flow++; },

    /** สัดส่วนยอดขาซ้ายในแถบสมดุล (0–100) · ยังไม่มียอด = 50 */
    get leftPct() {
        const l = Number(this.me?.mine?.left?.usdt || 0);
        const r = Number(this.me?.mine?.right?.usdt || 0);
        return l + r > 0 ? Math.round(l / (l + r) * 100) : 50;
    },
    usd: (v) => fmt(Math.floor((Number(v) || 0) * 100) / 100, 2),
    idtc: (v) => fmt(Math.floor(Number(v) || 0)),
    num: (v) => fmt(Number(v) || 0),
    bal() { return this.balance === null ? '—' : formatAmount(this.balance, USDT_DECIMALS, 2); },
    when: (ts) => (ts ? localDate(ts, true) : '—'),
    endsIn() {
        if (!this.me?.week?.ends_at) return '';
        const s = Math.max(0, this.me.week.ends_at - Math.floor(Date.now() / 1000));
        return t('binary.ends', { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600) });
    },
}));

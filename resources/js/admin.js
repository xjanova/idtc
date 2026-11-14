// หลังบ้าน IDTC (ระบบควบคุมเหรียญ) — Alpine components ของหน้า login และ app shell
import Alpine from 'alpinejs';
import { BrowserProvider, getAddress } from 'ethers';
import { CFG, connectWallet, injected, readProvider, short, walletError } from './chain';
import { ACTIONS, AdminError, adminError, api, runAction } from './admin-chain';
import './admin-map';
import './admin-bmap';
import './admin-tree';
import './qr';

const A = window.IDTC_ADMIN || {};

/* ───────────── stores: แจ้งเตือน + กล่องยืนยัน + ล็อกคำสั่งทั้งหน้า ───────────── */
Alpine.store('toast', {
    items: [],
    _id: 0,
    show(text, kind = '') {
        const id = ++this._id;
        this.items.push({ id, text, kind });
        setTimeout(() => { this.items = this.items.filter((t) => t.id !== id); }, 5000);
    },
    async copy(text) {
        try { await navigator.clipboard.writeText(text); this.show('คัดลอกแล้ว', 'ok'); } catch { this.show('คัดลอกไม่ได้ — เลือกข้อความแล้วคัดลอกเอง', 'err'); }
    },
});

Alpine.store('confirm', {
    open: false,
    title: '',
    lines: [],
    target: null,
    targetLabel: '',
    warnings: [],
    danger: false,
    needAck: false,
    ack: false,
    ackText: '',
    okText: 'ยืนยันและเปิดกระเป๋า',
    _resolve: null,
    ask({ title, lines = [], target = null, targetLabel = 'ที่อยู่ปลายทาง', warnings = [], danger = false, okText = null, wallet = true }) {
        this._resolve?.(false);
        Object.assign(this, {
            title, lines, target, targetLabel, warnings, danger, ack: false, open: true,
            needAck: danger,
            ackText: target ? 'ตรวจที่อยู่ปลายทางครบทุกตัวอักษรแล้ว และเข้าใจผลของคำสั่งนี้' : 'เข้าใจผลของคำสั่งนี้แล้ว',
            okText: okText || (wallet ? (danger ? 'ยืนยัน — เปิดกระเป๋าเซ็น' : 'ยืนยันและเปิดกระเป๋า') : 'ยืนยัน'),
        });
        setTimeout(() => document.querySelector('.modal [x-ref="cancelBtn"]')?.focus(), 60);
        return new Promise((resolve) => { this._resolve = resolve; });
    },
    answer(v) {
        this.open = false;
        const r = this._resolve; this._resolve = null; r?.(v);
    },
});

Alpine.store('tx', { busy: false });

/* ───────────── app shell: เมนูมือถือ · กระเป๋าที่เชื่อม · บล็อกล่าสุด ───────────── */
Alpine.data('adminShell', () => ({
    nav: false,
    account: null,
    wrongChain: false,
    block: A.head || null,
    short,
    get blockText() { return this.block ? Number(this.block).toLocaleString('en-US') : '—'; },
    init() {
        const eth = injected();
        if (eth) {
            eth.request({ method: 'eth_accounts' }).then((a) => { this.account = a?.[0] ? getAddress(a[0]) : null; }).catch(() => {});
            eth.request({ method: 'eth_chainId' }).then((c) => { this.wrongChain = parseInt(c, 16) !== Number(CFG.chain.id); }).catch(() => {});
            eth.on?.('accountsChanged', (a) => {
                this.account = a?.[0] ? getAddress(a[0]) : null;
                if (Alpine.store('tx').busy) Alpine.store('toast').show('บัญชีในกระเป๋าเปลี่ยน — คำสั่งที่กำลังทำจะถูกยกเลิกก่อนส่ง', 'err');
            });
            eth.on?.('chainChanged', (c) => { this.wrongChain = parseInt(c, 16) !== Number(CFG.chain.id); });
        }
        if (this.block) {
            this._iv = setInterval(() => {
                if (document.hidden) return;
                readProvider().getBlockNumber().then((b) => { this.block = b; }).catch(() => {});
            }, 15000);
        }
    },
    destroy() { clearInterval(this._iv); },
    async connect() {
        try {
            const { account } = await connectWallet();
            this.account = account; this.wrongChain = false;
        } catch (e) { Alpine.store('toast').show(walletError(e), 'err'); }
    },
}));

/* ───────────── เซ็นข้อความเข้าหลังบ้าน (ใช้ทั้งหน้าเข้าสู่ระบบและหน้าตั้งค่าครั้งแรก) ───────────── */
async function signAdminMessage(vm, eth, pick) {
    vm.step = vm.stepConnect; vm.msg = pick;
    const [acc] = await eth.request({ method: 'eth_requestAccounts' });
    if (!acc) throw new AdminError('ไม่ได้เลือกบัญชีในกระเป๋า');
    vm.account = getAddress(acc);

    const { message } = await api(A.routes.nonce, { address: vm.account });
    vm.step = vm.stepConnect + 1; vm.msg = 'กด "เซ็น" ในกระเป๋า — ไม่มีค่าธรรมเนียม และไม่มีการโอนเงิน';
    const signer = await new BrowserProvider(eth, 'any').getSigner(vm.account);
    const signature = await signer.signMessage(message);

    const [cur] = await eth.request({ method: 'eth_accounts' });
    if (!cur || cur.toLowerCase() !== vm.account.toLowerCase()) throw new AdminError('บัญชีในกระเป๋าเปลี่ยนระหว่างเซ็น กดเริ่มใหม่');

    return { message, signature };
}

/** ตรวจรหัสผ่านใหม่ฝั่งเบราว์เซอร์ก่อนส่ง (เซิร์ฟเวอร์ตรวจซ้ำเสมอ) — คืนข้อความผิดพลาดหรือ '' */
function passwordProblem(pw, pw2) {
    const rule = A.password || { min: 10, maxBytes: 72 };
    if ([...pw].length < rule.min) return `รหัสผ่านต้องยาวอย่างน้อย ${rule.min} ตัวอักษร`;
    if (new TextEncoder().encode(pw).length > rule.maxBytes) return 'รหัสผ่านยาวเกินไป (ไม่เกิน 72 ไบต์ — อักษรไทยราว 24 ตัว)';
    if (pw.trim() !== pw) return 'รหัสผ่านขึ้นต้นหรือลงท้ายด้วยช่องว่างไม่ได้';
    if (pw !== pw2) return 'ยืนยันรหัสผ่านไม่ตรงกัน';
    return '';
}

/* ───────────── หน้าเข้าสู่ระบบ: เชื่อมกระเป๋า → ขอ nonce → เซ็น → ตรวจ → รหัสผ่าน (ถ้าต้องใช้) ───────────── */
Alpine.data('adminLogin', () => ({
    step: 0,
    stepConnect: 1,
    busy: false,
    msg: '',
    kind: '',
    account: null,
    need: '', // '' | 'password' | 'set_password'
    pw: '',
    pw2: '',
    locked: 0,
    stepClass(n) { return { on: this.step === n, done: this.step > n }; },
    async login() {
        if (this.busy) return;
        const eth = injected();
        if (!eth) { this.fail(walletError({ code: 'NO_WALLET' })); return; }
        this.busy = true; this.kind = ''; this.msg = '';
        try {
            const signed = await signAdminMessage(this, eth, 'เปิดกระเป๋า… เลือกบัญชีที่มีสิทธิ์เข้าหลังบ้าน');
            this.step = 3; this.msg = 'กำลังตรวจลายเซ็นและสิทธิ์…';
            const res = await api(A.routes.verify, signed);
            if (res.next) { this.askPassword(res); return; }
            this.done(res.redirect);
        } catch (e) {
            this.fail(e instanceof AdminError ? e.message : walletError(e));
        } finally {
            if (this.step !== 5) this.busy = false;
        }
    },
    askPassword(res) {
        this.need = res.next; this.step = 4; this.pw = ''; this.pw2 = '';
        this.kind = res.locked ? 'err' : 'ok'; this.msg = res.message || '';
        this.lockFor(res.locked || 0);
        setTimeout(() => this.$refs.pw?.focus(), 60);
    },
    async sendPassword() {
        if (this.busy || this.locked > 0) return;
        if (this.need === 'set_password') {
            const bad = passwordProblem(this.pw, this.pw2);
            if (bad) { this.kind = 'err'; this.msg = bad; return; }
        } else if (!this.pw) { this.kind = 'err'; this.msg = 'ใส่รหัสผ่านหลังบ้าน'; return; }
        this.busy = true; this.kind = ''; this.msg = 'กำลังตรวจรหัสผ่าน…';
        try {
            const res = await api(A.routes.password, { password: this.pw, password_confirmation: this.pw2 });
            this.pw = ''; this.pw2 = '';
            this.done(res.redirect);
        } catch (e) {
            this.pw = '';
            const code = e?.data?.code;
            if (code === 'expired') { this.restart(); this.fail(e.message); return; }
            if (code === 'locked') this.lockFor(e.data.locked || 900);
            this.kind = 'err'; this.msg = e instanceof AdminError ? e.message : 'ตรวจรหัสผ่านไม่ได้ ลองใหม่อีกครั้ง';
            setTimeout(() => this.$refs.pw?.focus(), 60);
        } finally {
            if (this.step !== 5) this.busy = false;
        }
    },
    lockFor(seconds) {
        clearTimeout(this._lock);
        this.locked = seconds;
        if (seconds > 0) this._lock = setTimeout(() => { this.locked = 0; }, seconds * 1000);
    },
    restart() { this.need = ''; this.pw = ''; this.pw2 = ''; this.step = 0; this.msg = ''; this.kind = ''; this.lockFor(0); },
    done(url) { this.step = 5; this.kind = 'ok'; this.msg = 'เข้าสู่ระบบแล้ว กำลังพาไปหน้าหลังบ้าน…'; location.assign(url || '/admin'); },
    fail(m) { this.step = 0; this.kind = 'err'; this.msg = m; },
    destroy() { clearTimeout(this._lock); },
}));

/* ───────────── ตั้งค่าครั้งแรก: รหัสผ่าน → เชื่อมกระเป๋า → เซ็น → เป็นเจ้าของ ───────────── */
Alpine.data('adminSetup', () => ({
    step: 1,
    stepConnect: 2,
    busy: false,
    msg: '',
    kind: '',
    account: null,
    pw: '',
    pw2: '',
    ack: false,
    stepClass(n) { return { on: this.step === n, done: this.step > n }; },
    async run() {
        if (this.busy) return;
        const bad = passwordProblem(this.pw, this.pw2);
        if (bad) { this.kind = 'err'; this.msg = bad; return; }
        if (!this.ack) { this.kind = 'err'; this.msg = 'ติ๊กยืนยันก่อน'; return; }
        const eth = injected();
        if (!eth) { this.kind = 'err'; this.msg = walletError({ code: 'NO_WALLET' }); return; }
        this.busy = true; this.kind = ''; this.msg = '';
        try {
            const signed = await signAdminMessage(this, eth, 'เปิดกระเป๋า… เลือกบัญชีที่จะเป็นเจ้าของหลังบ้าน');
            this.step = 4; this.msg = 'กำลังบันทึกเป็นเจ้าของ…';
            const res = await api(A.routes.setup, { ...signed, password: this.pw, password_confirmation: this.pw2, ack: true });
            this.pw = ''; this.pw2 = '';
            this.step = 5; this.kind = 'ok'; this.msg = 'ตั้งค่าเสร็จแล้ว กำลังพาเข้าหลังบ้าน…';
            location.assign(res.redirect || '/admin');
        } catch (e) {
            this.step = 1; this.kind = 'err';
            this.msg = e?.status === 404 ? 'มีผู้ตั้งค่าเจ้าของไปแล้ว — โหลดหน้าใหม่เพื่อเข้าสู่ระบบตามปกติ' : (e instanceof AdminError ? e.message : walletError(e));
        } finally {
            if (this.step !== 5) this.busy = false;
        }
    },
}));

/* ───────────── หน้า "ผู้ดูแล": ติ๊กสิทธิ์ ดู/แก้ รายเมนู (ชุดสำเร็จรูปเติมช่องให้) ───────────── */
Alpine.data('permEditor', (role = 'viewer', perms = {}, presets = {}) => ({
    owner: role === 'owner',
    p: { ...perms }, // ส่วน => 'view' | 'edit' (ไม่มี key = ไม่มีสิทธิ์)
    view(s) { return !!this.p[s]; },
    edit(s) { return this.p[s] === 'edit'; },
    setView(s, on) { if (on) this.p[s] = this.p[s] || 'view'; else delete this.p[s]; },
    setEdit(s, on) { if (on) this.p[s] = 'edit'; else if (this.p[s]) this.p[s] = 'view'; },
    is(name) {
        const ref = presets[name] || {};
        const a = Object.keys(ref), b = Object.keys(this.p);
        return a.length === b.length && a.every((k) => this.p[k] === ref[k]);
    },
    preset(name) {
        if (name === 'owner') { this.owner = true; return; }
        this.owner = false;
        this.p = name === 'none' ? {} : { ...(presets[name] || {}) };
    },
}));

/* ───────────── คำสั่งบนเชน (หนึ่งฟอร์มต่อหนึ่งคำสั่ง) ───────────── */
Alpine.data('chainAction', (name, init = {}) => ({
    f: { ...init },
    busy: false,
    state: '',
    msg: '',
    hash: '',
    get explorer() { return this.hash ? `${CFG.chain.explorer}/tx/${this.hash}` : ''; },
    get working() { return ['wallet', 'check', 'confirm', 'sign', 'pending'].includes(this.state); },
    async run() {
        if (this.busy) return;
        if (Alpine.store('tx').busy) { this.state = 'error'; this.msg = 'มีคำสั่งอื่นกำลังทำอยู่ รอให้เสร็จก่อน'; return; }
        if (!ACTIONS[name]) { this.state = 'error'; this.msg = 'ไม่รู้จักคำสั่งนี้'; return; }
        this.busy = true; Alpine.store('tx').busy = true;
        this.state = ''; this.msg = ''; this.hash = '';
        try {
            const r = await runAction(name, this.f, {
                stage: (state, msg, extra = {}) => { this.state = state; this.msg = msg; if (extra.hash) this.hash = extra.hash; },
            }, (box) => Alpine.store('confirm').ask(box));
            if (r?.status === 'confirmed') {
                Alpine.store('toast').show('สำเร็จ — กำลังโหลดค่าล่าสุดจากเชน', 'ok');
                setTimeout(() => location.reload(), 2500);
            }
        } catch (e) {
            this.state = 'error';
            this.msg = adminError(e);
        } finally {
            this.busy = false; Alpine.store('tx').busy = false;
        }
    },
}));

/* ───────────── ฟอร์มธรรมดา: กันกดซ้ำ + ยืนยันก่อนลบ ───────────── */
Alpine.data('safeForm', (question = '', lines = [], danger = false) => ({
    busy: false,
    async submit(e) {
        if (this.busy) { e.preventDefault(); return; }
        if (question) {
            e.preventDefault();
            // ฟอร์มที่ระบุ data-target-field: ทวนค่าที่กรอก (เช่น ที่อยู่สัญญาใหม่) ในกล่องยืนยัน
            const field = e.target.dataset.targetField;
            const value = field ? String(e.target.elements[field]?.value || '').trim() : '';
            const target = field ? (value || '(ว่าง — ล้างค่าที่ตั้งจากหลังบ้าน)') : null;
            const ok = await Alpine.store('confirm').ask({ title: question, lines, danger, target, targetLabel: e.target.dataset.targetLabel || 'ค่าใหม่', wallet: false, okText: danger ? 'ยืนยันลบ' : 'ยืนยัน' });
            if (!ok) return;
            this.busy = true;
            e.target.submit();
            return;
        }
        this.busy = true;
    },
}));

/* ───────────── รายชื่อกระเป๋าโครงการ (เพิ่ม/ลบแถวก่อนบันทึก) ───────────── */
Alpine.data('walletList', (rows = [], max = 20) => ({
    rows: rows.length ? rows.map((r) => ({ ...r })) : [{ label: '', address: '' }],
    max,
    add() { if (this.rows.length < this.max) this.rows.push({ label: '', address: '' }); },
    remove(i) { this.rows.splice(i, 1); if (!this.rows.length) this.rows.push({ label: '', address: '' }); },
}));

window.Alpine = Alpine;
Alpine.start();

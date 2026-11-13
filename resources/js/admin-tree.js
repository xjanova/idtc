/**
 * หลังบ้าน > Binary > ผังสายงาน — ดูผัง · จัดสายจากคิว · ย้ายสายพร้อมทีม · ต่อสาย · ตั้งวิธีวาง · เปลี่ยนผู้แนะนำ (owner)
 *
 * ขั้นตอนย้าย/จัด/ต่อสาย: เลือกกระเป๋า → แตะช่องว่างในผัง (หรือพิมพ์ที่อยู่เอง) → ระบบตรวจ → เขียนเหตุผล → ยืนยัน
 * เซิร์ฟเวอร์ตรวจซ้ำในล็อกเดียวกับที่บันทึกทุกครั้ง ผลตรวจบนหน้านี้มีไว้ให้แอดมินเห็นก่อนกดเท่านั้น
 * ไม่มีธุรกรรมบนเชน — ผังอยู่ในฐานข้อมูลของเว็บ
 */
import Alpine from 'alpinejs';
import { isAddress } from 'ethers';
import { api, thaiTime } from './admin-chain';
import { short } from './chain';

const isAddr = (a) => typeof a === 'string' && /^0x[0-9a-fA-F]{40}$/.test(a.trim()) && isAddress(a.trim());
const same = (a, b) => !!a && !!b && a.toLowerCase() === b.toLowerCase();
const legText = (leg) => (leg === 'L' ? 'ขาซ้าย' : leg === 'R' ? 'ขาขวา' : '—');
const usd = (v) => (Math.floor((Number(v) || 0) * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => (Number(v) || 0).toLocaleString('en-US');
const blankMove = () => ({ wallet: '', parent: '', leg: 'L', reason: '', check: null, checking: false, busy: false });
const blankRef = () => ({ referrer: '', reason: '', check: null, checking: false, busy: false });

Alpine.data('binaryTree', (cfg = {}) => ({
    cfg,
    win: null,      // ผังรอบ root: { root, up, crumbs, edge, levels }
    focus: null,    // รายละเอียดของ root จากเซิร์ฟเวอร์
    sel: null,      // กระเป๋าที่เลือกในผัง (ข้อมูลจากช่องในผัง)
    depth: 3,
    q: '',
    loading: false,
    err: '',
    tab: 'queue',
    lists: { queue: [], outside: [], history: [] },
    listsBusy: false,
    listsErr: '',
    filter: '',
    mv: blankMove(),
    rf: blankRef(),
    autoMode: 'weak',
    autoBusy: '',
    modeBusy: false,
    _seq: 0,
    _cseq: 0,
    _rseq: 0,

    short,
    same,
    usd,
    num,
    legText,
    when: (ts) => (ts ? thaiTime(ts) : '—'),

    init() {
        this.load(cfg.root || null);
        this.loadLists();
    },

    get levels() {
        return (this.win?.levels || []).filter((row) => row.some((c) => c));
    },
    get cols() {
        const l = this.levels;
        return l.length ? l[l.length - 1].length : 1;
    },
    /** โหมดเลือกช่อง: มีกระเป๋าที่จะจัด/ย้ายแล้ว รอแตะช่องว่าง */
    get arming() {
        return cfg.canEdit && isAddr(this.mv.wallet);
    },

    // ───────────── โหลดผัง + รายการ ─────────────

    async load(root = null) {
        const id = ++this._seq;
        this.loading = true;
        this.err = '';
        try {
            const url = new URL(cfg.routes.data, location.origin);
            if (root) url.searchParams.set('root', root);
            url.searchParams.set('depth', String(this.depth));
            const d = await api(url.toString(), null, 'GET');
            if (id !== this._seq) return;
            this.win = d.window || null;
            this.focus = d.focus || null;
            const top = this.win?.levels?.[0]?.[0] || null;
            // คงกระเป๋าที่เลือกไว้ถ้ายังอยู่ในผังใหม่
            const keep = this.sel && this.findCell(this.sel.wallet);
            this.sel = keep || top;
            this.rf = blankRef();
            if (this.win) {
                const u = new URL(location.href);
                u.searchParams.set('root', this.win.root);
                history.replaceState(null, '', u);
            }
        } catch (e) {
            if (id === this._seq) this.err = e.message;
        } finally {
            if (id === this._seq) this.loading = false;
        }
    },

    async loadLists() {
        if (this.listsBusy) return;
        this.listsBusy = true;
        this.listsErr = '';
        try {
            this.lists = await api(cfg.routes.lists, null, 'GET');
        } catch (e) {
            this.listsErr = e.message;
        } finally {
            this.listsBusy = false;
        }
    },

    reload() {
        this.load(this.win?.root || null);
        this.loadLists();
    },

    go() {
        const a = this.q.trim();
        if (!isAddr(a)) { Alpine.store('toast').show('ที่อยู่กระเป๋าไม่ถูกต้อง (0x + 40 ตัวอักษร)', 'err'); return; }
        this.load(a);
    },

    findCell(wallet) {
        for (const row of this.win?.levels || []) {
            for (const c of row) if (c && !c.empty && same(c.wallet, wallet)) return c;
        }
        return null;
    },

    filtered(list) {
        const f = this.filter.trim().toLowerCase();
        if (!f) return list;
        return list.filter((r) => [r.wallet, r.referrer, r.to_parent, r.from_parent, r.reason].some((v) => v && String(v).toLowerCase().includes(f)));
    },

    // ───────────── เลือกในผัง ─────────────

    pick(c) {
        this.sel = c;
        this.rf = blankRef();
    },

    /** แตะช่องว่าง: ใช้เป็นปลายทางของกระเป๋าที่กำลังจัด/ย้าย */
    slot(c) {
        if (!cfg.canEdit) return;
        if (!this.arming) {
            Alpine.store('toast').show('เลือกกระเป๋าที่จะจัด/ย้ายก่อน — ปุ่ม "ย้ายกระเป๋านี้" หรือ "เลือกช่อง" ในคิว', 'err');
            return;
        }
        this.mv.parent = c.parent;
        this.mv.leg = c.leg;
        this.check();
    },

    /** เริ่มจัด/ย้าย/ต่อสายกระเป๋านี้ — ถ้ามีผู้ชวน เปิดผังของผู้ชวนให้เห็นช่องในทีมเขา */
    startMove(wallet, openAt = null) {
        if (!cfg.canEdit) return;
        this.mv = { ...blankMove(), wallet };
        if (openAt && !this.findCell(openAt)) this.load(openAt);
        Alpine.store('toast').show('แตะช่องว่าง (+) ในผังเพื่อเลือกตำแหน่ง — หรือพิมพ์ที่อยู่ในช่อง "วางใต้"', 'ok');
        this.$nextTick(() => document.getElementById('treeMove')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    },

    cancelMove() {
        this._cseq++;
        this.mv = blankMove();
    },

    // ───────────── ตรวจ + บันทึกการจัด/ย้าย/ต่อสาย ─────────────

    async check() {
        const { wallet, parent, leg } = this.mv;
        const id = ++this._cseq;
        this.mv.check = null;
        if (!isAddr(wallet) || !isAddr(parent) || !['L', 'R'].includes(leg)) return;
        this.mv.checking = true;
        try {
            const d = await api(cfg.routes.check, { wallet: wallet.trim(), parent: parent.trim(), leg });
            if (id === this._cseq) this.mv.check = d;
        } catch (e) {
            if (id === this._cseq) this.mv.check = { ok: false, error: e.message, warnings: [], info: {} };
        } finally {
            if (id === this._cseq) this.mv.checking = false;
        }
    },

    moveLines(i, reason) {
        const lines = [];
        if (i.kind === 'move') {
            lines.push(`ย้าย ${short(i.wallet)} จาก${legText(i.from_leg)}ของ ${short(i.from_parent)}`);
            lines.push(`ไป${legText(i.to_leg)}ของ ${short(i.to_parent)}`);
        } else {
            lines.push(`จัด/ต่อสาย ${short(i.wallet)} ลง${legText(i.to_leg)}ของ ${short(i.to_parent)}`);
        }
        if (i.team > 1) lines.push(`ทีมใต้กระเป๋านี้ไปด้วยทั้งหมด — รวม ${num(i.team)} กระเป๋า`);
        lines.push(`มีผลตั้งแต่สัปดาห์ขายที่ ${i.week} (เริ่ม ${thaiTime(i.week_start)}) — สัปดาห์ที่ปิดแล้วคิดด้วยผังเดิม`);
        lines.push(`เหตุผล: ${reason}`);
        return lines;
    },

    async submitMove() {
        const m = this.mv;
        if (m.busy || m.checking || !m.check?.ok) return;
        const reason = m.reason.trim();
        if (reason.length < 4 || reason.length > 300) {
            Alpine.store('toast').show('เขียนเหตุผล 4–300 ตัวอักษร (ขึ้นในประวัติการย้าย)', 'err');
            return;
        }
        const i = m.check.info;
        const ok = await Alpine.store('confirm').ask({
            title: i.kind === 'move' ? 'ย้ายสาย?' : 'จัดสาย / ต่อสาย?',
            lines: this.moveLines(i, reason),
            warnings: m.check.warnings || [],
            danger: i.kind === 'move' && i.team > 1,
            wallet: false,
            okText: 'ยืนยันบันทึก',
        });
        if (!ok || this.mv !== m) return;
        m.busy = true;
        try {
            const d = await api(cfg.routes.place, { wallet: m.wallet.trim(), parent: m.parent.trim(), leg: m.leg, reason });
            Alpine.store('toast').show(d.message || 'บันทึกแล้ว', 'ok');
            const at = m.wallet.trim();
            this.mv = blankMove();
            this.sel = null;
            await this.load(this.win?.root || null);
            this.sel = this.findCell(at) || this.sel;
            this.loadLists();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'err');
            m.busy = false;
            this.check();
        }
    },

    // ───────────── คิวรอจัดสาย: ให้ระบบเลือกช่อง ─────────────

    async autoPlace(q) {
        if (!cfg.canEdit || this.autoBusy) return;
        const ok = await Alpine.store('confirm').ask({
            title: 'ให้ระบบจัดสาย?',
            lines: [
                `${short(q.wallet)} → ในทีมของผู้ชวน ${short(q.referrer)}`,
                `วิธีวาง: ${cfg.modes[this.autoMode] || this.autoMode}`,
                'มีผลตั้งแต่สัปดาห์ขายปัจจุบัน',
            ],
            wallet: false,
            okText: 'ยืนยันจัดสาย',
        });
        if (!ok) return;
        this.autoBusy = q.wallet;
        try {
            const d = await api(cfg.routes.auto, { wallet: q.wallet, mode: this.autoMode });
            Alpine.store('toast').show(d.message || 'จัดสายแล้ว', 'ok');
            if (same(this.mv.wallet, q.wallet)) this.mv = blankMove();
            this.reload();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'err');
            this.loadLists();
        } finally {
            this.autoBusy = '';
        }
    },

    // ───────────── วิธีวางคนใหม่ของสมาชิก ─────────────

    async setMode(wallet, mode) {
        if (!cfg.canEdit || this.modeBusy || !mode) return;
        this.modeBusy = true;
        try {
            const d = await api(cfg.routes.mode, { wallet, mode });
            Alpine.store('toast').show(d.message || 'บันทึกแล้ว', 'ok');
            const c = this.findCell(wallet);
            if (c) c.mode = mode;
            if (this.focus && same(this.focus.wallet, wallet)) this.focus.mode = mode;
        } catch (e) {
            Alpine.store('toast').show(e.message, 'err');
        } finally {
            this.modeBusy = false;
        }
    },

    // ───────────── เปลี่ยนผู้แนะนำ (owner) ─────────────

    async checkRef() {
        const id = ++this._rseq;
        this.rf.check = null;
        if (!this.sel || !isAddr(this.rf.referrer)) return;
        this.rf.checking = true;
        try {
            const d = await api(cfg.routes.referrerCheck, { wallet: this.sel.wallet, referrer: this.rf.referrer.trim() });
            if (id === this._rseq) this.rf.check = d;
        } catch (e) {
            if (id === this._rseq) this.rf.check = { ok: false, error: e.message, warnings: [], info: {} };
        } finally {
            if (id === this._rseq) this.rf.checking = false;
        }
    },

    async submitRef() {
        const r = this.rf;
        if (!cfg.owner || !this.sel || r.busy || r.checking || !r.check?.ok) return;
        const reason = r.reason.trim();
        if (reason.length < 8 || reason.length > 300) {
            Alpine.store('toast').show('เขียนเหตุผล 8–300 ตัวอักษร', 'err');
            return;
        }
        const i = r.check.info;
        const ok = await Alpine.store('confirm').ask({
            title: 'เปลี่ยนผู้แนะนำ?',
            lines: [
                `กระเป๋า ${short(i.wallet)}`,
                `ผู้แนะนำเดิม ${i.from_referrer ? short(i.from_referrer) : 'ไม่มี'} → ใหม่ ${short(i.to_referrer)}`,
                'การซื้อ/ฝากก่อนหน้านี้ยังนับให้สายเดิม — สายใหม่ได้ตั้งแต่การซื้อหลังจากนี้',
                `เพื่อนตรงในขาของแผน Binary: นับตามผู้แนะนำใหม่ตั้งแต่สัปดาห์ขายที่ ${i.week}`,
                `เหตุผล: ${reason}`,
            ],
            target: i.to_referrer,
            targetLabel: 'ผู้แนะนำใหม่',
            warnings: r.check.warnings || [],
            danger: true,
            wallet: false,
            okText: 'ยืนยันเปลี่ยนผู้แนะนำ',
        });
        if (!ok || this.rf !== r) return;
        r.busy = true;
        try {
            const d = await api(cfg.routes.referrer, { wallet: i.wallet, referrer: i.to_referrer, reason });
            Alpine.store('toast').show(d.message || 'บันทึกแล้ว', 'ok');
            this.rf = blankRef();
            this.reload();
        } catch (e) {
            Alpine.store('toast').show(e.message, 'err');
            r.busy = false;
            this.checkRef();
        }
    },

    // ───────────── ลิงก์ชวน + QR ของกระเป๋าที่เลือก ─────────────

    refLink(wallet, side = '') {
        if (!wallet || !cfg.refBase) return '';
        const base = cfg.refBase.replace(/0x0{40}/i, wallet);
        return side ? `${base}/${side}` : base;
    },

    showQr(wallet, side = '') {
        const label = side === 'l' ? 'ลิงก์ชวน Binary — ขาซ้าย' : side === 'r' ? 'ลิงก์ชวน Binary — ขาขวา' : 'ลิงก์ชวน Binary';
        Alpine.store('qr').show(this.refLink(wallet, side), `${label} · ${short(wallet)}`, `idtc-binary-${wallet.slice(2, 8).toLowerCase()}${side ? '-' + side : ''}`);
    },

    memberUrl(wallet) {
        return cfg.routes.member.replace('__W__', wallet);
    },

    kindText(h) {
        if (h.kind === 'referrer') return 'เปลี่ยนผู้แนะนำ';
        return h.kind === 'move' ? 'ย้ายสาย' : 'จัด/ต่อสาย';
    },
    roleText(r) {
        return { owner: 'เจ้าของ', admin: 'แอดมิน', member: 'แม่ทีม' }[r] || r || '—';
    },
}));

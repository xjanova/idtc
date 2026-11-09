/**
 * หน้า /grid — สมาชิก IDTC Grid: เชื่อมกระเป๋า → เซ็นข้อความ (เข้าระบบ หรือผูกผู้ชวน) → ดูลิงก์ ระดับ ทีม และรางวัล
 *
 * ไม่มีธุรกรรมบนเชน: การเซ็นเป็น personal_sign ไม่เสียค่าแก๊ส ไม่โอนเหรียญ
 * เซิร์ฟเวอร์ออกข้อความให้เซ็น (nonce ใช้ครั้งเดียว) แล้วตรวจลายเซ็นเอง · ข้อความทุกตัวจาก lang/{th,en}/js.php
 */
import Alpine from 'alpinejs';
import { connectWallet, fmt, short, walletError } from './chain';
import { localDate, t } from './i18n';

Alpine.data('gridMember', (cfg = {}) => ({
    ref: cfg.ref || null,
    ranks: cfg.ranks || {},
    me: null,
    account: null,
    busy: false,
    phase: '',
    error: '',
    copied: false,
    flow: 0,
    canShare: typeof navigator !== 'undefined' && typeof navigator.share === 'function',

    short,

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
            if (r.ok) this.me = r.data;
            else if (r.status !== 401) this.error = t('grid.failed');
        } catch {
            this.error = t('grid.failed');
        }
    },

    /** purpose: 'login' | 'bind' */
    async signIn(purpose) {
        if (this.busy) return;
        const id = ++this.flow;
        this.busy = true;
        this.error = '';
        try {
            this.phase = t('grid.phase.connect');
            const { account, signer } = await connectWallet();
            if (id !== this.flow) return;
            this.account = account;
            if (purpose === 'bind' && this.refIsMe) purpose = 'login';

            const n = await this.api(cfg.routes.nonce, { address: account, purpose, referrer: purpose === 'bind' ? this.ref : undefined });
            if (!n.ok) {
                this.error = n.data.message || t('grid.failed');
                // ผูกไม่ได้ (ซื้อแล้ว/ผูกแล้ว/ลิงก์ของตัวเอง) — ยังเข้าระบบปกติได้ด้วยปุ่มข้าม
                return;
            }
            this.phase = t('grid.phase.sign');
            const signature = await signer.signMessage(n.data.message);
            if (id !== this.flow) return;

            this.phase = t('grid.phase.check');
            const v = await this.api(cfg.routes.verify, { message: n.data.message, signature });
            if (!v.ok && !v.data.signed_in) {
                this.error = v.data.message || t('grid.failed');
                return;
            }
            if (!v.ok) this.error = v.data.message || '';
            if (v.data.bound) this.ref = null;
            await this.load();
        } catch (e) {
            this.error = walletError(e);
        } finally {
            if (id === this.flow) { this.busy = false; this.phase = ''; }
        }
    },

    async logout() {
        this.flow++;
        await this.api(cfg.routes.logout, {});
        this.me = null;
        this.account = null;
        this.busy = false;
        this.phase = '';
    },

    async copy() {
        try {
            await navigator.clipboard.writeText(this.me.link);
            this.copied = true;
            clearTimeout(this._c);
            this._c = setTimeout(() => { this.copied = false; }, 1600);
        } catch { /* เบราว์เซอร์ไม่ให้คัดลอก — ผู้ใช้เลือกข้อความในช่องเองได้ */ }
    },

    async share() {
        try { await navigator.share({ title: 'IDTC Grid', text: t('grid.share_text'), url: this.me.link }); } catch { /* ผู้ใช้ปิดหน้าต่างแชร์ */ }
    },

    destroy() { clearTimeout(this._c); this.flow++; },

    idtc: (v) => fmt(Math.floor(Number(v) || 0)),
    usd: (v) => `$${fmt(Number(v) || 0, 2)}`,
    num: (v) => fmt(Number(v) || 0),
    when: (ts) => localDate(ts, true),
    status: (s) => t(`grid.status.${s}`),
    kind(x) { return x.kind === 'buyer' ? t('grid.kind_buyer') : t('grid.kind_ring', { n: x.ring }); },
    needLabel(n) {
        const have = n.k === 'directs' ? fmt(n.have) : fmt(n.have, 2);
        const need = n.k === 'directs' ? fmt(n.need) : fmt(n.need);
        return t(`grid.need_${n.k}`, { have, need });
    },
    endsIn() {
        if (!this.me?.sprint?.ends_at) return '';
        const s = Math.max(0, this.me.sprint.ends_at - Math.floor(Date.now() / 1000));
        return t('grid.ends', { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600) });
    },
}));

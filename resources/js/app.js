import Alpine from 'alpinejs';
import { addNetworkAndToken, connectWallet, injected, isMobile, reader, short, units, walletError, fmt, CFG } from './chain';
import { chosenLocale, pageUrl, rememberLocale, t } from './i18n';
import './presale';
import './trade';
import './bridge';
import './transparency';
import './whitepaper';

const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
const B = 1e9;
const shortNum = (n) => (n >= 1e12 ? `${(n / 1e12).toFixed(2).replace(/\.?0+$/, '')}T` : n >= 1e9 ? `${fmt(n / 1e9)}B` : fmt(n));

/**
 * รอให้กระเป๋าฉีด window.ethereum — เบราว์เซอร์ในแอปกระเป๋าบางตัว (Trust, Coinbase, OKX บางรุ่น)
 * ฉีดช้ากว่าตอนที่สคริปต์หน้าเว็บเริ่มทำงาน
 */
function whenEthereum(timeout = 3000) {
    if (injected()) return Promise.resolve(injected());
    return new Promise((resolve) => {
        let done = false;
        const finish = () => { if (done) return; done = true; clearInterval(iv); clearTimeout(to); resolve(injected()); };
        window.addEventListener('ethereum#initialized', finish, { once: true });
        const iv = setInterval(() => injected() && finish(), 200);
        const to = setTimeout(finish, timeout);
    });
}

/* ───────────── site shell: wallet + add network ───────────── */
Alpine.data('site', () => ({
    account: null,
    menu: false,
    notice: '',
    noticeKind: '',
    short,
    init() {
        // หน้าอื่น (presale/ความโปร่งใส) เชื่อมกระเป๋าเองได้ — ให้ปุ่มบนแถบเมนูตามทัน
        window.addEventListener('idtc:account', (e) => { this.account = e.detail || null; });
        const auto = document.body.dataset.autoAdd === '1' && (isMobile() || new URLSearchParams(location.search).has('auto'));
        whenEthereum(auto ? 4000 : 1500).then((eth) => {
            if (!eth) return;
            eth.request({ method: 'eth_accounts' }).then((a) => { this.account = a?.[0] || null; }).catch(() => {});
            eth.on?.('accountsChanged', (a) => { this.account = a?.[0] || null; window.dispatchEvent(new CustomEvent('idtc:account', { detail: this.account })); });
            // เปิด /add-network ในเบราว์เซอร์ของแอปกระเป๋า (deep link) → เพิ่มเชนให้ทันที
            // บนคอมไม่เด้งเอง (ต้องเติม ?auto=1) — กระเป๋า extension เด้งถามโดยไม่ได้กดจะดูน่าตกใจ
            if (auto) setTimeout(() => this.addNetwork(), 600);
        });
    },
    say(msg, kind = '') {
        this.notice = msg; this.noticeKind = kind;
        clearTimeout(this._t); this._t = setTimeout(() => { this.notice = ''; }, 7000);
    },
    async connect() {
        try {
            const { account } = await connectWallet();
            this.account = account;
            window.dispatchEvent(new CustomEvent('idtc:account', { detail: account }));
        } catch (e) {
            if (e?.code === 'NO_WALLET' && isMobile()) { location.href = pageUrl('addNetwork', '/add-network'); return; }
            this.say(walletError(e), 'err');
        }
    },
    async addNetwork() {
        if (!injected()) {
            if (isMobile()) { location.href = pageUrl('addNetwork', '/add-network'); return; }
            document.getElementById('addnet')?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth' });
            this.say(t('site.no_wallet'), 'err');
            return;
        }
        try {
            const r = await addNetworkAndToken();
            this.say(r.token ? t('site.added_both') : t('site.added_chain'), 'ok');
        } catch (e) { this.say(walletError(e), 'err'); }
    },
    /** ปุ่ม TH | EN: จำภาษาที่เลือกไว้ในคุกกี้ (เซิร์ฟเวอร์ใช้ตอนเข้าเว็บครั้งถัดไป) และพาไปส่วนเดิมของหน้า */
    pickLang(code, e) {
        rememberLocale(code);
        if (location.hash && e?.currentTarget?.href) {
            e.preventDefault();
            location.href = e.currentTarget.href.split('#')[0] + location.hash;
        }
    },
}));

/* ───────────── "Switch to English?" — หน้าไทยเท่านั้น, ไม่ redirect เอง ───────────── */
Alpine.data('langHint', () => ({
    show: false,
    init() {
        // ไม่ชวนซ้ำถ้าเคยเลือกภาษาแล้ว หรือเบราว์เซอร์ตั้งภาษาไทยไว้ (navigator.languages = Accept-Language)
        const langs = navigator.languages?.length ? navigator.languages : [navigator.language || ''];
        if (chosenLocale() || langs.some((l) => /^th\b/i.test(l))) return;
        setTimeout(() => { this.show = true; }, 900);
    },
    pick() { rememberLocale('en'); },
    dismiss() { rememberLocale('th'); this.show = false; },
}));

/* ───────────── hero slider ───────────── */
Alpine.data('heroSlider', (count) => ({
    index: 0,
    count,
    paused: false,
    duration: 7000,
    cycle: 0,
    _t: null,
    _left: 7000,
    _started: 0,
    _x: null,
    init() {
        // ภาพสำหรับมือถือ (ไฟล์เล็กกว่า) — สลับครั้งเดียวตอนโหลด
        if (matchMedia('(max-width: 760px)').matches) {
            this.$root.querySelectorAll('.slide-bg[data-mobile]').forEach((el) => { el.style.backgroundImage = `url('${el.dataset.mobile}')`; });
        }
        // โหลดภาพที่เหลือล่วงหน้าหลังหน้าแรกเสร็จ กันจอดำตอนสลับครั้งแรก
        addEventListener('load', () => this.$root.querySelectorAll('.slide-bg').forEach((el) => {
            const m = /url\(["']?([^"')]+)/.exec(el.style.backgroundImage); if (m) { const i = new Image(); i.src = m[1]; }
        }), { once: true });
        document.addEventListener('visibilitychange', () => (document.hidden ? this.stop() : this.paused || this.schedule()));
        this.$root.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') { this.next(); e.preventDefault(); }
            if (e.key === 'ArrowLeft') { this.prev(); e.preventDefault(); }
        });
        if (reduce) { this.paused = true; return; }
        this.schedule();
    },
    schedule(ms = this.duration) {
        this.stop();
        this._left = ms; this._started = performance.now();
        this._t = setTimeout(() => this.next(true), ms);
    },
    stop() { clearTimeout(this._t); this._t = null; },
    go(i, auto = false) {
        this.index = (i + this.count) % this.count;
        if (this.index === 0 && auto) this.cycle++;
        if (!reduce && !this.paused) this.schedule();
    },
    next(auto = false) { this.go(this.index + 1, auto); },
    prev() { this.go(this.index - 1); },
    hold(on) {
        if (reduce) return;
        if (on && !this.paused) {
            this.paused = true; this.stop();
            this._left = Math.max(400, this._left - (performance.now() - this._started));
        } else if (!on && this.paused) {
            this.paused = false; this.schedule(this._left);
        }
    },
    touchStart(e) { this._x = e.touches?.[0]?.clientX ?? null; },
    touchEnd(e) {
        if (this._x === null) return;
        const dx = (e.changedTouches?.[0]?.clientX ?? this._x) - this._x;
        if (Math.abs(dx) > 45) (dx < 0 ? this.next() : this.prev());
        this._x = null;
    },
}));

/* ───────────── explainer video ───────────── */
Alpine.data('explainer', () => ({
    playing: false,
    done: false,
    play() {
        const v = this.$refs.v;
        if (this.done) v.currentTime = 0;
        this.done = false;
        v.play().catch(() => { v.controls = true; });
    },
}));

/* ───────────── presale stats (อ่านสดจากสัญญา) ───────────── */
Alpine.data('presaleStats', () => ({
    live: false,
    sold: 0,
    raised: 0,
    cap: Number(CFG.sale?.cap || 100e9),
    pct: 0,
    fmt,
    init() {
        this.load();
        this._iv = setInterval(() => !document.hidden && this.load(), 20000);
    },
    destroy() { clearInterval(this._iv); },
    async load() {
        const sale = reader('presale', 'sale');
        if (!sale) return;
        try {
            const [sold, raised, cap] = await Promise.all([sale.sold(), sale.raised(), sale.saleCap()]);
            this.sold = units(sold, 18); this.raised = units(raised, CFG.sale?.pay_decimals ?? 6); this.cap = units(cap, 18);
            this.pct = this.cap ? Math.min(100, (this.sold / this.cap) * 100) : 0;
            this.live = true;
        } catch { /* RPC ช้า/หลุด — คงค่าเดิมไว้ */ }
    },
}));

/* ───────────── tokenomics donut ───────────── */
Alpine.data('donut', (alloc) => ({
    centerValue: '1.1T',
    centerLabel: t('chart.total'),
    init() {
        const total = alloc.reduce((a, it) => a + Number(it.amount), 0);
        const R = 80, C = 2 * Math.PI * R;
        let acc = 0;
        this.$refs.svg.innerHTML = `<circle cx="100" cy="100" r="${R}" stroke="rgba(255,255,255,.05)"></circle>` + alloc.map((it, i) => {
            const len = (Number(it.amount) / total) * C;
            const s = `<circle class="donut-seg" data-seg="${i}" cx="100" cy="100" r="${R}" stroke="${it.color}" stroke-dasharray="${Math.max(0, len - 1.2)} ${C}" stroke-dashoffset="${reduce ? -acc : -acc + len}" data-off="${-acc}"></circle>`;
            acc += len; return s;
        }).join('');
        this.total = total; this.alloc = alloc;
        this.$refs.svg.querySelectorAll('.donut-seg').forEach((el) => {
            el.addEventListener('mouseenter', () => this.highlight(+el.dataset.seg));
            el.addEventListener('mouseleave', () => this.highlight(null));
        });
        whenVisible(this.$root, () => this.$refs.svg.querySelectorAll('.donut-seg').forEach((s, i) => setTimeout(() => s.setAttribute('stroke-dashoffset', s.dataset.off), i * 90)));
    },
    highlight(i) {
        this.$refs.svg.querySelectorAll('.donut-seg').forEach((s) => s.classList.toggle('dim', i !== null && +s.dataset.seg !== i));
        if (i === null) { this.centerValue = '1.1T'; this.centerLabel = t('chart.total'); return; }
        this.centerValue = `${((Number(this.alloc[i].amount) / this.total) * 100).toFixed(1)}%`;
        this.centerLabel = this.alloc[i].name;
    },
}));

/* ───────────── unlock chart (สเกลจริง: แกน y = พันล้านเหรียญหมุนเวียน, แกน x = เดือน) ───────────── */
Alpine.data('unlockChart', (tranches) => ({
    init() {
        const liquid = 1100 - tranches.reduce((a, t) => a + Number(t.amount) / B, 0);
        const circ = (m) => tranches.reduce((v, t) => v + (Number(t.amount) / B) * Math.max(0, Math.min(t.release, m - t.cliff)) / t.release, liquid);
        const L = 64, Rr = 880, T = 18, Bt = 286, X = (m) => L + ((Rr - L) * m) / 60, Y = (v) => Bt - ((Bt - T) * v) / 1100;
        let s = `<defs><linearGradient id="lineGrad" x1="0" x2="1"><stop offset="0" stop-color="#10b981"/><stop offset="1" stop-color="#a3e635"/></linearGradient>
          <linearGradient id="areaGrad" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="rgba(163,230,53,.35)"/><stop offset="1" stop-color="rgba(16,185,129,0)"/></linearGradient></defs>`;
        [0, 275, 550, 825, 1100].forEach((v) => { s += `<line class="grid" x1="${L}" x2="${Rr}" y1="${Y(v)}" y2="${Y(v)}"/><text x="${L - 10}" y="${Y(v) + 4}" text-anchor="end">${v ? `${fmt(v)}B` : '0'}</text>`; });
        [0, 12, 24, 36, 48, 60].forEach((m) => { s += `<text x="${X(m)}" y="${Bt + 22}" text-anchor="${m === 60 ? 'end' : 'middle'}">${m ? t('chart.month', { m }) : t('chart.launch')}</text>`; });
        let d = '', area = `M${X(0)},${Bt}`;
        for (let m = 0; m <= 60; m += 0.5) { const p = `${X(m).toFixed(1)},${Y(circ(m)).toFixed(1)}`; d += (m ? 'L' : 'M') + p; area += `L${p}`; }
        area += `L${X(60)},${Bt}Z`;
        s += `<rect x="${L}" y="${T}" width="${Rr - L}" height="${Bt - T}" fill="rgba(59,130,246,.07)"/><path d="${area}" fill="url(#areaGrad)"/><path class="line" d="${d}"/>`;
        [0, 24, 36, 48, 60].forEach((m) => {
            const v = Math.round(circ(m)), end = m === 60;
            s += `<circle class="mark" cx="${X(m)}" cy="${Y(v)}" r="5"/><text class="ann" x="${X(m) + (end ? -10 : 10)}" y="${Y(v) + (end ? 24 : -12)}" text-anchor="${end ? 'end' : 'start'}">${m === 0 ? t('chart.circulating', { v: `${fmt(v)}B` }) : end ? t('chart.complete', { v: shortNum(v * B) }) : `${fmt(v)}B`}</text>`;
        });
        this.$refs.svg.innerHTML = s;
        if (reduce) return;
        const p = this.$refs.svg.querySelector('.line'), len = p.getTotalLength();
        p.style.strokeDasharray = len; p.style.strokeDashoffset = len;
        whenVisible(this.$root, () => { p.style.transition = 'stroke-dashoffset 2.2s cubic-bezier(.3,.6,.2,1)'; p.style.strokeDashoffset = 0; });
    },
}));

/* ───────────── reveal + counters ───────────── */
const io = 'IntersectionObserver' in window && !reduce
    ? new IntersectionObserver((entries) => entries.forEach((en) => {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        en.target.classList.remove('pre');
        en.target._onVisible?.();
    }), { rootMargin: '0px 0px -8% 0px' })
    : null;

function whenVisible(el, fn) {
    if (!io) { fn(); return; }
    el._onVisible = fn; io.observe(el);
}

function countUp(el) {
    const to = Number(el.dataset.count), isShort = 'short' in el.dataset;
    const show = (v) => { el.textContent = isShort ? shortNum(v) : fmt(Math.round(v)); };
    if (reduce) { show(to); return; }
    const t0 = performance.now(), dur = 1600;
    const step = (t) => { const p = Math.min(1, (t - t0) / dur); show(to * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); else show(to); };
    requestAnimationFrame(step);
}

function initReveal() {
    // เนื้อหาเห็นครบตั้งแต่โหลด — ซ่อนเพื่อทำอนิเมชันเฉพาะส่วนที่ยังอยู่ใต้จอเท่านั้น
    document.querySelectorAll('.reveal').forEach((el) => {
        if (!io) return;
        if (el.getBoundingClientRect().top > innerHeight * 0.92) el.classList.add('pre');
        const prev = el._onVisible; el._onVisible = () => prev?.();
        io.observe(el);
    });
    document.querySelectorAll('[data-count]').forEach((el) => whenVisible(el, () => countUp(el)));
}

/* ───────────── hero energy flow (2D canvas) ───────────── */
function initFlow() {
    const cv = document.getElementById('flow');
    if (!cv || reduce) return;
    const ctx = cv.getContext('2d');
    let W = 0, H = 0, parts = [], paths = [], running = true;
    const spawn = () => ({ p: Math.floor(Math.random() * 7), t: Math.random(), v: 0.0016 + Math.random() * 0.0028, s: 0.6 + Math.random() * 1.6, h: Math.random() < 0.25 });
    const bez = (P, t) => { const u = 1 - t; return [u * u * u * P[0][0] + 3 * u * u * t * P[1][0] + 3 * u * t * t * P[2][0] + t * t * t * P[3][0], u * u * u * P[0][1] + 3 * u * u * t * P[1][1] + 3 * u * t * t * P[2][1] + t * t * t * P[3][1]]; };
    function size() {
        const r = cv.getBoundingClientRect(), dpr = Math.min(devicePixelRatio || 1, 2);
        W = r.width; H = r.height; cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        paths = Array.from({ length: 7 }, (_, i) => {
            const y0 = H * (0.55 + i * 0.06), hx = W * 0.8, hy = H * 0.24;
            return [[W * (0.42 + i * 0.02), y0 + 40], [W * (0.58 + i * 0.015), y0 - 30 - i * 6], [W * (0.7 + i * 0.01), hy + 40 + i * 8], [hx, hy]];
        });
        parts = Array.from({ length: Math.round(W / (W < 760 ? 16 : 9)) }, spawn);
    }
    function frame() {
        if (!running) return;
        ctx.clearRect(0, 0, W, H);
        ctx.globalCompositeOperation = 'lighter';
        for (const q of parts) {
            q.t += q.v; if (q.t > 1) Object.assign(q, spawn(), { t: 0 });
            const [x, y] = bez(paths[q.p], q.t), a = Math.sin(q.t * Math.PI) * 0.9;
            const g = ctx.createRadialGradient(x, y, 0, x, y, q.s * 5);
            g.addColorStop(0, q.h ? `rgba(230,242,58,${a})` : `rgba(163,230,53,${a})`); g.addColorStop(1, 'rgba(16,185,129,0)');
            ctx.fillStyle = g; ctx.beginPath(); ctx.arc(x, y, q.s * 5, 0, 6.283); ctx.fill();
        }
        ctx.globalCompositeOperation = 'source-over';
        requestAnimationFrame(frame);
    }
    size();
    addEventListener('resize', size);
    // หยุดวาดเมื่อ hero เลื่อนพ้นจอ — ไม่กินแบตเปล่า ๆ
    new IntersectionObserver(([en]) => { const was = running; running = en.isIntersecting; if (running && !was) requestAnimationFrame(frame); }).observe(cv);
    requestAnimationFrame(frame);
}

// $t('presale.raised', { v }) ในเทมเพลต Alpine — ข้อความจากพจนานุกรมของหน้า (./i18n)
Alpine.magic('t', () => t);

window.Alpine = Alpine;
document.addEventListener('DOMContentLoaded', () => { initReveal(); initFlow(); });
Alpine.start();

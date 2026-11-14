/**
 * หลังบ้าน › Binary › ผังสายงาน — ต้นไม้สองขาจากบนลงล่าง (canvas 2D ไม่ใช้ไลบรารี) แยกจากผังวงของ IDTC Grid (admin-map.js)
 *
 * ข้อมูลจาก BinaryMap::slice เรียงชั้นต่อชั้น แถวละ
 * [wallet, แม่ (-1 = บนสุด), ขา L/R, ชั้น, ฝากเอง, คนซ้าย, คนขวา, ยอดซ้าย, ยอดขวา, ยกมาซ้าย, ยกมาขวา, flags, พับไว้, เพื่อนตรงซ้าย, เพื่อนตรงขวา]
 *
 * การวาง: แต่ละกระเป๋าได้ช่วงกว้าง = ช่วงของขาซ้าย + ขาขวา (ขาที่ว่างกันที่ไว้ 0.6 ช่อง) → ขาซ้ายอยู่ซ้าย ขาขวาอยู่ขวาเสมอ และไม่ทับกัน
 * วาดเฉพาะตอนมีอะไรเปลี่ยน · ลากที่ว่าง = เลื่อน · ล้อเมาส์/สองนิ้ว = ซูม · คลิก = ดูข้อมูล · ดับเบิลคลิก = เริ่มผังจากกระเป๋านั้น
 */
import Alpine from 'alpinejs';
import { fmt, short } from './chain';

const UNIT = 148;      // กว้างหนึ่งช่อง
const ROW = 120;       // สูงหนึ่งชั้น
const CW = 128, CH = 64;
const GAP = 0.6;       // ที่ของขาที่ยังว่าง
const ACTIVE = 1, QUALIFIED = 2, HELD = 4;
const C = {
    left: '#2dd4bf', right: '#f5c86b', qualified: '#a3e635', active: '#10b981', idle: '#5b6b66', held: '#fb7185',
    card: '#0b1512', text: '#eaf4ef', dim: 'rgba(234,244,239,.62)', line: 'rgba(220,255,240,.10)',
};
const SANS = '"Noto Sans Thai", system-ui, sans-serif';
const MONO = '"JetBrains Mono", ui-monospace, monospace';

/** 1234 → 1,234 · 12,345 → 12.3k · 1,234,567 → 1.23M */
export function compact(n) {
    const v = Number(n) || 0;
    if (v >= 1e6) return `${(v / 1e6).toFixed(v >= 1e7 ? 1 : 2)}M`;
    if (v >= 1e4) return `${(v / 1e3).toFixed(1)}k`;
    return fmt(v, v > 0 && v < 100 && v % 1 !== 0 ? 2 : 0);
}

/** ตำแหน่งของทุกกระเป๋า (หน่วย = ช่อง) — ส่งออกไว้ให้ทดสอบได้ */
export function layout(nodes) {
    const n = nodes.length;
    const L = new Int32Array(n).fill(-1), R = new Int32Array(n).fill(-1);
    for (let i = 1; i < n; i++) {
        const p = nodes[i][1];
        if (p < 0 || p >= i) continue;
        if (nodes[i][2] === 'L') L[p] = i; else R[p] = i;
    }
    // ความกว้างของช่วง — ข้อมูลเรียงแม่ก่อนลูก จึงย้อนจากท้ายได้ลูกก่อนแม่เสมอ
    const w = new Float64Array(n);
    for (let i = n - 1; i >= 0; i--) {
        w[i] = L[i] < 0 && R[i] < 0 ? 1 : (L[i] >= 0 ? w[L[i]] : GAP) + (R[i] >= 0 ? w[R[i]] : GAP);
    }
    const x0 = new Float64Array(n);
    for (let i = 0; i < n; i++) {
        if (L[i] >= 0) x0[L[i]] = x0[i];
        if (R[i] >= 0) x0[R[i]] = x0[i] + (L[i] >= 0 ? w[L[i]] : GAP);
    }
    // แม่อยู่กึ่งกลางระหว่างสองขา · มีขาเดียว = เยื้องครึ่งช่องไปฝั่งที่ว่าง
    const x = new Float64Array(n);
    let depth = 0;
    for (let i = n - 1; i >= 0; i--) {
        if (L[i] >= 0 && R[i] >= 0) x[i] = (x[L[i]] + x[R[i]]) / 2;
        else if (L[i] >= 0) x[i] = x[L[i]] + 0.5;
        else if (R[i] >= 0) x[i] = x[R[i]] - 0.5;
        else x[i] = x0[i] + 0.5;
        depth = Math.max(depth, nodes[i][3]);
    }
    return { x, L, R, width: n ? w[0] : 0, depth };
}

function roundRect(c, x, y, w, h, r) {
    c.beginPath();
    c.moveTo(x + r, y);
    c.arcTo(x + w, y, x + w, y + h, r);
    c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r);
    c.arcTo(x, y, x + w, y, r);
    c.closePath();
}

const tone = (flags) => (flags & QUALIFIED ? C.qualified : flags & ACTIVE ? C.active : C.idle);

Alpine.data('binaryMap', () => {
    // สถานะผังอยู่นอก reactive ของ Alpine (typed array ขนาดใหญ่ไม่ต้องถูก proxy) — มีแค่ tip / q / note ที่ผูกกับหน้า
    const S = {};

    return {
        tip: null,
        q: '',
        note: '',
        copied: false,

        init() {
            const el = this.$root.querySelector('script[data-bmap]');
            S.d = JSON.parse(el?.textContent || '{}');
            S.nodes = S.d.nodes || [];
            S.L = layout(S.nodes);
            S.at = new Map(S.nodes.map((x, i) => [x[0], i]));
            S.view = { x: 0, y: 0, k: 1 };
            S.ptrs = new Map();
            S.hover = -1;
            S.sel = -1;
            S.cv = this.$refs.cv;
            S.ro = new ResizeObserver(() => { this.resize(); });
            S.ro.observe(S.cv);
            this.resize();
            this.fit();
            document.fonts?.ready?.then(() => this.draw());
        },

        destroy() { S.ro?.disconnect(); cancelAnimationFrame(S.raf); },

        resize() {
            const r = S.cv.getBoundingClientRect();
            const dpr = Math.min(2, window.devicePixelRatio || 1);
            const first = !S.w;
            S.w = r.width; S.h = r.height;
            S.cv.width = Math.round(r.width * dpr); S.cv.height = Math.round(r.height * dpr);
            S.dpr = dpr;
            if (first && S.w) this.fit(); else this.draw();
        },

        // ขนาดผังทั้งหมด (หน่วยโลก)
        box() { return { w: Math.max(1, S.L.width) * UNIT, h: S.L.depth * ROW + CH + 34 }; },

        /** ทั้งผังพอดีจอ */
        fit() {
            const b = this.box();
            const k = Math.min(1.15, Math.max(0.02, Math.min((S.w - 36) / b.w, (S.h - 56) / b.h)));
            S.view = { k, x: (S.w - b.w * k) / 2, y: this.topGap() };      // ห้อยจากขอบบน — ผังกว้างแต่เตี้ยไม่ลอยอยู่กลางกรอบ
            this.unpin();
            this.draw();
        },

        /** ไปที่บนสุดของผัง ขนาดที่อ่านตัวหนังสือบนการ์ดออก */
        home() {
            if (!S.w) return;
            const b = this.box();
            const k = Math.min(1.15, Math.max(0.85, Math.min((S.w - 36) / b.w, (S.h - 56) / b.h)));
            const rx = S.nodes.length ? S.L.x[0] * UNIT : b.w / 2;
            S.view = { k, x: S.w / 2 - rx * k, y: this.topGap() };
            this.unpin();
            this.draw();
        },

        // จอแคบ: ปุ่มซูมอยู่มุมขวาบนของกรอบ — เว้นที่ไม่ให้ทับกระเป๋าบนสุด
        topGap() { return S.w < 560 ? 62 : 30; },

        unpin() { this.tip = null; S.sel = -1; },

        zoomBy(f, cx = S.w / 2, cy = S.h / 2) {
            const v = S.view;
            const k = Math.min(3, Math.max(0.02, v.k * f));
            // คงจุดใต้เคอร์เซอร์ไว้ที่เดิม
            v.x = cx - ((cx - v.x) / v.k) * k; v.y = cy - ((cy - v.y) / v.k) * k; v.k = k;
            if (this.tip?.pinned) this.unpin();
            this.draw();
        },

        pos(i) { return [S.L.x[i] * UNIT, S.nodes[i][3] * ROW]; },

        pick(px, py) {
            const v = S.view;
            const wx = (px - v.x) / v.k, wy = (py - v.y) / v.k;
            const hw = Math.max(CW / 2, 9 / v.k), hh = Math.max(CH / 2, 9 / v.k);
            for (let i = S.nodes.length - 1; i >= 0; i--) {
                const [x, y] = this.pos(i);
                if (Math.abs(wx - x) <= hw && Math.abs(wy - (y + CH / 2)) <= hh) return i;
            }
            return -1;
        },

        local(e) { const r = S.cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; },

        down(e) {
            S.cv.setPointerCapture?.(e.pointerId);
            const [px, py] = this.local(e);
            S.ptrs.set(e.pointerId, { x: px, y: py });
            S.drag = { x: px, y: py, vx: S.view.x, vy: S.view.y, moved: 0 };
            if (S.ptrs.size === 2) {
                const [a, b] = [...S.ptrs.values()];
                S.pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), k: S.view.k };
            }
        },

        move(e) {
            const [px, py] = this.local(e);
            if (S.ptrs.has(e.pointerId)) S.ptrs.set(e.pointerId, { x: px, y: py });
            if (S.pinch && S.ptrs.size === 2) {
                const [a, b] = [...S.ptrs.values()];
                const d = Math.hypot(a.x - b.x, a.y - b.y);
                if (S.pinch.d > 0) this.zoomBy((S.pinch.k * d / S.pinch.d) / S.view.k, (a.x + b.x) / 2, (a.y + b.y) / 2);
                return;
            }
            if (S.drag) {
                const dx = px - S.drag.x, dy = py - S.drag.y;
                S.drag.moved = Math.max(S.drag.moved, Math.abs(dx) + Math.abs(dy));
                if (S.drag.moved > 4) {
                    S.view.x = S.drag.vx + dx; S.view.y = S.drag.vy + dy;
                    if (this.tip) this.unpin();
                    this.draw();
                }
                return;
            }
            const i = this.pick(px, py);
            if (i !== S.hover) {
                S.hover = i;
                S.cv.style.cursor = i >= 0 ? 'pointer' : 'grab';
                if (!this.tip?.pinned) { this.tip = i >= 0 ? this.tipFor(i, px, py, false) : null; this.keepTipInside(); }
                this.draw();
            }
        },

        up(e) {
            S.ptrs.delete(e.pointerId);
            if (S.ptrs.size < 2) S.pinch = null;
            const d = S.drag;
            S.drag = null;
            if (d && d.moved <= 4 && e.type === 'pointerup') {
                const [px, py] = this.local(e);
                this.select(this.pick(px, py), px, py);
            }
        },

        leave() { if (!S.drag && !this.tip?.pinned) { this.tip = null; S.hover = -1; this.draw(); } },

        wheel(e) {
            const [px, py] = this.local(e);
            this.zoomBy(Math.exp(-e.deltaY * 0.0015), px, py);
        },

        dive(e) {
            const [px, py] = this.local(e);
            const i = this.pick(px, py);
            if (i > 0) location.href = this.mapUrl(S.nodes[i][0]);
        },

        key(e) {
            const step = 60;
            const move = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] }[e.key];
            if (move) { S.view.x += move[0]; S.view.y += move[1]; this.unpin(); this.draw(); }
            else if (e.key === '+' || e.key === '=') this.zoomBy(1.25);
            else if (e.key === '-') this.zoomBy(0.8);
            else if (e.key === '0') this.fit();
            else return;
            e.preventDefault();
        },

        select(i, px, py) {
            S.sel = i;
            S.hover = i;
            this.tip = i >= 0 ? this.tipFor(i, px, py, true) : null;
            this.keepTipInside();
            this.draw();
        },

        /** กล่องข้อมูลสูงไม่เท่ากัน (มี/ไม่มีปุ่ม) — วัดจริงหลังวาดแล้วดันกลับเข้ากรอบ */
        keepTipInside() {
            if (!this.tip) return;
            this.$nextTick(() => {
                const el = this.$refs.tip;
                if (!el || !this.tip) return;
                const maxX = S.w - el.offsetWidth - 8, maxY = S.h - el.offsetHeight - 8;
                if (this.tip.x > maxX) this.tip.x = Math.max(8, maxX);
                if (this.tip.y > maxY) this.tip.y = Math.max(8, maxY);
            });
        },

        /** เลื่อนไปที่กระเป๋า i แล้วเปิดข้อมูล */
        focus(i) {
            const [x, y] = this.pos(i);
            const k = Math.max(0.8, Math.min(1.15, S.view.k));
            S.view = { k, x: S.w / 2 - x * k, y: S.h * 0.3 - y * k };
            this.select(i, S.w / 2 + (CW / 2) * k, S.h * 0.3 + CH * k);
        },

        /** ค้นหา: เจอในส่วนที่แสดง = เลื่อนไปหา · ที่อยู่เต็มที่ไม่ได้แสดง = เปิดผังจากกระเป๋านั้น */
        find() {
            const q = this.q.trim().toLowerCase();
            this.note = '';
            if (!q) return;
            const full = /^0x[0-9a-f]{40}$/.test(q);
            let i = full ? (S.at.get(q) ?? -1) : -1;
            if (!full) {
                const part = q.replace(/^0x/, '').replace(/…|\.{2,}/g, '');
                if (!/^[0-9a-f]{4,40}$/.test(part)) { this.note = 'ใส่ที่อยู่กระเป๋า (0x…) หรืออย่างน้อย 4 ตัวอักษรของที่อยู่'; return; }
                i = S.nodes.findIndex((x) => x[0].includes(part));
            }
            if (i >= 0) { this.focus(i); return; }
            if (full) { location.href = this.mapUrl(q); return; }
            this.note = 'ไม่พบในส่วนที่แสดงอยู่ — ใส่ที่อยู่เต็ม (0x… 42 ตัว) เพื่อค้นทั้งระบบ';
        },

        async copy() {
            const w = this.tip?.wallet;
            if (!w) return;
            try {
                await navigator.clipboard.writeText(w);
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 1500);
            } catch {
                this.note = `คัดลอกไม่ได้ — ที่อยู่: ${w}`;
            }
        },

        mapUrl(w) { const u = new URL(S.d.mapUrl, location.href); u.searchParams.set('root', w); return u.toString(); },
        // โหมดตัวอย่างไม่มีหน้าจัดสาย/ข้อมูลกระเป๋า (treeUrl, memberUrl เป็น null) → ไม่มีปุ่ม
        treeUrl(w) { if (!S.d.treeUrl) return ''; const u = new URL(S.d.treeUrl, location.href); u.searchParams.set('root', w); return u.toString(); },
        memberUrl(w) { return S.d.memberUrl ? String(S.d.memberUrl).replace(S.d.zero, w) : ''; },

        tipFor(i, px, py, pinned) {
            const x = S.nodes[i];
            const narrow = S.w < 460;
            return {
                pinned, wallet: x[0], short: short(x[0]), root: i === 0,
                where: i === 0 ? 'บนสุดของผังที่แสดง' : `${x[2] === 'L' ? 'ขาซ้าย' : 'ขาขวา'}ของ ${short(S.nodes[x[1]][0])} · ชั้น ${x[3]}`,
                own: fmt(x[4], 2),
                state: x[11] & QUALIFIED ? 'ผ่านเงื่อนไขรับรางวัลจับคู่' : x[11] & ACTIVE ? 'ฝากถึงขั้นต่ำแล้ว · ยังไม่ผ่านเงื่อนไขจับคู่' : 'ยังฝากไม่ถึงขั้นต่ำ',
                tone: x[11] & QUALIFIED ? 'q' : x[11] & ACTIVE ? 'a' : 'i',
                held: !!(x[11] & HELD),
                nl: fmt(x[5]), nr: fmt(x[6]), l: fmt(x[7], 2), r: fmt(x[8], 2), cl: fmt(x[9], 2), cr: fmt(x[10], 2), dl: fmt(x[13]), dr: fmt(x[14]),
                fold: x[12] ? fmt(x[12]) : '',
                map: this.mapUrl(x[0]), tree: this.treeUrl(x[0]), member: this.memberUrl(x[0]),
                x: narrow ? 8 : px + 14, y: py + 14,
            };
        },

        draw() {
            if (S.raf) return;
            S.raf = requestAnimationFrame(() => { S.raf = 0; this.paint(); });
        },

        paint() {
            const c = S.cv.getContext('2d');
            const { w, h, dpr, view: v, L, nodes } = S;
            if (!c || !w) return;
            const k = v.k;
            c.setTransform(dpr, 0, 0, dpr, 0, 0);
            c.clearRect(0, 0, w, h);

            // แถบชั้น (พิกัดจอ)
            const rowPx = ROW * k;
            if (rowPx >= 26) {
                c.font = `11px ${SANS}`;
                c.textAlign = 'left';
                c.textBaseline = 'middle';
                for (let d = 0; d <= L.depth; d++) {
                    const sy = v.y + (d * ROW + CH / 2) * k;
                    if (sy < -20 || sy > h + 20) continue;
                    c.strokeStyle = C.line;
                    c.lineWidth = 1;
                    c.beginPath(); c.moveTo(0, sy); c.lineTo(w, sy); c.stroke();
                    c.fillStyle = 'rgba(234,244,239,.42)';
                    c.fillText(d === 0 ? 'บนสุด' : `ชั้น ${d}`, 8, sy - Math.min(rowPx / 2 - 8, CH * k / 2 + 9));
                }
            }

            // จากนี้วาดในพิกัดโลก
            c.setTransform(dpr * k, 0, 0, dpr * k, dpr * v.x, dpr * v.y);
            const x0 = -v.x / k, y0 = -v.y / k, x1 = (w - v.x) / k, y1 = (h - v.y) / k;
            const seen = (x, y) => x > x0 - UNIT && x < x1 + UNIT && y > y0 - ROW && y < y1 + ROW;
            const lw = Math.max(1.6, 1.2 / k);

            // เส้นสาย: ซ้ายสีหนึ่ง ขวาอีกสีหนึ่ง (รวมเป็นสอง path)
            for (const leg of ['L', 'R']) {
                c.beginPath();
                for (let i = 1; i < nodes.length; i++) {
                    if (nodes[i][2] !== leg) continue;
                    const p = nodes[i][1];
                    const px = L.x[p] * UNIT, py = nodes[p][3] * ROW + CH;
                    const cx = L.x[i] * UNIT, cy = nodes[i][3] * ROW;
                    if (!seen(px, py) && !seen(cx, cy) && !(Math.min(px, cx) < x1 && Math.max(px, cx) > x0 && py < y1 && cy > y0)) continue;
                    const my = py + (ROW - CH) / 2;
                    c.moveTo(px, py); c.lineTo(px, my); c.lineTo(cx, my); c.lineTo(cx, cy);
                }
                c.strokeStyle = leg === 'L' ? C.left : C.right;
                c.globalAlpha = 0.6;
                c.lineWidth = lw;
                c.lineJoin = 'round';
                c.stroke();
            }
            c.globalAlpha = 1;

            const cards = k >= 0.5;      // การ์ดเต็ม · ต่ำกว่านี้ = การ์ดย่อ (ไม่มีตัวหนังสือ) · ไกลมาก = กล่องสีทึบ
            const mini = !cards && k >= 0.24;
            const half = cards || mini ? CW / 2 : Math.max(CW / 2 * 0.8, 3.5 / k);
            const tall = cards || mini ? CH : Math.max(CH * 0.8, 7 / k);

            for (let i = 0; i < nodes.length; i++) {
                const n = nodes[i];
                const x = L.x[i] * UNIT, y = n[3] * ROW;
                if (!seen(x, y)) continue;
                const col = tone(n[11]);

                // ช่องว่างของขาที่ยังไม่มีคน (เฉพาะกระเป๋าที่เปิดลูกอยู่และมีขาเดียว)
                if (cards && (L.L[i] >= 0) !== (L.R[i] >= 0)) {
                    const ex = x + (L.L[i] >= 0 ? 0.5 : -0.5) * UNIT, ey = y + ROW + 22;
                    c.beginPath();
                    c.setLineDash([4, 4]);
                    c.moveTo(x, y + CH); c.lineTo(x, y + CH + (ROW - CH) / 2); c.lineTo(ex, y + CH + (ROW - CH) / 2); c.lineTo(ex, ey - 14);
                    c.strokeStyle = 'rgba(234,244,239,.22)';
                    c.lineWidth = 1.2;
                    c.stroke();
                    c.beginPath(); c.arc(ex, ey, 14, 0, Math.PI * 2); c.stroke();
                    c.setLineDash([]);
                    c.fillStyle = 'rgba(234,244,239,.45)';
                    c.font = `10px ${SANS}`; c.textAlign = 'center'; c.textBaseline = 'middle';
                    c.fillText('ว่าง', ex, ey + 1);
                }

                if (mini) {
                    roundRect(c, x - CW / 2, y, CW, CH, 14);
                    c.fillStyle = C.card;
                    c.fill();
                    c.save();
                    c.clip();
                    c.fillStyle = col;
                    c.globalAlpha = n[11] & (ACTIVE | QUALIFIED) ? 0.85 : 0.4;
                    c.fillRect(x - CW / 2, y, CW, CH - 24);
                    c.globalAlpha = 0.75;
                    c.fillStyle = n[5] > 0 ? C.left : 'rgba(234,244,239,.12)'; c.fillRect(x - CW / 2, y + CH - 24, CW / 2 - 1.5, 24);
                    c.fillStyle = n[6] > 0 ? C.right : 'rgba(234,244,239,.12)'; c.fillRect(x + 1.5, y + CH - 24, CW / 2 - 1.5, 24);
                    c.restore();
                    c.globalAlpha = 1;
                    roundRect(c, x - CW / 2, y, CW, CH, 14);
                    c.strokeStyle = n[11] & HELD ? C.held : i === 0 ? '#ffffff' : 'rgba(5,10,9,.9)';
                    c.lineWidth = n[11] & HELD || i === 0 ? 5 : 2;
                    c.stroke();
                } else if (!cards) {
                    roundRect(c, x - half, y + (CH - tall) / 2, half * 2, tall, Math.min(10, half / 2));
                    c.fillStyle = col;
                    c.globalAlpha = n[11] & (ACTIVE | QUALIFIED) ? 0.9 : 0.55;
                    c.fill();
                    c.globalAlpha = 1;
                    if (n[11] & HELD || i === 0) { c.strokeStyle = n[11] & HELD ? C.held : '#ffffff'; c.lineWidth = Math.max(2, 1.5 / k); c.stroke(); }
                } else {
                    roundRect(c, x - CW / 2, y, CW, CH, 12);
                    c.fillStyle = C.card;
                    c.fill();
                    c.strokeStyle = n[11] & HELD ? C.held : col;
                    c.lineWidth = i === 0 ? 2.6 : 1.6;
                    c.stroke();

                    // แถบยอดสองขา
                    c.save();
                    roundRect(c, x - CW / 2 + 1, y + 1, CW - 2, CH - 2, 11);
                    c.clip();
                    c.fillStyle = 'rgba(45,212,191,.14)'; c.fillRect(x - CW / 2, y + CH - 22, CW / 2, 22);
                    c.fillStyle = 'rgba(245,200,107,.14)'; c.fillRect(x, y + CH - 22, CW / 2, 22);
                    c.restore();

                    c.textBaseline = 'middle';
                    c.textAlign = 'left';
                    c.font = `600 12px ${MONO}`;
                    c.fillStyle = C.text;
                    c.fillText(short(n[0]), x - CW / 2 + 10, y + 14);
                    c.beginPath(); c.arc(x + CW / 2 - 12, y + 14, 4, 0, Math.PI * 2); c.fillStyle = col; c.fill();
                    c.font = `11px ${SANS}`;
                    c.fillStyle = n[4] > 0 ? C.dim : 'rgba(234,244,239,.38)';
                    c.fillText(n[4] > 0 ? `ฝาก ${compact(n[4])} USDT` : 'ยังไม่ฝาก', x - CW / 2 + 10, y + 31);
                    c.font = `600 10.5px ${SANS}`;
                    c.textAlign = 'center';
                    c.fillStyle = C.left; c.fillText(`ซ ${compact(n[5])} · ${compact(n[7])}`, x - CW / 4, y + CH - 11);
                    c.fillStyle = C.right; c.fillText(`ข ${compact(n[6])} · ${compact(n[8])}`, x + CW / 4, y + CH - 11);
                }

                if (i === S.sel || i === S.hover) {
                    const hw = cards ? CW / 2 : half, th = cards ? CH : tall, ty = cards ? y : y + (CH - tall) / 2;
                    const pad = 4 / Math.min(1, k);
                    roundRect(c, x - hw - pad, ty - pad, hw * 2 + pad * 2, th + pad * 2, 14);
                    c.strokeStyle = '#ffffff';
                    c.lineWidth = Math.max(2, 1.6 / k);
                    c.stroke();
                }

                // สายล่างที่พับไว้
                if (n[12] > 0 && k >= 0.28) {
                    const label = `+${compact(n[12])} ▾`;
                    c.font = `600 11px ${SANS}`;
                    const tw = c.measureText(label).width + 16;
                    roundRect(c, x - tw / 2, y + CH + 7, tw, 20, 10);
                    c.fillStyle = 'rgba(245,200,107,.14)'; c.fill();
                    c.strokeStyle = 'rgba(245,200,107,.5)'; c.lineWidth = 1; c.stroke();
                    c.fillStyle = C.right; c.textAlign = 'center'; c.textBaseline = 'middle';
                    c.fillText(label, x, y + CH + 17.5);
                }
            }
        },
    };
});

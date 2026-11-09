/**
 * หลังบ้าน › ผังสายงาน Grid — canvas 2D ไม่ใช้ไลบรารี
 *
 * ข้อมูลจาก GridBook::slice (ไม่เกิน 2,500 กระเป๋า): [wallet, parent, วง, rank, own, team, team_count, held, fold]
 * วาง "วงโคจร": จุดศูนย์กลาง = ทั้งระบบหรือกระเป๋าที่เลือก · วง d อยู่บนวงกลมรัศมีที่ d · มุมแบ่งตามจำนวนปลายสาย
 * วาดเฉพาะตอนมีอะไรเปลี่ยน (ไม่มี loop ค้าง) · ลากที่ว่าง = เลื่อน · ล้อเมาส์/สองนิ้ว = ซูม · คลิกจุด = ดูข้อมูล · ดับเบิลคลิก = เริ่มผังจากจุดนั้น
 */
import Alpine from 'alpinejs';
import { fmt, short } from './chain';

const RANK_COLOR = ['#5b6b66', '#e6f23a', '#3b82f6', '#10b981', '#a3e635'];
const HELD = '#fb7185';

function layout(nodes) {
    const n = nodes.length;
    const kids = Array.from({ length: n }, () => []);
    const top = [];
    const at = new Map(nodes.map((x, i) => [x[0], i]));
    nodes.forEach((x, i) => { const p = x[1] === null ? -1 : at.get(x[1]) ?? -1; if (p < 0) top.push(i); else kids[p].push(i); });

    // น้ำหนัก = จำนวนปลายสาย (ข้อมูลเรียงวงต่อวง → ย้อนจากท้ายได้ลูกก่อนพ่อเสมอ)
    const weight = new Float64Array(n);
    for (let i = n - 1; i >= 0; i--) weight[i] = Math.max(1, kids[i].reduce((s, c) => s + weight[c], 0));

    // รัศมีแต่ละวง: ห่างกันอย่างน้อย 80 และยาวพอให้จุดในวงนั้นไม่ทับกัน (~11 หน่วยต่อจุด)
    // วงที่แน่นมากเรียงจุดสลับกันได้ถึง 3 แถว (ห่างแถวละ 16) — วงนอกไม่ต้องขยายจนวงในเล็กเป็นจุด
    const perRing = [];
    nodes.forEach((x) => { perRing[x[2]] = (perRing[x[2]] || 0) + 1; });
    const ring = [0], lanes = [1];
    for (let d = 1; d < perRing.length; d++) {
        const base = ring[d - 1] + 80 + (lanes[d - 1] - 1) * 16;
        const need = ((perRing[d] || 0) * 11) / (2 * Math.PI);
        lanes[d] = Math.min(3, Math.max(1, Math.ceil(need / base)));
        ring[d] = Math.max(base, need / lanes[d]);
    }
    const seen = [];

    const x = new Float64Array(n), y = new Float64Array(n);
    const place = (list, a0, a1) => {
        const total = list.reduce((s, c) => s + weight[c], 0) || 1;
        let a = a0;
        for (const c of list) {
            const span = (a1 - a0) * (weight[c] / total);
            const mid = a + span / 2;
            const d = nodes[c][2];
            seen[d] = (seen[d] || 0) + 1;
            const r = ring[d] + ((seen[d] - 1) % lanes[d]) * 16;
            x[c] = Math.cos(mid) * r; y[c] = Math.sin(mid) * r;
            place(kids[c], a, a + span);
            a += span;
        }
    };
    place(top, -Math.PI / 2, Math.PI * 1.5);
    const last = ring.length - 1;
    return { x, y, ring, top, radius: (ring[last] || 80) + (lanes[last] - 1) * 16 };
}

Alpine.data('gridMap', () => {
    // สถานะผังอยู่นอก reactive ของ Alpine (typed array / Map ขนาดใหญ่ไม่ต้องถูก proxy) — มีแค่ tip ที่ผูกกับหน้า
    const S = {};

    return {
        tip: null,

        init() {
            const el = this.$root.querySelector('script[data-map]');
            S.d = JSON.parse(el?.textContent || '{}');
            S.nodes = S.d.nodes || [];
            S.L = layout(S.nodes);
            S.size = S.nodes.map((x) => 3 + Math.min(10, Math.sqrt(Math.max(0, x[4])) / 2.5));
            S.view = { x: 0, y: 0, k: 1 };
            S.ptrs = new Map();
            S.hover = -1;
            S.cv = this.$refs.cv;
            S.ro = new ResizeObserver(() => { this.resize(); });
            S.ro.observe(S.cv);
            this.resize();
            this.fit();
        },

        destroy() { S.ro?.disconnect(); cancelAnimationFrame(S.raf); },

        resize() {
            const r = S.cv.getBoundingClientRect();
            const dpr = Math.min(2, window.devicePixelRatio || 1);
            S.w = r.width; S.h = r.height;
            S.cv.width = Math.round(r.width * dpr); S.cv.height = Math.round(r.height * dpr);
            S.dpr = dpr;
            this.draw();
        },

        fit() {
            const R = S.L.radius + 40;
            S.view = { x: 0, y: 0, k: Math.min(2, Math.max(0.05, Math.min(S.w, S.h) / (2 * R))) };
            this.tip = null;
            this.draw();
        },

        zoomBy(f, cx = S.w / 2, cy = S.h / 2) {
            const v = S.view;
            const k = Math.min(6, Math.max(0.03, v.k * f));
            // คงจุดใต้เคอร์เซอร์ไว้ที่เดิม
            const wx = (cx - S.w / 2 - v.x) / v.k, wy = (cy - S.h / 2 - v.y) / v.k;
            v.x = cx - S.w / 2 - wx * k; v.y = cy - S.h / 2 - wy * k; v.k = k;
            if (this.tip?.pinned) this.tip = null;
            this.draw();
        },

        screen(i) {
            const v = S.view;
            return [S.w / 2 + v.x + S.L.x[i] * v.k, S.h / 2 + v.y + S.L.y[i] * v.k];
        },

        pick(px, py) {
            let best = -1, bd = Infinity;
            for (let i = 0; i < S.nodes.length; i++) {
                const [sx, sy] = this.screen(i);
                const r = Math.max(3, S.size[i] * Math.min(1.6, S.view.k)) + 5;
                const dx = sx - px, dy = sy - py, dd = dx * dx + dy * dy;
                if (dd < r * r && dd < bd) { bd = dd; best = i; }
            }
            return best;
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
                    this.tip = null;
                    this.draw();
                }
                return;
            }
            const i = this.pick(px, py);
            if (i !== S.hover) {
                S.hover = i;
                S.cv.style.cursor = i >= 0 ? 'pointer' : 'grab';
                if (!this.tip?.pinned) this.tip = i >= 0 ? this.tipFor(i, px, py, false) : null;
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
                const i = this.pick(px, py);
                this.tip = i >= 0 ? this.tipFor(i, px, py, true) : null;
                S.hover = i;
                this.draw();
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
            if (i >= 0) location.href = this.mapUrl(S.nodes[i][0]);
        },

        memberUrl(w) { return String(S.d.memberUrl).replace(S.d.zero, w); },
        mapUrl(w) { const u = new URL(S.d.mapUrl, location.href); u.searchParams.set('root', w); return u.toString(); },

        tipFor(i, px, py, pinned) {
            const x = S.nodes[i];
            return {
                pinned, short: short(x[0]), rank: S.d.ranks[x[3]] || '', own: fmt(x[4], 2), team: fmt(x[5], 2), count: fmt(x[6]),
                fold: x[8] ? fmt(x[8]) : '', held: !!x[7], member: this.memberUrl(x[0]), map: this.mapUrl(x[0]),
                x: Math.min(px + 14, Math.max(8, S.w - 280)), y: Math.min(py + 14, Math.max(8, S.h - 150)),
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
            c.setTransform(dpr, 0, 0, dpr, 0, 0);
            c.clearRect(0, 0, w, h);
            const ox = w / 2 + v.x, oy = h / 2 + v.y;

            // วงสายงาน (วง 1–3 ได้รางวัล = เส้นทึบ)
            c.font = '11px "Noto Sans Thai", system-ui, sans-serif';
            c.textAlign = 'center';
            for (let d = 1; d < L.ring.length; d++) {
                const r = L.ring[d] * v.k;
                c.beginPath();
                c.setLineDash(d <= 3 ? [] : [4, 6]);
                c.strokeStyle = d <= 3 ? 'rgba(16,185,129,.28)' : 'rgba(220,255,240,.10)';
                c.lineWidth = 1;
                c.arc(ox, oy, r, 0, Math.PI * 2);
                c.stroke();
                if (r > 24) { c.fillStyle = d <= 3 ? 'rgba(142,230,168,.8)' : 'rgba(234,244,239,.45)'; c.fillText(`วง ${d}`, ox, oy - r - 5); }
            }
            c.setLineDash([]);

            // เส้นสาย — path เดียว
            const at = S._at || (S._at = new Map(nodes.map((x, i) => [x[0], i])));
            const vis = (sx, sy, m) => sx > -m && sx < w + m && sy > -m && sy < h + m;
            c.beginPath();
            for (let i = 0; i < nodes.length; i++) {
                const p = nodes[i][1] === null ? -1 : (at.get(nodes[i][1]) ?? -1);
                const sx = ox + L.x[i] * v.k, sy = oy + L.y[i] * v.k;
                const tx = p < 0 ? ox : ox + L.x[p] * v.k, ty = p < 0 ? oy : oy + L.y[p] * v.k;
                if (!vis(sx, sy, 40) && !vis(tx, ty, 40)) continue;
                c.moveTo(tx, ty); c.lineTo(sx, sy);
            }
            c.strokeStyle = 'rgba(220,255,240,.16)';
            c.lineWidth = 1;
            c.stroke();

            // จุดกลาง
            c.beginPath();
            c.arc(ox, oy, 9, 0, Math.PI * 2);
            c.fillStyle = S.d.root ? RANK_COLOR[S.d.rootRank ?? 0] : '#eaf4ef';
            c.fill();
            c.fillStyle = 'rgba(234,244,239,.85)';
            c.fillText(S.d.root ? short(S.d.root) : 'ทั้งระบบ', ox, oy + 24);

            // กระเป๋า
            const zs = Math.min(1.6, v.k);
            c.textAlign = 'left';
            for (let i = 0; i < nodes.length; i++) {
                const sx = ox + L.x[i] * v.k, sy = oy + L.y[i] * v.k;
                if (!vis(sx, sy, 20)) continue;
                const x = nodes[i];
                const r = Math.max(2, S.size[i] * zs);
                c.beginPath();
                c.arc(sx, sy, r, 0, Math.PI * 2);
                c.fillStyle = RANK_COLOR[x[3]] || RANK_COLOR[0];
                c.fill();
                if (x[7]) { c.lineWidth = 2; c.strokeStyle = HELD; c.stroke(); }
                if (i === S.hover) { c.lineWidth = 2; c.strokeStyle = '#ffffff'; c.beginPath(); c.arc(sx, sy, r + 3, 0, Math.PI * 2); c.stroke(); }
                if (x[8] && v.k > 0.45) { c.fillStyle = 'rgba(245,200,107,.9)'; c.fillText(`+${fmt(x[8])}`, sx + r + 3, sy + 4); }
            }
        },
    };
});

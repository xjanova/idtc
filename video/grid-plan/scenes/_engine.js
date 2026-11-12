/* ===========================================================
   TPIX Whitepaper Film — deterministic animation engine
   ---------------------------------------------------------
   ทุกอย่างคำนวณจากเวลา t (วินาที) อย่างเดียว ไม่มี rAF /
   ไม่มี CSS keyframe — เพื่อให้เรนเดอร์ทีละเฟรมได้ตรงเป๊ะ
   =========================================================== */

(function () {
    'use strict';

    // ---------- math ----------
    const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
    const clamp01 = (v) => clamp(v, 0, 1);
    const lerp = (a, b, u) => a + (b - a) * u;

    const Ease = {
        linear: (u) => u,
        out: (u) => 1 - Math.pow(1 - u, 3),
        outQuint: (u) => 1 - Math.pow(1 - u, 5),
        in: (u) => u * u * u,
        inOut: (u) => (u < 0.5 ? 4 * u * u * u : 1 - Math.pow(-2 * u + 2, 3) / 2),
        outBack: (u) => 1 + 2.2 * Math.pow(u - 1, 3) + 1.2 * Math.pow(u - 1, 2),
        outExpo: (u) => (u >= 1 ? 1 : 1 - Math.pow(2, -10 * u)),
    };

    /** ความคืบหน้า 0..1 ของช่วงเวลา [a, b] พร้อม easing */
    function win(t, a, b, ease) {
        if (b <= a) return t >= b ? 1 : 0;
        return (ease || Ease.out)(clamp01((t - a) / (b - a)));
    }

    // ---------- number formatting (English labels) ----------
    const fmt = {
        int: (n) => Math.round(n).toLocaleString('en-US'),
        dec: (n, d) => n.toFixed(d),
        compact(n) {
            const a = Math.abs(n);
            if (a >= 1e9) return trim(n / 1e9) + 'B';
            if (a >= 1e6) return trim(n / 1e6) + 'M';
            if (a >= 1e3) return trim(n / 1e3) + 'K';
            return trim(n);
        },
        pct: (n, d) => n.toFixed(d === undefined ? 1 : d) + '%',
        usd: (n) => '$' + n.toFixed(2),
    };
    function trim(v) {
        const s = v.toFixed(v < 10 ? 2 : v < 100 ? 1 : 0);
        // ตัดศูนย์ท้ายเฉพาะที่อยู่หลังจุดทศนิยม — ไม่งั้น 500 จะกลายเป็น 5
        return s.includes('.') ? s.replace(/\.?0+$/, '') : s;
    }

    // ---------- animation presets ----------
    // แต่ละตัวคืน {opacity, transform, filter, clip} จาก u (0..1) ขาเข้า
    const Anim = {
        fade: (u) => ({ o: u }),
        rise: (u) => ({ o: u, ty: lerp(34, 0, u) }),
        drop: (u) => ({ o: u, ty: lerp(-26, 0, u) }),
        left: (u) => ({ o: u, tx: lerp(-46, 0, u) }),
        right: (u) => ({ o: u, tx: lerp(46, 0, u) }),
        scale: (u) => ({ o: u, s: lerp(0.9, 1, u) }),
        pop: (u) => ({ o: clamp01(u * 1.8), s: lerp(0.82, 1, Ease.outBack(u)) }),
        blur: (u) => ({ o: u, b: lerp(14, 0, u), s: lerp(1.04, 1, u) }),
        wipe: (u) => ({ o: 1, clip: `inset(0 ${(1 - u) * 100}% 0 0)` }),
        wipeUp: (u) => ({ o: 1, clip: `inset(${(1 - u) * 100}% 0 0 0)` }),
        none: () => ({ o: 1 }),
    };
    const AnimOut = {
        fade: (u) => ({ o: 1 - u }),
        rise: (u) => ({ o: 1 - u, ty: lerp(0, -22, u) }),
        sink: (u) => ({ o: 1 - u, ty: lerp(0, 22, u) }),
        scale: (u) => ({ o: 1 - u, s: lerp(1, 1.05, u) }),
        blur: (u) => ({ o: 1 - u, b: lerp(0, 12, u) }),
        none: () => ({ o: 1 }),
    };

    function paint(el, st) {
        el.style.opacity = st.o === undefined ? 1 : st.o;
        const tx = st.tx || 0, ty = st.ty || 0, s = st.s === undefined ? 1 : st.s;
        el.style.transform =
            tx || ty || s !== 1 ? `translate3d(${tx}px, ${ty}px, 0) scale(${s})` : '';
        el.style.filter = st.b ? `blur(${st.b}px)` : '';
        el.style.clipPath = st.clip || '';
    }

    // ---------- engine ----------
    const TPIX = {
        duration: 10,
        frame: 0,
        t: 0,
        _custom: [],
        _cues: [],
        _autos: null,
        _cam: null,
        ready: false,

        /** ลงทะเบียนฉาก */
        init(opts) {
            resolveTiming(opts.timing);
            this.duration = opts.duration;
            this._cues = opts.cues || [];
            if (opts.camera) this._cam = opts.camera;
            buildChrome(opts);
            this._autos = collectAutos();
            this.ready = true;
            return this;
        },

        /** ตรรกะเคลื่อนไหวเฉพาะฉาก — เรียกทุกเฟรม */
        custom(fn) { this._custom.push(fn); return this; },

        /** ทยอยโผล่ทีละชิ้น */
        stagger(sel, start, step, opts) {
            const o = opts || {};
            document.querySelectorAll(sel).forEach((el, i) => {
                el.dataset.in = (start + i * step).toFixed(3);
                if (o.anim) el.dataset.anim = o.anim;
                if (o.dur) el.dataset.dur = o.dur;
                if (o.out) el.dataset.out = o.out;
            });
            return this;
        },

        /** ไปยังเวลา t แล้ววาดสถานะทั้งหมด (ต้องเป็น synchronous) */
        seek(t) {
            this.t = t;
            this.frame = Math.round(t * 30);

            if (this._autos === null) this._autos = collectAutos();
            for (const a of this._autos) applyAuto(a, t);

            for (const fn of this._custom) fn(t, this);

            paintCues(this._cues, t);
            paintChrome(t, this);
        },

        // เปิดใช้ helper ให้ฉากเรียกได้
        win, clamp, clamp01, lerp, Ease, fmt, Anim, lineTime,
    };

    // ---------- timing from narration ----------
    // data-at="l10"        → data-in = เริ่มประโยค l10
    // data-at="l10.end-1"  → data-in = จบประโยค l10 ลบ 1 วินาที
    // data-out-at="l14-0.6"→ data-out (ออก) ก่อนประโยค l14 เริ่ม 0.6 วินาที
    function lineTime(spec, timing) {
        const m = /^(l\d+)(?:\.(\d+))?(\.end)?([+-][\d.]+)?$/.exec(spec.trim());
        if (!m) throw new Error('bad time spec ' + spec);
        const cues = timing.lines.filter((c) => c.line === m[1]);
        if (!cues.length) throw new Error('unknown line ' + m[1]);
        let base;
        if (m[2]) base = cues[parseInt(m[2], 10) - 1][m[3] ? 't1' : 't0'];
        else base = m[3] ? cues[cues.length - 1].t1 : cues[0].t0;
        return base + (m[4] ? parseFloat(m[4]) : 0);
    }
    function resolveTiming(timing) {
        if (!timing) return;
        document.querySelectorAll('[data-at]').forEach((el) => {
            el.dataset.in = lineTime(el.dataset.at, timing).toFixed(3);
        });
        document.querySelectorAll('[data-out-at]').forEach((el) => {
            el.dataset.out = lineTime(el.dataset.outAt, timing).toFixed(3);
        });
    }

    // ---------- auto animations from data-attributes ----------
    function collectAutos() {
        return Array.from(document.querySelectorAll('[data-in]')).map((el) => ({
            el,
            t0: parseFloat(el.dataset.in),
            dur: parseFloat(el.dataset.dur || 0.75),
            anim: Anim[el.dataset.anim || 'rise'] || Anim.rise,
            t1: el.dataset.out !== undefined ? parseFloat(el.dataset.out) : null,
            odur: parseFloat(el.dataset.odur || 0.45),
            oanim: AnimOut[el.dataset.oanim || 'fade'] || AnimOut.fade,
            ease: Ease[el.dataset.ease || 'out'] || Ease.out,
            // ตัวช่วยพิเศษ
            count: el.dataset.count !== undefined ? parseFloat(el.dataset.count) : null,
            countFrom: parseFloat(el.dataset.countFrom || 0),
            countFmt: el.dataset.countFmt || 'int',
            countDec: parseInt(el.dataset.countDec || 0, 10),
            countPre: el.dataset.countPre || '',
            countPost: el.dataset.countPost || '',
            bar: el.dataset.bar !== undefined ? parseFloat(el.dataset.bar) : null,
            draw: el.dataset.draw !== undefined,
            drawTo: parseFloat(el.dataset.drawTo || 1),
            type: el.dataset.type || null,
        }));
    }

    function applyAuto(a, t) {
        const el = a.el;

        // ยังไม่ถึงคิว → ซ่อน
        if (t < a.t0) { el.style.opacity = 0; el.style.transform = ''; el.style.filter = ''; return; }

        const uIn = a.ease(clamp01((t - a.t0) / a.dur));
        let st = a.anim(uIn);

        if (a.t1 !== null && t >= a.t1) {
            const uOut = Ease.inOut(clamp01((t - a.t1) / a.odur));
            const so = a.oanim(uOut);
            st = { ...st, ...so, o: (st.o === undefined ? 1 : st.o) * (so.o === undefined ? 1 : so.o) };
        }
        paint(el, st);

        // ตัวเลขวิ่ง
        if (a.count !== null) {
            const v = lerp(a.countFrom, a.count, Ease.outQuint(uIn));
            const body =
                a.countFmt === 'compact' ? fmt.compact(v)
                : a.countFmt === 'pct' ? fmt.pct(v, a.countDec)
                : a.countFmt === 'dec' ? fmt.dec(v, a.countDec)
                : a.countFmt === 'usd' ? fmt.usd(v)
                : fmt.int(v);
            el.textContent = a.countPre + body + a.countPost;
        }

        // แถบเติม
        if (a.bar !== null) el.style.setProperty('--p', (a.bar * Ease.outQuint(uIn)).toFixed(4));

        // เส้น SVG ค่อย ๆ ลาก
        if (a.draw) {
            if (!a._len) { a._len = el.getTotalLength ? el.getTotalLength() : 1000; el.style.strokeDasharray = a._len; }
            el.style.strokeDashoffset = a._len * (1 - a.drawTo * Ease.inOut(uIn));
        }

        // พิมพ์ทีละตัว
        if (a.type) {
            const n = Math.round(a.type.length * Ease.linear(clamp01((t - a.t0) / a.dur)));
            el.textContent = a.type.slice(0, n);
        }
    }

    // ---------- persistent chrome (bg, header, subs, progress) ----------
    function buildChrome(opts) {
        const stage = document.getElementById('stage');

        // ⚠️ ห้ามใช้ insertBefore ทีละชิ้น — มันจะกลับลำดับ ทำให้ bg-base ทึบไปทับ
        //    grid / grain / vignette จนมองไม่เห็นทั้งสามชั้น
        const bg = document.createElement('div');
        bg.className = 'bg-layers';
        bg.innerHTML =
            '<div class="bg-base"></div><div class="bg-grid"></div>' +
            '<div class="bg-grain"></div><div class="bg-vignette"></div>';
        stage.insertBefore(bg, stage.firstChild);

        // grain texture (deterministic, generated once)
        document.documentElement.style.setProperty('--grain-url', `url("${grainDataURL()}")`);

        if (opts.chapter) {
            const rail = document.createElement('div');
            rail.className = 'chapter-rail';
            rail.id = 'chapter-rail';
            rail.innerHTML =
                '<span class="dot"></span>' +
                `<span>${opts.series || 'IDTC Grid'}</span><span class="sep">/</span>` +
                `<span class="cur">${opts.chapter}</span>`;
            stage.appendChild(rail);
        }

        if (opts.mark !== false) {
            const m = document.createElement('img');
            m.className = 'mark';
            m.id = 'corner-mark';
            m.src = opts.markSrc || '../assets/idtc-512.png';
            stage.appendChild(m);
        }

        const subs = document.createElement('div');
        subs.id = 'subs';
        subs.innerHTML = '<div class="cue"></div>' + (opts.subEn ? '<div class="cue-en"></div>' : '');
        stage.appendChild(subs);

        const pr = document.createElement('div');
        pr.id = 'progress';
        stage.appendChild(pr);
    }

    function paintChrome(t, eng) {
        // ส่วนหัว/โลโก้: เฟดเข้าตอนต้น เฟดออกตอนท้าย
        const fadeIn = win(t, 0.35, 1.35, Ease.out);
        const fadeOut = 1 - win(t, eng.duration - 1.0, eng.duration - 0.2, Ease.inOut);
        const a = fadeIn * fadeOut;
        const rail = document.getElementById('chapter-rail');
        if (rail) rail.style.opacity = a * 1;
        const mark = document.getElementById('corner-mark');
        if (mark) mark.style.opacity = a * 0.55;

        // แถบความคืบหน้า
        const pr = document.getElementById('progress');
        if (pr) pr.style.width = (clamp01(t / eng.duration) * 1920).toFixed(1) + 'px';

        // เกรนฟิล์ม: ขยับแบบสุ่มคงที่ตามหมายเลขเฟรม
        const f = eng.frame;
        const gx = ((f * 73) % 211) - 105;
        const gy = ((f * 149) % 197) - 98;
        document.documentElement.style.setProperty('--grain-x', gx + 'px');
        document.documentElement.style.setProperty('--grain-y', gy + 'px');

        // กล้องดันเข้าช้า ๆ
        if (eng._cam) {
            const c = eng._cam;
            const u = clamp01(t / eng.duration);
            const s = lerp(c.from === undefined ? 1 : c.from, c.to === undefined ? 1.04 : c.to, Ease.inOut(u));
            const fr = document.querySelector('.frame');
            if (fr) fr.style.transform = `scale(${s})`;
        }
    }

    // ---------- Thai subtitles ----------
    function paintCues(cues, t) {
        const box = document.querySelector('#subs .cue');
        if (!box) return;
        let active = null;
        for (const c of cues) {
            if (t >= c.t0 && t < c.t1) { active = c; break; }
        }
        // ซับอังกฤษตัวเล็กใต้ซับไทย (เฉพาะฉากที่เปิด subEn)
        const en = document.querySelector('#subs .cue-en');
        if (!active) { box.textContent = ''; box.style.opacity = 0; if (en) { en.textContent = ''; en.style.opacity = 0; } return; }
        if (box.textContent !== active.th) box.textContent = active.th;
        // เฟดสั้น ๆ 120ms หัวท้าย ให้ตาไม่สะดุด
        const inU = clamp01((t - active.t0) / 0.12);
        const outU = clamp01((active.t1 - t) / 0.12);
        box.style.opacity = Math.min(inU, outU);
        if (en) {
            const txt = active.en || '';
            if (en.textContent !== txt) en.textContent = txt;
            en.style.opacity = txt ? Math.min(inU, outU) : 0;
        }
    }

    // ---------- deterministic grain ----------
    function grainDataURL() {
        const N = 120;
        const c = document.createElement('canvas');
        c.width = c.height = N;
        const ctx = c.getContext('2d');
        const img = ctx.createImageData(N, N);
        let seed = 20260822;
        const rnd = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 4294967296);
        for (let i = 0; i < N * N; i++) {
            const v = 110 + rnd() * 90;
            img.data[i * 4] = img.data[i * 4 + 1] = img.data[i * 4 + 2] = v;
            img.data[i * 4 + 3] = 255;
        }
        ctx.putImageData(img, 0, 0);
        return c.toDataURL('image/png');
    }

    window.TPIX = TPIX;
})();

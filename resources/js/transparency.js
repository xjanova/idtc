/**
 * หน้า /transparency — แดชบอร์ดสัญญาล็อก 70% (TrancheVesting) อ่านสดจากเชน
 *
 * สูตรเดียวกับสัญญา: งวดที่ k ของก้อนหนึ่งปลดที่เวลา start + (cliff + k) × period
 * ก่อนสัญญาขึ้นเชน แสดง "แผน" จาก config/idtc.php (ไม่มีวันเริ่ม จึงไม่มีนับถอยหลัง)
 */
import Alpine from 'alpinejs';
import { Contract, Interface } from 'ethers';
import {
    ABI, CFG, assertWallet, chainNow, connectWallet, contractAddress, decodeRevert, explorerAddress, explorerTx,
    formatAmount, readProvider, sendTx, short, splitDuration, syncChainClock, thaiDate, waitReceipt, walletError, withTimeout,
} from './chain';

const DEC = Number(CFG.token?.decimals ?? 18);
const ONE = 10n ** BigInt(DEC);
const VEST_ABI = [...ABI.vesting, ...ABI.errors, 'event Released(address indexed to, uint256 amount)'];
const vestIface = new Interface(VEST_ABI);

const trancheName = (cliff) => (cliff % 12 === 0 ? `ล็อก ${cliff / 12} ปี` : `ล็อก ${cliff} เดือน`);

Alpine.data('lockDashboard', (plan) => ({
    vestAddr: contractAddress('vesting'),
    tokenAddr: contractAddress('token'),

    loaded: false,
    loading: false,
    loadError: '',
    tokenMismatch: false,

    start: 0,
    period: Number(plan?.period || 2629800),
    tranches: (plan?.tranches || []).map((t) => ({ name: t.name, amount: BigInt(t.amount) * ONE, cliff: Number(t.cliff), release: Number(t.release) })),
    total: 0n,
    released: 0n,
    releasable: 0n,
    locked: 0n,
    balance: null,
    funded: null,
    beneficiary: null,
    nextAt: 0,
    nextAmount: 0n,

    now: chainNow(),
    bucket: -1,

    // ปุ่มปลดงวด
    confirming: false,
    busy: false,
    phase: '',
    flowId: 0,
    error: '',
    errorTx: '',
    success: null,

    short,
    explorerAddress,
    explorerTx,

    get deployed() { return !!this.vestAddr; },

    init() {
        if (!this.deployed) {
            this.total = this.tranches.reduce((a, t) => a + t.amount, 0n);
            this.locked = this.total;
            return;
        }
        this._tick = setInterval(() => this.tick(), 1000);
        this._poll = setInterval(() => { if (!document.hidden && !this.busy) this.load(); }, 30000);
        syncChainClock().then(() => this.tick());
        this.load();
    },

    destroy() { clearInterval(this._tick); clearInterval(this._poll); },

    tick() {
        this.now = chainNow();
        if (this.start && this.period) {
            const b = this.now < this.start ? -1 : Math.floor((this.now - this.start) / this.period);
            if (b !== this.bucket) this.bucket = b;
        }
        // ถึงเวลางวดถัดไป → อ่านสัญญาใหม่ครั้งเดียว (ยอดที่กดปลดได้จะเพิ่มขึ้น)
        if (this.nextAt && this.now >= this.nextAt && this._reloadedFor !== this.nextAt) {
            this._reloadedFor = this.nextAt;
            setTimeout(() => this.load(), 2500);
        }
    },

    vestC() { return this._v || (this._v = new Contract(this.vestAddr, VEST_ABI, readProvider())); },

    async load() {
        if (!this.deployed || this.loading) return;
        this.loading = true;
        const v = this.vestC();
        try {
            const [token, beneficiary, start, period, total, released, releasable, locked, funded, next, count] = await withTimeout(Promise.all([
                v.token(), v.beneficiary(), v.start(), v.period(), v.totalAllocation(), v.released(), v.releasable(),
                v.lockedAmount(), v.isFunded(), v.nextUnlock(), v.trancheCount(),
            ]), 15000);
            const n = Number(count);
            const rows = await withTimeout(Promise.all(Array.from({ length: n }, (_, i) => v.tranche(i))), 15000);
            const balance = await withTimeout(new Contract(token, ABI.erc20, readProvider()).balanceOf(this.vestAddr), 10000).catch(() => null);
            Object.assign(this, {
                beneficiary, start: Number(start), period: Number(period), total, released, releasable, locked, funded, balance,
                nextAt: Number(next[0]), nextAmount: next[1],
            });
            this.tranches = rows.map((t) => {
                const cliff = Number(t.cliffPeriods);
                return { name: trancheName(cliff), amount: t.amount, cliff, release: Number(t.releasePeriods) };
            });
            this.tokenMismatch = !!this.tokenAddr && token.toLowerCase() !== this.tokenAddr.toLowerCase();
            if (!this.tokenAddr) this.tokenAddr = token;
            this.loaded = true;
            this.loadError = '';
            this.bucket = -2; // บังคับคำนวณช่องไทม์ไลน์ใหม่
            this.tick();
        } catch (e) {
            this.loadError = e?.code === 'BAD_DATA' || e?.code === 'CALL_EXCEPTION'
                ? 'อ่านสัญญาล็อกไม่ได้ ที่อยู่สัญญาอาจไม่ถูกต้อง'
                : 'อ่านข้อมูลจากเชนไม่ได้ (เครือข่ายช้าหรือหลุด) ระบบจะลองใหม่เอง';
        } finally {
            this.loading = false;
        }
    },

    /* ───────── ตัวเลขรวม ───────── */
    get live() { return this.deployed && this.loaded; },
    get cells() { return Math.max(60, ...this.tranches.map((t) => t.cliff + t.release)); },
    get pctReleased() { return this.total > 0n ? Number((this.released * 10000n) / this.total) / 100 : 0; },

    /** ล้านเหรียญ: 220000000000 → "220,000 ล้าน" (ระหว่างรออ่านสัญญาแสดง … ไม่โชว์ 0 หลอก) */
    million(v) { return this.deployed && !this.loaded ? '…' : formatAmount(v ?? 0n, DEC + 6, 2, { trim: true }); },
    full(v, d = 2) { return this.deployed && !this.loaded ? '…' : v === null || v === undefined ? '—' : formatAmount(v, DEC, d); },
    thaiDate,

    /* ───────── ก้อนล็อก ───────── */
    /**
     * งวดที่ถึงกำหนดแล้วของก้อนนี้ = floor((now − start) / period) − cliff (ตรงกับ vestedAmount ในสัญญา)
     * ใช้ bucket (เปลี่ยนเฉพาะตอนข้ามงวด) แทน now เพื่อไม่ให้ไทม์ไลน์วาดใหม่ทุกวินาที
     */
    vestedCount(t) {
        if (!this.live || this.bucket < 0) return 0;
        return Math.max(0, Math.min(t.release, this.bucket - t.cliff));
    },
    cellsFor(t) {
        const done = this.vestedCount(t);
        const nowIdx = this.live && this.bucket >= 0 ? this.bucket : -1;
        let html = '';
        for (let m = 0; m < this.cells; m++) {
            let c = m < t.cliff ? 'lock' : m < t.cliff + done ? 'rel' : m < t.cliff + t.release ? 'due' : '';
            if (m === nowIdx) c += ' now';
            html += `<i class="${c}"></i>`;
        }
        return html;
    },
    firstUnlock(t) {
        return this.live ? thaiDate(this.start + (t.cliff + 1) * this.period) : `เดือนที่ ${t.cliff + 1} หลังวันเริ่มล็อก`;
    },
    lastUnlock(t) {
        return this.live ? thaiDate(this.start + (t.cliff + t.release) * this.period) : `เดือนที่ ${t.cliff + t.release}`;
    },
    perInstallment(t) { return t.release ? t.amount / BigInt(t.release) : 0n; },

    /* ───────── งวดถัดไป ───────── */
    get nextLabel() {
        if (!this.live) {
            const first = [...this.tranches].sort((a, b) => a.cliff - b.cliff)[0];
            return first ? `ก้อน "${first.name}" งวดที่ 1 · เดือนที่ ${first.cliff + 1} หลังวันเริ่มล็อก` : '';
        }
        if (!this.nextAt) return 'ปลดครบทุกงวดแล้ว';
        const parts = this.tranches
            .map((t) => {
                const cliffEnd = this.start + t.cliff * this.period;
                const k = (this.nextAt - cliffEnd) / this.period;
                return Number.isInteger(k) && k >= 1 && k <= t.release ? `ก้อน "${t.name}" งวดที่ ${k}/${t.release}` : null;
            })
            .filter(Boolean);
        return parts.join(' + ') || thaiDate(this.nextAt, true);
    },
    get nextAmountPlan() {
        const first = [...this.tranches].sort((a, b) => a.cliff - b.cliff)[0];
        return first ? this.perInstallment(first) : 0n;
    },
    get cd() {
        if (!this.live || !this.nextAt) return null;
        return splitDuration(this.nextAt - this.now);
    },
    pad(v) { return String(v).padStart(2, '0'); },

    /* ───────── กดปลดงวด (ใครก็กดได้ เหรียญไปที่ผู้รับเท่านั้น) ───────── */
    get canRelease() { return this.live && this.releasable > 0n && !this.busy; },

    askRelease() {
        if (!this.canRelease) return;
        this.error = ''; this.errorTx = ''; this.success = null;
        this.confirming = true;
    },

    cancelRelease() {
        if (this.busy && this.phase === 'wait') return;
        if (this.busy) { this.flowId++; this.busy = false; this.phase = ''; }
        this.confirming = false;
    },

    async release() {
        if (this.busy || !this.live) return;
        const id = ++this.flowId;
        const alive = () => { if (id !== this.flowId) throw Object.assign(new Error('cancelled'), { code: 'CANCELLED' }); };
        this.busy = true; this.error = ''; this.errorTx = ''; this.success = null;
        try {
            this.phase = 'connect';
            const { account, signer } = await connectWallet();
            window.dispatchEvent(new CustomEvent('idtc:account', { detail: account }));
            alive();
            this.phase = 'sign';
            const tx = await this.vestC().release.populateTransaction();
            await assertWallet(account);
            let gas;
            try {
                gas = await withTimeout(readProvider().estimateGas({ ...tx, from: account }), 15000);
            } catch (e) {
                // RPC ของเชน TPIX ไม่ส่งเหตุผลกลับมา — อ่านยอดใหม่ ถ้ายอดที่ปลดได้เป็น 0 แปลว่ามีคนกดไปก่อนแล้ว
                if (e?.code !== 'CALL_EXCEPTION') throw e;
                await this.load();
                throw this.releasable === 0n ? Object.assign(new Error('nothing'), { revert: { name: 'NothingToRelease', args: [] } }) : e;
            }
            const hash = await sendTx(signer, { ...tx, gasLimit: (gas * 12n) / 10n + 10000n });
            alive();
            this.phase = 'wait'; this.errorTx = hash;
            const r = await waitReceipt(hash);
            let amount = null;
            for (const log of r.logs) {
                try { const p = vestIface.parseLog(log); if (p?.name === 'Released') amount = p.args.amount; } catch { /* log ของเหรียญ */ }
            }
            this.errorTx = '';
            this.success = { hash, amount };
            this.confirming = false;
        } catch (e) {
            if (e?.code === 'CANCELLED') return;
            const d = decodeRevert(e);
            this.error = d?.name === 'NothingToRelease' ? 'ยังไม่มีงวดที่ถึงเวลาปลด (อาจมีคนกดปลดไปก่อนแล้ว)' : walletError(e);
            this.errorTx = e?.hash || (this.phase === 'wait' ? this.errorTx : '');
        } finally {
            if (id === this.flowId) { this.busy = false; this.phase = ''; }
            this.load();
        }
    },

    get phaseText() {
        return { connect: 'เปิดกระเป๋าเพื่อเชื่อมต่อ…', sign: 'ยืนยันรายการ "release" ในกระเป๋า…', wait: 'รอเชนยืนยัน…' }[this.phase] || '';
    },
}));

/* ───────────── สถานะ verified จาก explorer (Blockscout เปิด CORS ให้ทุกโดเมน) ───────────── */
const verifyCache = new Map();
function fetchVerified(address) {
    if (!verifyCache.has(address)) {
        const ctl = new AbortController();
        const t = setTimeout(() => ctl.abort(), 8000);
        // /addresses/{a} เล็กราว 1 KB — /smart-contracts/{a} แนบซอร์สโค้ดทั้งไฟล์มาด้วย (หลายร้อย KB)
        const url = `${String(CFG.chain.explorer).replace(/\/$/, '')}/api/v2/addresses/${address}`;
        verifyCache.set(address, fetch(url, { signal: ctl.signal, headers: { accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
            .then((j) => (j?.is_contract === false ? 'notcontract' : j?.is_verified ? 'verified' : 'unverified'))
            .catch(() => 'unknown')
            .finally(() => clearTimeout(t)));
    }
    return verifyCache.get(address);
}

Alpine.data('verifyBadge', (address) => ({
    state: address ? 'checking' : 'none',
    init() {
        if (address) fetchVerified(address).then((s) => { this.state = s; });
    },
    get label() {
        return { checking: 'กำลังตรวจ…', verified: '✓ verified', unverified: 'ยังไม่ verify', notcontract: 'ไม่พบสัญญา', unknown: 'ตรวจบน explorer ↗', none: 'รอ deploy' }[this.state];
    },
    get cls() {
        return { verified: 'pill-ok', unverified: 'pill-warn', notcontract: 'pill-warn' }[this.state] || 'pill-wait';
    },
}));

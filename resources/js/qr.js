/**
 * QR code ของลิงก์ชวน — สร้างในเบราว์เซอร์ (qrcode-generator) ไม่ส่งลิงก์ไปบริการภายนอก
 *
 * Alpine.store('qr'): กล่อง QR ตัวเดียวของทั้งหน้า (partials/qr-modal) — ปุ่มไหนก็เรียก $store.qr.show(ลิงก์, ป้าย, ชื่อไฟล์)
 * ใช้ทั้งหน้าเว็บ (/grid, /binary) และหลังบ้าน (ผังสายงาน Binary)
 */
import Alpine from 'alpinejs';
import qrcode from 'qrcode-generator';

/** PNG data URL: โมดูลดำบนพื้นขาว ขอบเงียบ 4 โมดูล (สแกนง่ายทั้งจอมืดและกระดาษ) */
export function qrPng(text, px = 10) {
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    const n = qr.getModuleCount();
    const quiet = 4;
    const size = (n + quiet * 2) * px;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const g = canvas.getContext('2d');
    g.fillStyle = '#ffffff';
    g.fillRect(0, 0, size, size);
    g.fillStyle = '#000000';
    for (let r = 0; r < n; r++) {
        for (let c = 0; c < n; c++) {
            if (qr.isDark(r, c)) g.fillRect((c + quiet) * px, (r + quiet) * px, px, px);
        }
    }
    return canvas.toDataURL('image/png');
}

Alpine.store('qr', {
    open: false,
    text: '',
    label: '',
    src: '',
    name: 'idtc-invite',
    copied: false,
    failed: false,

    show(text, label = '', name = 'idtc-invite') {
        if (!text) return;
        Object.assign(this, { text, label, name, copied: false, failed: false });
        try { this.src = qrPng(text); } catch { this.src = ''; this.failed = true; }
        this.open = true;
        setTimeout(() => document.querySelector('[data-qr-close]')?.focus(), 60);
    },
    close() { this.open = false; },
    download() {
        if (!this.src) return;
        const a = document.createElement('a');
        a.href = this.src;
        a.download = `${this.name}.png`;
        document.body.appendChild(a);
        a.click();
        a.remove();
    },
    async copy() {
        try {
            await navigator.clipboard.writeText(this.text);
            this.copied = true;
            clearTimeout(this._t);
            this._t = setTimeout(() => { this.copied = false; }, 1600);
        } catch { /* เบราว์เซอร์ไม่ให้คัดลอก — เลือกข้อความในช่องเองได้ */ }
    },
});

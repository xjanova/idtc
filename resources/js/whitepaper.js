/**
 * หน้า /whitepaper — สารบัญด้านข้างที่ไฮไลต์หัวข้อที่กำลังอ่าน (scroll-spy)
 */
import Alpine from 'alpinejs';

Alpine.data('wpToc', () => ({
    active: '',
    open: false,
    init() {
        const heads = [...document.querySelectorAll('.wp-body h2[id]')];
        if (!heads.length) return;
        this.active = heads[0].id;
        let queued = false;
        const update = () => {
            queued = false;
            // หัวข้อล่าสุดที่เลื่อนผ่านเส้นใต้แถบเมนูแล้ว = หัวข้อที่กำลังอ่าน
            const line = 120;
            let cur = heads[0].id;
            for (const h of heads) {
                if (h.getBoundingClientRect().top - line <= 0) cur = h.id;
                else break;
            }
            // เลื่อนจนสุดหน้า: หัวข้อท้าย ๆ อาจขึ้นไม่ถึงเส้น ให้เลือกหัวข้อสุดท้าย
            if (innerHeight + scrollY >= document.documentElement.scrollHeight - 4) cur = heads[heads.length - 1].id;
            this.active = cur;
        };
        this._onScroll = () => { if (!queued) { queued = true; requestAnimationFrame(update); } };
        addEventListener('scroll', this._onScroll, { passive: true });
        addEventListener('resize', this._onScroll, { passive: true });
        update();
    },
    destroy() {
        removeEventListener('scroll', this._onScroll);
        removeEventListener('resize', this._onScroll);
    },
    pick() { this.open = false; },
    print() { window.print(); },
}));

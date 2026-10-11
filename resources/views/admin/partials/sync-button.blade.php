@if ($me->canWrite())
    <form method="POST" action="{{ route('admin.sales.sync') }}" x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault(); return; } busy = true">
        @csrf
        <button class="btn btn-ghost btn-sm" type="submit" :disabled="busy" title="ดึงยอดซื้อล่าสุดจากเชนเดี๋ยวนี้ (ปกติระบบดึงเองทุกนาที)">
            <span x-show="busy" x-cloak class="spin" aria-hidden="true"></span>
            <x-admin.icon name="refresh" x-show="!busy"/>
            <span x-text="busy ? 'กำลังซิงก์…' : 'ซิงก์ตอนนี้'">ซิงก์ตอนนี้</span>
        </button>
    </form>
@endif

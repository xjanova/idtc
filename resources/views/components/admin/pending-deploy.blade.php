@props(['what', 'key' => null])
{{-- สัญญายังไม่ขึ้นเชน / ยังไม่ได้ตั้งที่อยู่ --}}
<div {{ $attributes->class('empty') }}>
    <x-admin.icon name="clock"/>
    <b>รอ deploy {{ $what }}</b>
    <span>ยังไม่มีที่อยู่สัญญา ข้อมูลจะขึ้นเองทันทีที่ตั้งที่อยู่ในหน้าตั้งค่าเหรียญ (หรือใน .env)</span>
    @if (isset($me) && $me->canWrite())
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.settings') }}">ไปหน้าตั้งค่าเหรียญ</a>
    @endif
</div>

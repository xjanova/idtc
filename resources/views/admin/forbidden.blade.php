@extends('layouts.admin')

@section('content')
    @php $home = $me->homeRoute(); @endphp
    <div class="empty" style="max-width:560px">
        <x-admin.icon name="lock"/>
        <b>{{ $message }}</b>
        <span>สิทธิ์ของคุณตอนนี้: {{ $me->roleLabel() }} — ถ้าต้องการสิทธิ์เพิ่ม ให้เจ้าของติ๊กสิทธิ์ให้ในหน้า "ผู้ดูแล"</span>
        @if ($home !== null)
            <a class="btn btn-ghost btn-sm" href="{{ route($home) }}">กลับหน้าแรกของคุณ</a>
        @else
            <span>ตอนนี้ยังไม่มีส่วนไหนที่คุณเปิดได้ — เปลี่ยนรหัสผ่านหรือออกจากระบบได้จากเมนูด้านซ้าย</span>
        @endif
    </div>
@endsection

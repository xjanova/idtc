@extends('layouts.admin')

@section('content')
    <div class="empty" style="max-width:560px">
        <x-admin.icon name="lock"/>
        <b>{{ $message }}</b>
        <span>สิทธิ์ของคุณตอนนี้: {{ $me->roleLabel() }} — ถ้าต้องการสิทธิ์เพิ่ม ให้เจ้าของเปลี่ยนในหน้า "ผู้ดูแล"</span>
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">กลับหน้าภาพรวม</a>
    </div>
@endsection

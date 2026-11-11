@extends('layouts.admin')
@php use App\Services\Admin\AdminPasswords as P; use App\Support\AdminFormat as F; @endphp

@section('sub')
    รหัสผ่านชั้นที่สอง — ใส่หลังเซ็นข้อความในกระเป๋าทุกครั้งที่เข้าระบบ · เจ้าของ (Owner) ต้องมีเสมอ
@endsection

@section('content')
    <div class="grid-main">
        <form class="panel form" method="POST" action="{{ route('admin.password.update') }}" x-data="safeForm" @submit="submit($event)" autocomplete="off">
            @csrf
            <h3>{{ $hasPassword ? 'เปลี่ยนรหัสผ่าน' : 'ตั้งรหัสผ่าน' }}</h3>
            {{-- ช่วยตัวจัดการรหัสผ่านของเบราว์เซอร์จับคู่กับกระเป๋านี้ --}}
            <input type="text" name="username" value="{{ $me->checksum() }}" autocomplete="username" hidden readonly>
            @if ($hasPassword)
                <label class="field"><span>รหัสผ่านปัจจุบัน</span><input class="input" type="password" name="current_password" autocomplete="current-password" required></label>
            @endif
            <label class="field"><span>รหัสผ่านใหม่</span><input class="input" type="password" name="password" minlength="{{ P::MIN_LENGTH }}" autocomplete="new-password" required></label>
            <label class="field"><span>ยืนยันรหัสผ่านใหม่</span><input class="input" type="password" name="password_confirmation" minlength="{{ P::MIN_LENGTH }}" autocomplete="new-password" required></label>
            <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกรหัสผ่าน</button></div>
            <p class="hint">อย่างน้อย {{ P::MIN_LENGTH }} ตัวอักษร ไม่เกิน 72 ไบต์ (อักษรไทยราว 24 ตัว) · เครื่องอื่นที่เข้าระบบด้วยกระเป๋านี้อยู่จะถูกออกจากระบบ · ใส่รหัสผ่านปัจจุบันผิด {{ P::MAX_ATTEMPTS }} ครั้ง กระเป๋านี้ถูกล็อก {{ intdiv(P::LOCK_SECONDS, 60) }} นาที</p>
        </form>

        <div class="panel">
            <h3>สถานะ</h3>
            <ul class="hint" style="margin:0;padding-left:18px;display:grid;gap:6px">
                <li>กระเป๋า <span class="mono">{{ $me->checksum() }}</span></li>
                <li>{{ $hasPassword ? 'ตั้งรหัสผ่านแล้ว'.($changedAt ? ' · เปลี่ยนล่าสุด '.F::dateTime($changedAt) : '') : 'ยังไม่ได้ตั้งรหัสผ่าน' }}</li>
                <li>{{ $required ? 'ต้องใส่รหัสผ่านทุกครั้งที่เข้าระบบ' : 'ตอนนี้เข้าระบบด้วยกระเป๋าอย่างเดียว — ตั้งรหัสผ่านแล้วจะต้องใส่ทุกครั้ง (แนะนำ)' }}</li>
                <li>ลืมรหัสผ่าน: ให้เจ้าของคนอื่นกด "รีเซ็ตรหัสผ่าน" ในหน้า "ผู้ดูแล" · ถ้าเป็นเจ้าของคนเดียว ผู้ดูแลเซิร์ฟเวอร์รัน <code>php artisan idtc:admin-password-reset &lt;ที่อยู่&gt;</code></li>
            </ul>
        </div>
    </div>
@endsection

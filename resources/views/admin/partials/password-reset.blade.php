@if ($address !== $me->address)
    <form method="POST" action="{{ route('admin.admins.password.reset') }}" style="display:inline"
          x-data="safeForm('รีเซ็ตรหัสผ่านของผู้ดูแลนี้?', @js(['กระเป๋า '.\App\Support\AdminFormat::addr($address), 'ผู้ดูแลคนนี้ถูกออกจากระบบทันที', 'ต้องเซ็นกระเป๋าแล้วตั้งรหัสผ่านใหม่ตอนเข้าครั้งถัดไป']), true)" @submit="submit($event)">
        @csrf
        <input type="hidden" name="address" value="{{ $address }}">
        <button class="btn btn-ghost btn-sm" type="submit" :disabled="busy">รีเซ็ตรหัสผ่าน</button>
    </form>
@endif

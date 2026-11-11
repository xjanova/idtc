@extends('layouts.admin')
@php use App\Support\AdminFormat as F; use App\Support\Idtc; @endphp

@section('sub')
    เฉพาะเจ้าของ (Owner) · ติ๊กสิทธิ์ ดู / แก้ไข รายเมนูให้ผู้ดูแลแต่ละคน · เจ้าของได้ทุกอย่างเสมอ
@endsection

@php
    // สถานะรหัสผ่านของแต่ละกระเป๋า (ไม่แสดง hash)
    $pwState = function (string $address, string $role) use ($creds) {
        $c = $creds[$address] ?? null;
        return match (true) {
            $c?->must_change === true => ['pill-warn', 'ถูกรีเซ็ต · รอตั้งใหม่'],
            $c?->password_hash !== null => ['pill-ok', 'ตั้งรหัสผ่านแล้ว'],
            $role === 'owner' => ['pill-warn', 'ยังไม่ตั้ง · ต้องตั้งตอนเข้าระบบ'],
            default => ['pill-wait', 'ไม่มีรหัสผ่าน (กระเป๋าอย่างเดียว)'],
        };
    };
@endphp

@section('content')
    <div class="panel">
        <h3>ผู้ดูแลจาก .env</h3>
        <div class="table-scroll"><table class="tbl">
            <thead><tr><th>ผู้ดูแล</th><th>สิทธิ์</th><th>รหัสผ่าน</th><th></th></tr></thead>
            <tbody>
                @forelse ($envAdmins as $address => $role)
                    @php [$pc, $pt] = $pwState($address, $role); @endphp
                    <tr>
                        <td><x-admin.addr :address="$address"/><div class="muted" style="font-size:12.5px">จาก IDTC_ADMIN_WALLETS (.env){{ $address === $me->address ? ' · คุณ' : '' }}</div></td>
                        <td>{{ $roles[$role] }} <span class="muted">· แก้ใน .env</span></td>
                        <td><span class="{{ $pc }}">{{ $pt }}</span></td>
                        <td>@include('admin.partials.password-reset', ['address' => $address])</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">ไม่มี (IDTC_ADMIN_WALLETS ว่าง)</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </div>

    <h3 style="margin:22px 0 10px">ผู้ดูแลที่เพิ่มจากหลังบ้าน</h3>
    @forelse ($admins as $admin)
        @php [$pc, $pt] = $pwState($admin->address, $admin->role); $self = $admin->address === $me->address; @endphp
        <div class="panel admin-card" x-data="{ open: false }">
            <div class="actions" style="justify-content:space-between">
                <div>
                    <x-admin.addr :address="$admin->address"/>
                    <div style="font-size:13px">{{ $admin->label ?: '—' }}{{ $self ? ' · คุณ' : '' }}</div>
                    <div class="muted" style="font-size:12px">เพิ่มโดย {{ $admin->added_by ? Idtc::short(F::addr($admin->added_by)) : '—' }} · {{ F::date($admin->created_at) }}</div>
                </div>
                <div class="actions">
                    <span class="pill-wait">{{ $roles[$admin->role] ?? $admin->role }}{{ $admin->role !== 'owner' && $admin->permissions !== null ? ' · กำหนดเอง' : '' }}</span>
                    <span class="{{ $pc }}">{{ $pt }}</span>
                    @unless ($self)
                        <button class="btn btn-ghost btn-sm" type="button" @click="open = !open" :aria-expanded="open.toString()">ตั้งสิทธิ์</button>
                        @include('admin.partials.password-reset', ['address' => $admin->address])
                        <form method="POST" action="{{ route('admin.admins.destroy', $admin) }}" x-data="safeForm('ลบผู้ดูแลออกจากหลังบ้าน?', @js(['กระเป๋า '.F::addr($admin->address).($admin->label ? ' ('.$admin->label.')' : ''), 'กระเป๋านี้จะถูกออกจากระบบทันทีและเข้าไม่ได้อีก']), true)" @submit="submit($event)">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm" type="submit" :disabled="busy">ลบ</button>
                        </form>
                    @else
                        <span class="muted">ลด/ลบสิทธิ์ตัวเองไม่ได้</span>
                    @endunless
                </div>
            </div>
            @unless ($self)
                <form method="POST" action="{{ route('admin.admins.update', $admin) }}" x-show="open" x-cloak style="margin-top:14px"
                      x-data="safeForm('บันทึกสิทธิ์ของผู้ดูแลนี้?', @js(['กระเป๋า '.F::addr($admin->address), 'มีผลทันทีตั้งแต่คำขอถัดไปของผู้ดูแลคนนี้']))" @submit="submit($event)">
                    @csrf @method('PATCH')
                    @include('admin.partials.perm-editor', ['role' => $admin->role, 'perms' => $perms[$admin->id] ?? []])
                    <div class="actions" style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกสิทธิ์</button></div>
                </form>
            @endunless
        </div>
    @empty
        <div class="panel muted">ยังไม่มีผู้ดูแลที่เพิ่มจากหลังบ้าน</div>
    @endforelse

    <form class="panel form" method="POST" action="{{ route('admin.admins.store') }}" x-data="safeForm" @submit="submit($event)" style="margin-top:22px">
        @csrf
        <h3>เพิ่มผู้ดูแล</h3>
        <label class="field"><span>ที่อยู่กระเป๋า</span><input class="input mono" name="address" value="{{ old('address') }}" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
        <label class="field"><span>ชื่อเรียก (ไม่บังคับ)</span><input class="input" name="label" value="{{ old('label') }}" maxlength="80" placeholder="เช่น ทีมการตลาด"></label>
        <div class="field"><span>สิทธิ์</span>
            @include('admin.partials.perm-editor', ['role' => 'viewer', 'perms' => $presets['viewer']])
        </div>
        <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">เพิ่มผู้ดูแล</button></div>
        <p class="hint">ผู้ดูแลเข้าระบบด้วยการเซ็นข้อความในกระเป๋าของตัวเอง แล้วตั้งรหัสผ่านเองได้ที่หน้า "รหัสผ่าน" (เจ้าของต้องตั้งเสมอ) · สิทธิ์บนหลังบ้านไม่เกี่ยวกับสิทธิ์บนสัญญา (owner ของสัญญา presale ตรวจจากเชนทุกครั้ง)</p>
    </form>
@endsection

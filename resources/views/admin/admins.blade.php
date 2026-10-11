@extends('layouts.admin')
@php use App\Support\AdminFormat as F; @endphp

@section('sub')
    เฉพาะเจ้าของ (Owner) · viewer = ดูอย่างเดียว · admin = แก้ค่าตั้งและใช้คำสั่งบนเชน · owner = ทุกอย่าง + จัดการผู้ดูแล
@endsection

@section('content')
    <div class="grid-main">
        <div class="panel">
            <h3>ผู้ดูแลทั้งหมด</h3>
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>ผู้ดูแล</th><th>สิทธิ์</th><th></th></tr></thead>
                <tbody>
                    @foreach ($envAdmins as $address => $role)
                        <tr>
                            <td><x-admin.addr :address="$address"/><div class="muted" style="font-size:12.5px">จาก IDTC_ADMIN_WALLETS (.env){{ $address === $me->address ? ' · คุณ' : '' }}</div></td>
                            <td>{{ $roles[$role] }}</td>
                            <td class="muted">แก้ใน .env</td>
                        </tr>
                    @endforeach
                    @foreach ($admins as $admin)
                        <tr>
                            <td><x-admin.addr :address="$admin->address"/>
                                <div style="font-size:13px">{{ $admin->label ?: '—' }}</div>
                                <div class="muted" style="font-size:12px">เพิ่มโดย {{ $admin->added_by ? \App\Support\Idtc::short(F::addr($admin->added_by)) : '—' }} · {{ F::date($admin->created_at) }}</div>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('admin.admins.update', $admin) }}" class="actions" style="flex-wrap:nowrap" x-data="safeForm('เปลี่ยนสิทธิ์ผู้ดูแลนี้?', @js(['กระเป๋า '.F::addr($admin->address), 'มีผลทันทีตั้งแต่คำขอถัดไปของผู้ดูแลคนนี้']))" @submit="submit($event)">
                                    @csrf @method('PATCH')
                                    <select class="select" name="role" style="min-height:36px;padding-block:4px;width:auto" aria-label="สิทธิ์ของ {{ $admin->address }}" @disabled($admin->address === $me->address)>
                                        @foreach ($roles as $value => $label)
                                            <option value="{{ $value }}" @selected($admin->role === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @unless ($admin->address === $me->address)
                                        <button class="btn btn-ghost btn-sm" type="submit" :disabled="busy">เปลี่ยน</button>
                                    @endunless
                                </form>
                            </td>
                            <td>
                                @if ($admin->address === $me->address)
                                    <span class="muted">คุณ</span>
                                @else
                                    <form method="POST" action="{{ route('admin.admins.destroy', $admin) }}" x-data="safeForm('ลบผู้ดูแลออกจากหลังบ้าน?', @js(['กระเป๋า '.F::addr($admin->address).($admin->label ? ' ('.$admin->label.')' : ''), 'กระเป๋านี้จะถูกออกจากระบบทันทีและเข้าไม่ได้อีก']), true)" @submit="submit($event)">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger btn-sm" type="submit" :disabled="busy">ลบ</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($envAdmins === [] && $admins->isEmpty())
                        <tr><td colspan="3" class="muted">ยังไม่มีผู้ดูแล</td></tr>
                    @endif
                </tbody>
            </table></div>
        </div>

        <form class="panel form" method="POST" action="{{ route('admin.admins.store') }}" x-data="safeForm" @submit="submit($event)">
            @csrf
            <h3>เพิ่มผู้ดูแล</h3>
            <label class="field"><span>ที่อยู่กระเป๋า</span><input class="input mono" name="address" value="{{ old('address') }}" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
            <label class="field"><span>ชื่อเรียก (ไม่บังคับ)</span><input class="input" name="label" value="{{ old('label') }}" maxlength="80" placeholder="เช่น ทีมการตลาด"></label>
            <label class="field"><span>สิทธิ์</span>
                <select class="select" name="role">
                    @foreach ($roles as $value => $label)
                        <option value="{{ $value }}" @selected(old('role', 'viewer') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">เพิ่มผู้ดูแล</button></div>
            <p class="hint">ผู้ดูแลเข้าระบบด้วยการเซ็นข้อความในกระเป๋าของตัวเอง — ไม่มีรหัสผ่านให้ส่งต่อ · สิทธิ์บนหลังบ้านไม่เกี่ยวกับสิทธิ์บนสัญญา (owner ของสัญญา presale ตรวจจากเชนทุกครั้ง)</p>
        </form>
    </div>
@endsection

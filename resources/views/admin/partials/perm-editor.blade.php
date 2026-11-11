@php
    use App\Services\Admin\AdminPermissions as AP;
    /** @var string $role  @var array<string, string> $perms  @var string $uid */
    $byGroup = [];
    foreach ($sections as $key => $meta) {
        $byGroup[$meta['group']][$key] = $meta;
    }
@endphp
{{-- ตัวเลือกสิทธิ์: ชุดสำเร็จรูปเติมช่องติ๊กให้ แล้วปรับรายส่วนได้ · role=owner ได้ทุกอย่าง (ไม่ส่งค่าติ๊ก) --}}
<div class="perm-editor" x-data="permEditor(@js($role), @js((object) $perms), @js($presets))">
    <input type="hidden" name="perm_form" value="1">
    <input type="hidden" name="role" :value="owner ? 'owner' : 'admin'" value="{{ $role === 'owner' ? 'owner' : 'admin' }}">
    <div class="actions" role="group" aria-label="ชุดสิทธิ์สำเร็จรูป">
        <button class="btn btn-ghost btn-sm" type="button" @click="preset('viewer')" :class="{ on: !owner && is('viewer') }">ดูอย่างเดียว</button>
        <button class="btn btn-ghost btn-sm" type="button" @click="preset('admin')" :class="{ on: !owner && is('admin') }">แอดมิน (ดู + แก้ทุกส่วน)</button>
        <button class="btn btn-ghost btn-sm" type="button" @click="preset('owner')" :class="{ on: owner }">เจ้าของ (ทุกอย่าง + จัดการผู้ดูแล)</button>
        <button class="btn btn-ghost btn-sm" type="button" @click="preset('none')" x-show="!owner">ล้างทั้งหมด</button>
    </div>
    <p class="hint" x-show="owner" x-cloak style="margin:6px 0 0">เจ้าของได้ทุกส่วนเสมอ รวมถึงเพิ่ม/ลบผู้ดูแลและรีเซ็ตรหัสผ่าน — ต้องตั้งรหัสผ่านตอนเข้าระบบครั้งถัดไป</p>
    <div class="table-scroll" x-show="!owner">
        <table class="tbl perm-tbl">
            <thead><tr><th>ส่วนของหลังบ้าน</th><th class="c">ดู</th><th class="c">แก้ไข/สั่งงาน</th></tr></thead>
            <tbody>
                @foreach ($byGroup as $group => $items)
                    <tr class="grp-row"><td colspan="3">{{ $group }}</td></tr>
                    @foreach ($items as $key => $meta)
                        <tr>
                            <td>{{ $meta['label'] }}</td>
                            <td class="c"><input type="checkbox" name="perm[{{ $key }}][view]" value="1" aria-label="ดู {{ $meta['label'] }}"
                                @checked(isset($perms[$key])) :checked="view('{{ $key }}')" @change="setView('{{ $key }}', $event.target.checked)" :disabled="owner"></td>
                            <td class="c">
                                @if (AP::editable($key))
                                    <input type="checkbox" name="perm[{{ $key }}][edit]" value="1" aria-label="แก้ไข {{ $meta['label'] }}"
                                        @checked(($perms[$key] ?? null) === 'edit') :checked="edit('{{ $key }}')" @change="setEdit('{{ $key }}', $event.target.checked)" :disabled="owner">
                                @else
                                    <span class="muted" title="ส่วนนี้ไม่มีอะไรให้แก้">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</div>

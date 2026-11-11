<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminCredential;
use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminPasswords;
use App\Services\Admin\AdminPermissions;
use App\Services\Admin\AdminRegistry;
use App\Services\Admin\Audit;
use App\Services\Chain\Address;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ผู้ดูแล (เฉพาะ owner): เพิ่ม · ตั้งสิทธิ์รายเมนู · ลบ · รีเซ็ตรหัสผ่าน — กระเป๋าใน .env แก้สิทธิ์จากหน้านี้ไม่ได้
 *
 * ฟอร์มสิทธิ์ส่ง role (owner|admin|viewer) และ perm[<ส่วน>][view|edit] + perm_form=1
 *   - role=owner → ได้ทุกอย่าง ไม่เก็บค่าติ๊ก
 *   - มี perm_form → เก็บค่าที่ติ๊ก role ตั้งตามนั้น (มีสิทธิ์แก้สักส่วน = admin ไม่มี = viewer)
 *   - ไม่มี perm_form → ใช้ชุดสิทธิ์ของ role (viewer/admin) ตามเดิม
 * กันล็อกตัวเองออก: ลด/ลบตัวเองไม่ได้ · owner คนสุดท้าย (รวม .env) ลดหรือลบไม่ได้
 */
class AdminUserController extends Controller
{
    public function index(AdminRegistry $registry): View
    {
        $admins = Admin::query()->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('id')->get();
        $addresses = array_merge(array_keys($registry->envAdmins()), $admins->pluck('address')->all());

        return view('admin.admins', [
            'title' => 'ผู้ดูแล',
            'envAdmins' => $registry->envAdmins(),
            'admins' => $admins,
            'roles' => AdminRegistry::LABELS,
            'sections' => AdminPermissions::SECTIONS,
            'presets' => ['viewer' => AdminPermissions::preset('viewer'), 'admin' => AdminPermissions::preset('admin')],
            'perms' => $admins->mapWithKeys(fn (Admin $a) => [$a->id => $registry->permissionsFor($a->address, $a->role)])->all(),
            'creds' => AdminCredential::query()->whereIn('address', $addresses)->get()->keyBy('address'),
        ]);
    }

    public function store(Request $request, AdminRegistry $registry): RedirectResponse
    {
        $address = trim((string) $request->input('address'));
        $label = trim((string) $request->input('label', ''));
        [$role, $perms] = $this->access($request);

        $error = match (true) {
            ! Address::isValid($address) => 'ที่อยู่กระเป๋าต้องขึ้นต้นด้วย 0x ตามด้วยตัวอักษรฐานสิบหก 40 ตัว',
            ! Address::hasValidChecksum($address) => 'ตัวพิมพ์เล็ก/ใหญ่ของที่อยู่ไม่ตรง checksum (อาจพิมพ์ผิด)',
            Address::isZero($address) => 'ใช้ที่อยู่ว่างไม่ได้',
            $role === null => 'เลือกสิทธิ์ให้ถูกต้อง',
            mb_strlen($label) > 80 => 'ชื่อเรียกยาวเกิน 80 ตัวอักษร',
            $registry->isEnvAdmin($address) => 'กระเป๋านี้อยู่ใน IDTC_ADMIN_WALLETS (.env) อยู่แล้ว',
            Admin::query()->where('address', strtolower($address))->exists() => 'กระเป๋านี้เป็นผู้ดูแลอยู่แล้ว — เปลี่ยนสิทธิ์จากตารางด้านล่าง',
            default => null,
        };
        if ($error !== null) {
            return back()->withInput($request->only('address', 'label', 'role'))->with('error', $error);
        }

        $me = app(AdminContext::class);
        Admin::query()->create(['address' => $address, 'role' => $role, 'permissions' => $perms, 'label' => $label ?: null, 'added_by' => $me->address]);
        // ที่อยู่นี้อาจเคยเป็นผู้ดูแลแล้วถูกลบ — ไม่ให้รหัสผ่านเก่าค้างมาใช้
        app(AdminPasswords::class)->forget($address);
        Audit::record('admins.add', array_filter(['address' => strtolower($address), 'new' => $role, 'permissions' => $perms, 'label' => $label ?: null], fn ($v) => $v !== null));

        return back()->with('status', 'เพิ่มผู้ดูแลแล้ว — กระเป๋านี้เข้าสู่ระบบได้ทันที');
    }

    public function update(Request $request, Admin $admin, AdminRegistry $registry): RedirectResponse
    {
        [$role, $perms] = $this->access($request);
        if ($role === null) {
            return back()->with('error', 'เลือกสิทธิ์ให้ถูกต้อง');
        }
        $me = app(AdminContext::class);
        if ($admin->address === $me->address && $role !== 'owner') {
            return back()->with('error', 'ลดสิทธิ์ตัวเองไม่ได้ (กันล็อกตัวเองออก) — ให้ owner คนอื่นทำแทน');
        }
        if ($admin->role === 'owner' && $role !== 'owner' && $registry->ownerCount() <= 1) {
            return back()->with('error', 'ต้องมี owner อย่างน้อยหนึ่งคน');
        }

        $oldRole = $admin->role;
        $oldPerms = $admin->permissions;
        if ($oldRole === $role && $oldPerms === $perms) {
            return back()->with('status', 'สิทธิ์ไม่ได้เปลี่ยน');
        }
        $admin->update(['role' => $role, 'permissions' => $perms]);
        if ($oldRole !== $role) {
            Audit::record('admins.role', ['address' => $admin->address, 'old' => $oldRole, 'new' => $role]);
        }
        if ($oldPerms !== $perms) {
            Audit::record('admins.permissions', ['address' => $admin->address, 'old' => $oldPerms, 'new' => $perms]);
        }

        return back()->with('status', 'บันทึกสิทธิ์แล้ว มีผลทันทีตั้งแต่คำขอถัดไปของผู้ดูแลคนนี้');
    }

    public function destroy(Admin $admin, AdminRegistry $registry, AdminPasswords $passwords): RedirectResponse
    {
        $me = app(AdminContext::class);
        if ($admin->address === $me->address) {
            return back()->with('error', 'ลบตัวเองไม่ได้ — ให้ owner คนอื่นทำแทน');
        }
        if ($admin->role === 'owner' && $registry->ownerCount() <= 1) {
            return back()->with('error', 'ต้องมี owner อย่างน้อยหนึ่งคน');
        }
        $admin->delete();
        if (! $registry->isEnvAdmin($admin->address)) {
            $passwords->forget($admin->address);
        }
        Audit::record('admins.remove', ['address' => $admin->address, 'old' => $admin->role, 'label' => $admin->label]);

        return back()->with('status', 'ลบผู้ดูแลแล้ว กระเป๋านี้ถูกออกจากระบบทันที');
    }

    /** รีเซ็ตรหัสผ่านของผู้ดูแลคนอื่น (รวมกระเป๋าใน .env) — ผู้นั้นหลุดจากระบบ และต้องตั้งรหัสผ่านใหม่ตอนเข้าครั้งถัดไป */
    public function resetPassword(Request $request, AdminRegistry $registry, AdminPasswords $passwords): RedirectResponse
    {
        $address = strtolower(trim((string) $request->input('address')));
        $me = app(AdminContext::class);
        $error = match (true) {
            ! Address::isValid($address) || $registry->roleFor($address) === null => 'ไม่พบผู้ดูแลกระเป๋านี้',
            $address === $me->address => 'รหัสผ่านของตัวเองให้เปลี่ยนที่หน้า "รหัสผ่าน"',
            default => null,
        };
        if ($error !== null) {
            return back()->with('error', $error);
        }

        $passwords->reset($address, $me->address);
        Audit::record('admins.password_reset', ['address' => $address]);

        return back()->with('status', 'รีเซ็ตรหัสผ่านแล้ว — ผู้ดูแลคนนี้ถูกออกจากระบบ และต้องตั้งรหัสผ่านใหม่ตอนเข้าครั้งถัดไป');
    }

    /** @return array{0: string|null, 1: array<string, string>|null} [role, ค่าที่ติ๊ก (null = ตามชุดของ role)] */
    protected function access(Request $request): array
    {
        $role = (string) $request->input('role');
        if (! in_array($role, Admin::ROLES, true)) {
            return [null, null];
        }
        if ($role === 'owner' || ! $request->boolean('perm_form')) {
            return [$role, null];
        }
        $perms = AdminPermissions::normalize($request->input('perm', []));
        $role = AdminPermissions::roleFor($perms);

        // ติ๊กตรงกับชุดสำเร็จรูปพอดี = เก็บเป็นชุดนั้น (ชุดสิทธิ์ขยายตามเมนูใหม่ในอนาคตได้)
        return [$role, AdminPermissions::isPreset($perms, $role) ? null : $perms];
    }
}

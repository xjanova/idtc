<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminRegistry;
use App\Services\Admin\Audit;
use App\Services\Chain\Address;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ผู้ดูแล (เฉพาะ owner): เพิ่ม · เปลี่ยนสิทธิ์ · ลบ — กระเป๋าใน .env แก้จากหน้านี้ไม่ได้
 */
class AdminUserController extends Controller
{
    public function index(AdminRegistry $registry): View
    {
        return view('admin.admins', [
            'title' => 'ผู้ดูแล',
            'envAdmins' => $registry->envAdmins(),
            'admins' => Admin::query()->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('id')->get(),
            'roles' => AdminRegistry::LABELS,
        ]);
    }

    public function store(Request $request, AdminRegistry $registry): RedirectResponse
    {
        $address = trim((string) $request->input('address'));
        $role = (string) $request->input('role');
        $label = trim((string) $request->input('label', ''));

        $error = match (true) {
            ! Address::isValid($address) => 'ที่อยู่กระเป๋าต้องขึ้นต้นด้วย 0x ตามด้วยตัวอักษรฐานสิบหก 40 ตัว',
            ! Address::hasValidChecksum($address) => 'ตัวพิมพ์เล็ก/ใหญ่ของที่อยู่ไม่ตรง checksum (อาจพิมพ์ผิด)',
            Address::isZero($address) => 'ใช้ที่อยู่ว่างไม่ได้',
            ! in_array($role, Admin::ROLES, true) => 'เลือกสิทธิ์ให้ถูกต้อง',
            mb_strlen($label) > 80 => 'ชื่อเรียกยาวเกิน 80 ตัวอักษร',
            $registry->isEnvAdmin($address) => 'กระเป๋านี้อยู่ใน IDTC_ADMIN_WALLETS (.env) อยู่แล้ว',
            Admin::query()->where('address', strtolower($address))->exists() => 'กระเป๋านี้เป็นผู้ดูแลอยู่แล้ว — เปลี่ยนสิทธิ์จากตารางด้านล่าง',
            default => null,
        };
        if ($error !== null) {
            return back()->withInput()->with('error', $error);
        }

        $me = app(AdminContext::class);
        Admin::query()->create(['address' => $address, 'role' => $role, 'label' => $label ?: null, 'added_by' => $me->address]);
        Audit::record('admins.add', ['address' => strtolower($address), 'new' => $role, 'label' => $label ?: null]);

        return back()->with('status', 'เพิ่มผู้ดูแลแล้ว — กระเป๋านี้เข้าสู่ระบบได้ทันที');
    }

    public function update(Request $request, Admin $admin, AdminRegistry $registry): RedirectResponse
    {
        $role = (string) $request->input('role');
        if (! in_array($role, Admin::ROLES, true)) {
            return back()->with('error', 'เลือกสิทธิ์ให้ถูกต้อง');
        }
        $me = app(AdminContext::class);
        if ($admin->address === $me->address && $role !== 'owner') {
            return back()->with('error', 'ลดสิทธิ์ตัวเองไม่ได้ (กันล็อกตัวเองออก) — ให้ owner คนอื่นทำแทน');
        }
        if ($admin->role === 'owner' && $role !== 'owner' && $registry->ownerCount() <= 1) {
            return back()->with('error', 'ต้องมี owner อย่างน้อยหนึ่งคน');
        }
        $old = $admin->role;
        if ($old === $role) {
            return back()->with('status', 'สิทธิ์ไม่ได้เปลี่ยน');
        }
        $admin->update(['role' => $role]);
        Audit::record('admins.role', ['address' => $admin->address, 'old' => $old, 'new' => $role]);

        return back()->with('status', 'เปลี่ยนสิทธิ์แล้ว มีผลทันที');
    }

    public function destroy(Admin $admin, AdminRegistry $registry): RedirectResponse
    {
        $me = app(AdminContext::class);
        if ($admin->address === $me->address) {
            return back()->with('error', 'ลบตัวเองไม่ได้ — ให้ owner คนอื่นทำแทน');
        }
        if ($admin->role === 'owner' && $registry->ownerCount() <= 1) {
            return back()->with('error', 'ต้องมี owner อย่างน้อยหนึ่งคน');
        }
        $admin->delete();
        Audit::record('admins.remove', ['address' => $admin->address, 'old' => $admin->role, 'label' => $admin->label]);

        return back()->with('status', 'ลบผู้ดูแลแล้ว กระเป๋านี้ถูกออกจากระบบทันที');
    }
}

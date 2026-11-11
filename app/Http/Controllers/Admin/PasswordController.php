<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminPasswords;
use App\Services\Admin\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * เปลี่ยนรหัสผ่านหลังบ้านของตัวเอง — มีรหัสผ่านอยู่แล้วต้องใส่รหัสเดิม (ผิดนับรวมกับตอนเข้าระบบ ล็อกเหมือนกัน)
 * ไม่ส่งค่าที่กรอกกลับไปที่ฟอร์ม (ไม่ใช้ withInput) และไม่ใส่รหัสผ่านใน log
 */
class PasswordController extends Controller
{
    public function edit(AdminPasswords $passwords): View
    {
        $me = app(AdminContext::class);
        $cred = $passwords->find($me->address);

        return view('admin.password', [
            'title' => 'รหัสผ่านหลังบ้าน',
            'hasPassword' => $cred?->password_hash !== null,
            'changedAt' => $cred?->password_changed_at,
            'required' => $me->isOwner() || $cred?->password_hash !== null,
        ]);
    }

    public function update(Request $request, AdminPasswords $passwords): RedirectResponse
    {
        $me = app(AdminContext::class);
        $cred = $passwords->find($me->address);

        if ($cred?->password_hash !== null) {
            $locked = $passwords->lockedSeconds($me->address);
            if ($locked > 0) {
                return back()->with('error', AdminPasswords::lockMessage($locked));
            }
            $current = $request->input('current_password');
            if (! is_string($current) || $current === '' || strlen($current) > 1000 || ! $passwords->check($me->address, $current, $cred)) {
                $left = $passwords->recordFailure($me->address);
                Audit::record('auth.password_failed', ['address' => $me->address, 'left' => $left, 'where' => 'change']);

                return back()->with('error', $left === 0
                    ? AdminPasswords::lockMessage(AdminPasswords::LOCK_SECONDS)
                    : 'รหัสผ่านปัจจุบันไม่ถูกต้อง (ลองได้อีก '.$left.' ครั้ง)');
            }
        }

        $new = $request->input('password');
        $error = $passwords->validateNew($new, $request->input('password_confirmation'));
        if ($error === null && $cred?->password_hash !== null && $passwords->check($me->address, (string) $new, $cred)) {
            $error = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
        }
        if ($error !== null) {
            return back()->with('error', $error);
        }

        $fresh = $passwords->set($me->address, (string) $new);
        // session นี้ไปต่อได้ · session อื่นของกระเป๋านี้ (เครื่องอื่น) หลุดเพราะ version เปลี่ยน
        $request->session()->regenerate();
        $request->session()->put('admin.pw_v', $fresh->version);
        Audit::record('auth.password_changed', ['address' => $me->address, 'first' => $cred?->password_hash === null]);

        return redirect()->route('admin.password')->with('status', 'บันทึกรหัสผ่านแล้ว — ครั้งต่อไปต้องใส่รหัสผ่านนี้หลังเซ็นกระเป๋า');
    }
}

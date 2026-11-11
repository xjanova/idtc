<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminPasswords;
use App\Services\Admin\AdminRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ต้องเข้าระบบหลังบ้านด้วยกระเป๋าแล้ว และกระเป๋านั้นยังมีสิทธิ์อยู่ (ตรวจใหม่ทุกคำขอ)
 * ถ้าที่อยู่นี้ต้องใช้รหัสผ่าน session ต้องผ่านรหัสผ่านรุ่นล่าสุดมาแล้ว — ถูกรีเซ็ต/เปลี่ยนรหัสผ่านที่อื่น = หลุดทันที
 */
class EnsureAdmin
{
    public function __construct(protected AdminRegistry $registry, protected AdminPasswords $passwords) {}

    public function handle(Request $request, Closure $next): Response
    {
        $address = $request->session()->get('admin.address');
        $role = is_string($address) ? $this->registry->roleFor($address) : null;

        $passwordStale = false;
        $step = null;
        if ($role !== null) {
            $cred = $this->passwords->find($address);
            $step = $this->passwords->stepFor($address, $role, $cred);
            $passwordStale = $step === AdminPasswords::STEP_SET
                || ($step === AdminPasswords::STEP_ENTER && $request->session()->get('admin.pw_v') !== (int) $cred?->version);
        }

        if ($passwordStale) {
            $request->session()->forget('admin');
            $request->session()->regenerate();
            $message = $step === AdminPasswords::STEP_SET
                ? 'ต้องตั้งรหัสผ่านหลังบ้านก่อน — เข้าสู่ระบบด้วยกระเป๋าอีกครั้ง แล้วตั้งรหัสผ่าน'
                : 'รหัสผ่านหลังบ้านถูกเปลี่ยน — เข้าสู่ระบบใหม่';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->guest(route('admin.login'))->with('error', $message);
        }

        if ($role === null) {
            $revoked = is_string($address);
            if ($revoked) {
                $request->session()->forget('admin');
                $request->session()->regenerate();
            }
            $message = $revoked ? 'สิทธิ์ของกระเป๋านี้ถูกถอนแล้ว ติดต่อเจ้าของโครงการ' : 'เข้าสู่ระบบหลังบ้านด้วยกระเป๋าก่อน';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->guest(route('admin.login'))->with('error', $revoked ? $message : null);
        }

        $context = new AdminContext($address, $role, $this->registry->labelFor($address), $this->registry->permissionsFor($address, $role));
        app()->instance(AdminContext::class, $context);
        View::share('me', $context);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}

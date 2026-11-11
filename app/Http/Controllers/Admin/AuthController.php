<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminPasswords;
use App\Services\Admin\AdminRegistry;
use App\Services\Admin\AdminSession;
use App\Services\Admin\Audit;
use App\Services\Auth\SiweAuth;
use App\Services\Auth\SiweException;
use App\Services\Chain\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * เข้าหลังบ้าน: เซ็นข้อความในกระเป๋า (EIP-4361) → ถ้าที่อยู่นี้ต้องใช้รหัสผ่าน ใส่รหัสผ่าน (หรือตั้งใหม่) → เข้า
 *
 * owner ต้องมีรหัสผ่านเสมอ (ยังไม่มี = ตั้งก่อนเข้า) · ผู้ดูแลคนอื่นที่ตั้งรหัสผ่านไว้ต้องใส่ทุกครั้ง
 * เซิร์ฟเวอร์ไม่เห็นคีย์ · รหัสผ่านเก็บแค่ hash ไม่ log ไม่ส่งกลับ
 */
class AuthController extends Controller
{
    public function show(Request $request, AdminRegistry $registry): Response|RedirectResponse
    {
        if (! $registry->hasAnyAdmin()) {
            return redirect()->route('admin.setup');
        }
        $address = $request->session()->get('admin.address');
        if (is_string($address) && $registry->roleFor($address) !== null) {
            return redirect()->route('admin.dashboard');
        }

        return response()->view('admin.login', ['title' => 'เข้าสู่หลังบ้าน'])->withHeaders(self::headers());
    }

    public function nonce(Request $request, SiweAuth $siwe): JsonResponse
    {
        $address = $request->input('address');
        if (! is_string($address) || ! Address::isValid($address)) {
            return response()->json(['message' => 'ที่อยู่กระเป๋าไม่ถูกต้อง เชื่อมกระเป๋าใหม่อีกครั้ง'], 422);
        }
        $message = $siwe->issue($request, $address);

        return response()->json(['message' => $message->toString(), 'nonce' => $message->nonce]);
    }

    public function verify(Request $request, SiweAuth $siwe, AdminRegistry $registry, AdminPasswords $passwords, AdminSession $session): JsonResponse
    {
        $text = $request->input('message');
        $signature = $request->input('signature');
        if (! is_string($text) || ! is_string($signature) || strlen($text) > 2000 || strlen($signature) > 200) {
            return response()->json(['message' => 'ข้อมูลเข้าสู่ระบบไม่ครบ กดเข้าสู่ระบบใหม่', 'code' => 'format'], 422);
        }

        try {
            $address = $siwe->verify($request, $text, $signature);
        } catch (SiweException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 422);
        }

        $role = $registry->roleFor($address);
        if ($role === null) {
            Audit::record('auth.denied', ['address' => $address]);

            return response()->json(['message' => 'กระเป๋านี้ไม่มีสิทธิ์เข้าหลังบ้าน IDTC — ให้เจ้าของเพิ่มที่อยู่นี้ในหน้า "ผู้ดูแล" ก่อน', 'code' => 'not_admin'], 403);
        }

        $step = $passwords->stepFor($address, $role);
        if ($step !== null) {
            $session->startPending($request, $address, $step);
            $locked = $step === AdminPasswords::STEP_ENTER ? $passwords->lockedSeconds($address) : 0;

            return response()->json([
                'next' => $step === AdminPasswords::STEP_ENTER ? 'password' : 'set_password',
                'message' => $step === AdminPasswords::STEP_ENTER
                    ? ($locked > 0 ? AdminPasswords::lockMessage($locked) : 'ลายเซ็นถูกต้อง ใส่รหัสผ่านหลังบ้านเพื่อเข้าสู่ระบบ')
                    : 'ลายเซ็นถูกต้อง ตั้งรหัสผ่านหลังบ้านก่อนเข้าใช้งาน (ต้องใส่ทุกครั้งที่เข้าระบบ)',
                'locked' => $locked,
            ]);
        }

        return response()->json(['redirect' => $session->admit($request, $address, $role)]);
    }

    /** ขั้นที่สอง: ใส่รหัสผ่าน (enter) หรือตั้งรหัสผ่านใหม่ (set) — ต้องเซ็นกระเป๋าผ่านมาไม่เกิน 5 นาที */
    public function password(Request $request, AdminRegistry $registry, AdminPasswords $passwords, AdminSession $session): JsonResponse
    {
        $pending = $session->pending($request);
        $role = $pending !== null ? $registry->roleFor($pending['address']) : null;
        if ($pending === null || $role === null) {
            $request->session()->forget('admin_pending');

            return response()->json(['message' => 'ขั้นตอนเข้าสู่ระบบหมดเวลา เซ็นข้อความในกระเป๋าใหม่อีกครั้ง', 'code' => 'expired'], 422);
        }
        $address = $pending['address'];
        $me = new AdminContext($address, $role);

        // สถานะรหัสผ่านอาจเปลี่ยนระหว่างรอ (เช่น ถูกรีเซ็ต) — ให้เริ่มใหม่ ไม่เดาแทน
        $cred = $passwords->find($address);
        $step = $passwords->stepFor($address, $role, $cred);
        if ($step !== $pending['step']) {
            $request->session()->forget('admin_pending');

            return response()->json(['message' => 'สถานะรหัสผ่านของกระเป๋านี้เปลี่ยนไป เซ็นข้อความในกระเป๋าใหม่อีกครั้ง', 'code' => 'expired'], 422);
        }

        if ($step === AdminPasswords::STEP_SET) {
            $error = $passwords->validateNew($request->input('password'), $request->input('password_confirmation'));
            if ($error !== null) {
                return response()->json(['message' => $error, 'code' => 'invalid'], 422);
            }
            $passwords->set($address, (string) $request->input('password'));
            Audit::record('auth.password_set', ['address' => $address, 'forced' => (bool) $cred?->must_change], $me);

            return response()->json(['redirect' => $session->admit($request, $address, $role)]);
        }

        $locked = $passwords->lockedSeconds($address);
        if ($locked > 0) {
            return response()->json(['message' => AdminPasswords::lockMessage($locked), 'code' => 'locked', 'locked' => $locked], 429);
        }
        $password = $request->input('password');
        if (! is_string($password) || $password === '' || strlen($password) > 1000 || ! $passwords->check($address, $password, $cred)) {
            $left = $passwords->recordFailure($address);
            Audit::record('auth.password_failed', ['address' => $address, 'left' => $left], $me);
            if ($left === 0) {
                Audit::record('auth.password_locked', ['address' => $address, 'minutes' => intdiv(AdminPasswords::LOCK_SECONDS, 60)], $me);

                return response()->json(['message' => AdminPasswords::lockMessage(AdminPasswords::LOCK_SECONDS), 'code' => 'locked', 'locked' => AdminPasswords::LOCK_SECONDS], 429);
            }

            return response()->json(['message' => 'รหัสผ่านไม่ถูกต้อง (ลองได้อีก '.$left.' ครั้งก่อนถูกล็อก 15 นาที)', 'code' => 'password', 'left' => $left], 422);
        }
        $passwords->clearFailures($address);

        return response()->json(['redirect' => $session->admit($request, $address, $role)]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::record('auth.logout');
        $request->session()->forget(['admin', 'admin_pending']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'ออกจากระบบแล้ว');
    }

    /** @return array<string, string> */
    public static function headers(): array
    {
        return [
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'same-origin',
        ];
    }
}

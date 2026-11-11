<?php

namespace App\Services\Admin;

use Illuminate\Http\Request;

/**
 * เปิด session หลังบ้านหลังผ่านทุกขั้น (ลายเซ็น + รหัสผ่านถ้าต้องใช้)
 *
 * ขั้นกลาง "เซ็นผ่านแล้ว รอรหัสผ่าน" เก็บใน session key admin_pending (อายุ PENDING_TTL วินาที)
 * — ยังไม่ใช่ session ผู้ดูแล middleware admin ไม่นับ
 */
class AdminSession
{
    public const PENDING_TTL = 300;

    public function __construct(protected AdminRegistry $registry, protected AdminPasswords $passwords) {}

    public function startPending(Request $request, string $address, string $step): void
    {
        $request->session()->regenerate();
        $request->session()->forget('admin');
        $request->session()->put('admin_pending', ['address' => strtolower($address), 'step' => $step, 'at' => now()->timestamp]);
    }

    /** @return array{address: string, step: string, at: int}|null ขั้นกลางที่ยังไม่หมดอายุ */
    public function pending(Request $request): ?array
    {
        $p = $request->session()->get('admin_pending');
        if (! is_array($p) || ! is_string($p['address'] ?? null) || ! is_string($p['step'] ?? null) || ! is_int($p['at'] ?? null)) {
            return null;
        }
        if (now()->timestamp - $p['at'] > self::PENDING_TTL) {
            $request->session()->forget('admin_pending');

            return null;
        }

        return $p;
    }

    /** @return string URL ที่จะพาไปต่อ */
    public function admit(Request $request, string $address, string $role): string
    {
        $address = strtolower($address);
        $request->session()->regenerate();
        $request->session()->forget('admin_pending');
        $request->session()->put('admin', [
            'address' => $address,
            'login_at' => now()->timestamp,
            'pw_v' => $this->passwords->sessionVersion($address, $role),
        ]);

        $me = new AdminContext($address, $role, null, $this->registry->permissionsFor($address, $role));
        Audit::record('auth.login', [], $me);

        $intended = (string) $request->session()->pull('url.intended', '');
        $base = url('/admin');
        if ($intended === $base || str_starts_with($intended, $base.'/')) {
            return $intended;
        }

        return route($me->homeRoute() ?? 'admin.password');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminRegistry;
use App\Services\Admin\Audit;
use App\Services\Auth\SiweAuth;
use App\Services\Auth\SiweException;
use App\Services\Chain\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * เข้าหลังบ้านด้วยการเซ็นข้อความในกระเป๋า (EIP-4361) — ไม่มีรหัสผ่าน เซิร์ฟเวอร์ไม่เห็นคีย์
 */
class AuthController extends Controller
{
    public function show(Request $request, AdminRegistry $registry): Response|RedirectResponse
    {
        $address = $request->session()->get('admin.address');
        if (is_string($address) && $registry->roleFor($address) !== null) {
            return redirect()->route('admin.dashboard');
        }

        return response()->view('admin.login', [
            'title' => 'เข้าสู่หลังบ้าน',
            'bootstrapped' => $registry->hasAnyAdmin(),
        ])->withHeaders([
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'same-origin',
        ]);
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

    public function verify(Request $request, SiweAuth $siwe, AdminRegistry $registry): JsonResponse
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

        $request->session()->regenerate();
        $request->session()->put('admin', ['address' => $address, 'login_at' => now()->timestamp]);
        Audit::record('auth.login', [], new AdminContext($address, $role));

        $intended = (string) $request->session()->pull('url.intended', '');
        $home = route('admin.dashboard');

        return response()->json(['redirect' => str_starts_with($intended, url('/admin')) ? $intended : $home]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::record('auth.logout');
        $request->session()->forget('admin');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'ออกจากระบบแล้ว');
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * ต้องเข้าระบบหลังบ้านด้วยกระเป๋าแล้ว และกระเป๋านั้นยังมีสิทธิ์อยู่ (ตรวจใหม่ทุกคำขอ)
 */
class EnsureAdmin
{
    public function __construct(protected AdminRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $address = $request->session()->get('admin.address');
        $role = is_string($address) ? $this->registry->roleFor($address) : null;

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

        $context = new AdminContext($address, $role, $this->registry->labelFor($address));
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

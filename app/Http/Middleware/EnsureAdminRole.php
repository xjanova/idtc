<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ต้องมีสิทธิ์อย่างน้อยระดับที่กำหนด — ใช้หลัง EnsureAdmin เสมอ เช่น ->middleware('admin.role:owner')
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string $min = 'admin'): Response
    {
        $me = app()->bound(AdminContext::class) ? app(AdminContext::class) : null;
        if ($me !== null && $me->can($min)) {
            return $next($request);
        }

        $message = match ($min) {
            'owner' => 'เฉพาะเจ้าของ (Owner) เท่านั้นที่ทำรายการนี้ได้',
            default => 'สิทธิ์ของคุณดูได้อย่างเดียว แก้ไขหรือสั่งงานไม่ได้',
        };
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return response()->view('admin.forbidden', ['message' => $message, 'title' => 'ไม่มีสิทธิ์'], 403);
    }
}

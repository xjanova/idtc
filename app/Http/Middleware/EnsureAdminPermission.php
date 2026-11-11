<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminContext;
use App\Services\Admin\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * สิทธิ์รายเมนู — ใช้หลัง EnsureAdmin เสมอ: ->middleware('admin.can:sales') (ดู) · ('admin.can:sales,edit') (แก้/สั่งงาน)
 * owner ผ่านทุกส่วน · ส่วนนี้ถูกแชร์ให้ view เป็น $adminSection (ใช้ตัดสินว่าโชว์ปุ่มแก้หรือไม่)
 */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $section, string $level = AdminPermissions::VIEW): Response
    {
        $me = app()->bound(AdminContext::class) ? app(AdminContext::class) : null;
        View::share('adminSection', $section);

        $ok = $me !== null && ($level === AdminPermissions::EDIT ? $me->canEdit($section) : $me->canView($section));
        if ($ok) {
            return $next($request);
        }

        // เข้าระบบแล้วถูกพามาหน้าภาพรวมแต่ดูภาพรวมไม่ได้ — พาไปหน้าแรกที่ดูได้แทน
        if ($me !== null && $section === 'dashboard' && $request->isMethod('GET') && ! $request->expectsJson()) {
            $home = $me->homeRoute();
            if ($home !== null && $home !== 'admin.dashboard') {
                return redirect()->route($home);
            }
        }

        $name = AdminPermissions::SECTIONS[$section]['label'] ?? $section;
        $message = $level === AdminPermissions::EDIT && $me?->canView($section)
            ? 'สิทธิ์ของคุณในส่วน "'.$name.'" ดูได้อย่างเดียว แก้ไขหรือสั่งงานไม่ได้'
            : 'คุณยังไม่มีสิทธิ์เปิดส่วน "'.$name.'"';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return response()->view('admin.forbidden', ['message' => $message, 'title' => 'ไม่มีสิทธิ์'], 403);
    }
}

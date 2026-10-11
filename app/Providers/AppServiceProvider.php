<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureAdminRole;
use App\Services\Admin\AdminRegistry;
use App\Services\Auth\SiweAuth;
use App\Services\Chain\RpcClient;
use App\Support\Idtc;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(RpcClient::class);
        $this->app->singleton(AdminRegistry::class);
        $this->app->singleton(SiweAuth::class);
    }

    public function boot(): void
    {
        // ที่อยู่สัญญาที่แอดมินตั้งจากหลังบ้าน แก้ทับค่าใน config/idtc.php (แคชไว้ ล้างเมื่อบันทึก)
        Idtc::resolveContractsUsing(fn () => $this->app->make(Settings::class)->contractOverrides());

        // ประกาศ + ลิงก์โซเชียลจากหลังบ้าน → layout หน้าสาธารณะ
        View::composer('layouts.site', function ($view) {
            $settings = $this->app->make(Settings::class);
            if (! $view->offsetExists('announcement')) {
                $view->with('announcement', $settings->announcement());
            }
            if (! $view->offsetExists('socialLinks')) {
                $view->with('socialLinks', $settings->social());
            }
        });

        // เข้าระบบหลังบ้าน: ขอ nonce/ส่งลายเซ็น ได้ 10 ครั้งต่อนาทีต่อ IP
        RateLimiter::for('admin-auth', fn (Request $request) => Limit::perMinute(10)->by('admin-auth|'.$request->ip())
            ->response(fn () => response()->json(['message' => 'ลองเข้าระบบบ่อยเกินไป รอสักหนึ่งนาทีแล้วลองใหม่'], 429)));

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('admin', EnsureAdmin::class);
        $router->aliasMiddleware('admin.role', EnsureAdminRole::class);

        $this->renderAdminErrorsInThai();
    }

    /**
     * หน้า error ของหลังบ้านเป็นภาษาไทย (ไม่แสดงรายละเอียดภายใน) — โหมด debug ยังเห็นหน้า debug ของ Laravel ตามเดิม
     * ไม่แตะหน้า error ของเว็บสาธารณะ
     */
    protected function renderAdminErrorsInThai(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'renderable')) {
            return;
        }
        $handler->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('admin', 'admin/*') || $request->expectsJson() || config('app.debug') || $e instanceof ValidationException) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            [$title, $message] = match ($status) {
                404 => ['ไม่พบหน้านี้', 'ลิงก์อาจผิดหรือรายการถูกลบไปแล้ว'],
                403 => ['ไม่มีสิทธิ์', 'สิทธิ์ของคุณทำรายการนี้ไม่ได้'],
                419 => ['หน้าเปิดค้างนานเกินไป', 'เพื่อความปลอดภัย ฟอร์มนี้หมดอายุแล้ว กลับไปหน้าเดิม รีเฟรช แล้วลองใหม่'],
                429 => ['ลองบ่อยเกินไป', 'รอสักครู่แล้วลองใหม่'],
                503 => ['ปิดปรับปรุงชั่วคราว', 'ระบบกำลังปรับปรุง ลองใหม่อีกสักครู่'],
                default => ['ระบบขัดข้องชั่วคราว', 'ลองใหม่อีกครั้ง ถ้ายังเป็นอยู่ให้แจ้งผู้ดูแลระบบ (รายละเอียดอยู่ใน log ของเซิร์ฟเวอร์)'],
            };

            return response()->view('admin.error', compact('status', 'title', 'message'), $status);
        });
    }
}

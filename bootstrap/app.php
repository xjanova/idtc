<?php

use App\Http\Middleware\SetSiteLocale;
use App\Support\SiteLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // idtc.online is behind Cloudflare: read the visitor's real IP, or every admin login throttle and
        // audit-log IP becomes one shared Cloudflare address. require (not config()) — the config service
        // is not bound yet when this closure runs. List + reasoning: config/trustedproxy.php.
        $middleware->trustProxies(
            at: (require __DIR__.'/../config/trustedproxy.php')['proxies'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // ภาษาของหน้าสาธารณะ (routes/web.php) · คุกกี้ภาษาตั้งจาก JS ตอนกดสลับภาษา จึงไม่เข้ารหัส
        $middleware->alias(['site.locale' => SetSiteLocale::class]);
        $middleware->encryptCookies(except: [SiteLocale::COOKIE]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// DirectAdmin serves domains/<domain>/public_html — same layout as xmanstudio and tpix.online
$app->usePublicPath($app->basePath('public_html'));

return $app;

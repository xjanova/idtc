<?php

namespace App\Http\Middleware;

use App\Support\SiteLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ตั้งภาษาของหน้าสาธารณะตาม URL (ไทย = ไม่มี prefix, อังกฤษ = /en)
 *
 * ผู้เข้าชมที่เคยกดเลือกภาษาอังกฤษเอง (คุกกี้ idtc_locale=en) แล้วเข้าเว็บจากข้างนอก
 * (พิมพ์ URL, บุ๊กมาร์ก, ผลค้นหา) ที่หน้าภาษาไทย จะถูกพาไปหน้าเดียวกันภาษาอังกฤษ
 *
 * ไม่เดาภาษาจาก Accept-Language และไม่ redirect ใครที่ไม่มีคุกกี้ — bot ของ search engine
 * ไม่ส่งคุกกี้ จึงเห็นแต่ละ URL ตรงตามภาษาของมันเสมอ การเลื่อนไปมาภายในเว็บก็ไม่ถูก redirect
 */
class SetSiteLocale
{
    public function handle(Request $request, Closure $next, string $locale = SiteLocale::DEFAULT): Response
    {
        if (! SiteLocale::isSupported($locale)) {
            $locale = SiteLocale::DEFAULT;
        }
        app()->setLocale($locale);

        if ($locale === SiteLocale::DEFAULT && $this->shouldSendToChosen($request)) {
            $target = SiteLocale::switchUrl((string) SiteLocale::chosen($request), $request->route());
            $query = $request->getQueryString();

            return redirect()->to($target.($query ? '?'.$query : ''), 302)
                ->header('Cache-Control', 'private, no-store')
                ->header('Vary', 'Cookie');
        }

        $response = $next($request);
        if ($locale === SiteLocale::DEFAULT) {
            // หน้าไทยตอบต่างกันตามคุกกี้ภาษา (redirect หรือไม่) — กัน cache กลางทางส่งผิดคน
            $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Cookie', ', '));
        }

        return $response;
    }

    protected function shouldSendToChosen(Request $request): bool
    {
        $chosen = SiteLocale::chosen($request);
        if ($chosen === null || $chosen === SiteLocale::DEFAULT || ! $request->isMethodSafe()) {
            return false;
        }

        // มาจากหน้าในเว็บเราเอง = ผู้ใช้กดลิงก์หน้าภาษาไทยเอง ไม่ต้องพากลับ
        $referer = (string) $request->headers->get('referer', '');
        $host = $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;

        return ! (is_string($host) && strcasecmp($host, $request->getHost()) === 0);
    }
}

<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * ภาษาของเว็บสาธารณะ: ไทยเป็นค่าเริ่มต้นที่ URL เดิม อังกฤษอยู่ใต้ /en
 *
 * ชื่อ route: ไทย = 'presale', อังกฤษ = 'en.presale' — ใน Blade ใช้ lroute('presale') เสมอ
 * แล้วได้ลิงก์ภาษาเดียวกับหน้าที่กำลังเปิดอยู่
 */
class SiteLocale
{
    public const DEFAULT = 'th';

    /** ภาษา => [html lang, og:locale, locale วันที่ใน JS] */
    public const SUPPORTED = [
        'th' => ['html' => 'th', 'og' => 'th_TH', 'date' => 'th-TH'],
        'en' => ['html' => 'en', 'og' => 'en_US', 'date' => 'en-GB'],
    ];

    /** หน้าสาธารณะที่มีครบทั้งสองภาษา (ชื่อ route ภาษาไทย) */
    public const PAGES = ['home', 'presale', 'transparency', 'whitepaper', 'add-network'];

    /** คุกกี้จำภาษาที่ผู้เข้าชมเลือกเอง (ตั้งจาก JS ตอนกดสลับภาษา — ไม่เข้ารหัส) */
    public const COOKIE = 'idtc_locale';

    public static function current(): string
    {
        $locale = app()->getLocale();

        return isset(self::SUPPORTED[$locale]) ? $locale : self::DEFAULT;
    }

    public static function isSupported(?string $locale): bool
    {
        return is_string($locale) && isset(self::SUPPORTED[$locale]);
    }

    public static function routeName(string $page, ?string $locale = null): string
    {
        $locale ??= self::current();

        return $locale === self::DEFAULT ? $page : $locale.'.'.$page;
    }

    /** ลิงก์หน้าสาธารณะในภาษาที่กำหนด (ค่าเริ่มต้น = ภาษาของหน้าปัจจุบัน) */
    public static function route(string $page, array $params = [], ?string $locale = null, bool $absolute = true): string
    {
        return route(self::routeName($page, $locale), $params, $absolute);
    }

    /** ชื่อหน้า (แบบไทย) ของ route ที่กำลังเปิด — null ถ้าไม่ใช่หน้าสาธารณะที่มีสองภาษา */
    public static function page(?Route $route = null): ?string
    {
        $name = ($route ?? request()->route())?->getName();
        if (! is_string($name)) {
            return null;
        }
        foreach (array_keys(self::SUPPORTED) as $locale) {
            if ($locale !== self::DEFAULT && str_starts_with($name, $locale.'.')) {
                $name = substr($name, strlen($locale) + 1);
                break;
            }
        }

        return in_array($name, self::PAGES, true) ? $name : null;
    }

    /** หน้าเดียวกันในอีกภาษา (หน้าอื่นที่ไม่มีสองภาษา → หน้าแรกของภาษานั้น) */
    public static function switchUrl(string $locale, ?Route $route = null): string
    {
        return self::route(self::page($route) ?? 'home', [], $locale);
    }

    /** @return array<string, string> hreflang => URL (รวม x-default = ภาษาไทย) */
    public static function alternates(?Route $route = null): array
    {
        $out = [];
        foreach (array_keys(self::SUPPORTED) as $locale) {
            $out[$locale] = self::switchUrl($locale, $route);
        }
        $out['x-default'] = $out[self::DEFAULT];

        return $out;
    }

    public static function htmlLang(): string
    {
        return self::SUPPORTED[self::current()]['html'];
    }

    public static function ogLocale(?string $locale = null): string
    {
        return self::SUPPORTED[$locale ?? self::current()]['og'];
    }

    public static function dateLocale(): string
    {
        return self::SUPPORTED[self::current()]['date'];
    }

    /** ปีปัจจุบันตามปฏิทินของภาษา: ไทย = พ.ศ. อังกฤษ = ค.ศ. */
    public static function year(?int $gregorian = null): int
    {
        $year = $gregorian ?? (int) now()->year;

        return self::current() === 'th' ? $year + 543 : $year;
    }

    /**
     * จำนวนเหรียญก้อนใหญ่ เป็นตัวเลขอย่างเดียว: ไทยนับเป็น "ล้าน" อังกฤษนับเป็น "billion"
     * 100000000000 → ไทย "100,000" · อังกฤษ "100"
     */
    public static function bigValue(float|int|string $value): string
    {
        $value = (float) $value;
        $exp = (int) __('site.big.exp');
        if ($exp === 6) {
            return number_format($value / 1e6);
        }

        $out = number_format($value / 10 ** $exp, 2);

        return str_contains($out, '.') ? (preg_replace('/\.?0+$/', '', $out) ?? $out) : $out;
    }

    /** เหมือน bigValue แต่ต่อหน่วยท้าย: "100,000 ล้าน" · "100 billion" */
    public static function big(float|int|string $value): string
    {
        return self::bigValue($value).' '.__('site.big.unit');
    }

    /** ผู้เข้าชมเลือกภาษาไว้ในคุกกี้หรือไม่ (ค่าที่ไม่รู้จักถือว่าไม่ได้เลือก) */
    public static function chosen(Request $request): ?string
    {
        $value = $request->cookies->get(self::COOKIE);

        return self::isSupported($value) ? $value : null;
    }
}

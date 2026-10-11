<?php

use App\Support\SiteLocale;

if (! function_exists('lroute')) {
    /**
     * ลิงก์หน้าสาธารณะในภาษาของหน้าที่กำลังเปิด — lroute('presale') ได้ /presale หรือ /en/presale
     * ส่ง $locale เพื่อขอลิงก์ภาษาที่ระบุ
     */
    function lroute(string $page, array $params = [], ?string $locale = null, bool $absolute = true): string
    {
        return SiteLocale::route($page, $params, $locale, $absolute);
    }
}

<?php

namespace App\Services\Chain;

use phpseclib3\Math\BigInteger;

/**
 * แปลงจำนวนเต็มหน่วยเล็กสุด (wei, micro-USDT) ⇄ ตัวเลขที่คนอ่าน โดยไม่ผ่าน float เมื่อต้องแม่นยำ
 */
final class Units
{
    /** "1500000", 6 → "1.5" (แม่นยำ ตัดศูนย์ท้าย) */
    public static function fromRaw(?string $raw, int $decimals): string
    {
        $raw = ltrim((string) $raw, '0');
        if ($raw === '' || ! ctype_digit($raw)) {
            return '0';
        }
        if ($decimals === 0) {
            return $raw;
        }
        $raw = str_pad($raw, $decimals + 1, '0', STR_PAD_LEFT);
        $int = substr($raw, 0, -$decimals);
        $frac = rtrim(substr($raw, -$decimals), '0');

        return $frac === '' ? $int : $int.'.'.$frac;
    }

    /** แสดงผลมีจุลภาค ตัด (ไม่ปัด) ทศนิยมเหลือ $maxFrac หลัก */
    public static function format(?string $raw, int $decimals, int $maxFrac = 2): string
    {
        return self::group(self::fromRaw($raw, $decimals), $maxFrac);
    }

    /** "1234567.891" → "1,234,567.89" */
    public static function group(string $decimal, int $maxFrac = 2): string
    {
        [$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $neg = str_starts_with($int, '-');
        $int = ltrim($int, '-') ?: '0';
        $int = strrev(implode(',', str_split(strrev($int), 3)));
        $frac = rtrim(substr($frac, 0, $maxFrac), '0');

        return ($neg ? '-' : '').$int.($frac !== '' ? '.'.$frac : '');
    }

    public static function toFloat(?string $raw, int $decimals): float
    {
        return (float) self::fromRaw($raw, $decimals);
    }

    /** "1.5", 6 → "1500000" · คืน null ถ้ารูปแบบผิดหรือทศนิยมเกิน */
    public static function toRaw(string $human, int $decimals): ?string
    {
        $human = trim(str_replace(',', '', $human));
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $human, $m)) {
            return null;
        }
        $frac = $m[2] ?? '';
        if (strlen(rtrim($frac, '0')) > $decimals) {
            return null;
        }
        $raw = ltrim($m[1].str_pad(substr($frac, 0, $decimals), $decimals, '0'), '0');

        return $raw === '' ? '0' : $raw;
    }

    /** จำนวนสั้นแบบ 87.52B / 1.1T / 12.5M */
    public static function compact(float $n, int $frac = 2): string
    {
        $abs = abs($n);
        foreach ([[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$size, $suffix]) {
            if ($abs >= $size) {
                return rtrim(rtrim(number_format($n / $size, $frac, '.', ','), '0'), '.').$suffix;
            }
        }

        return rtrim(rtrim(number_format($n, $frac, '.', ','), '0'), '.') ?: '0';
    }

    public static function hexToDec(?string $hex): string
    {
        $h = ltrim(strtolower(preg_replace('/^0x/i', '', (string) $hex) ?? ''), '0');

        return $h === '' ? '0' : (new BigInteger($h, 16))->toString();
    }

    public static function hexToInt(?string $hex): int
    {
        return (int) self::hexToDec($hex);
    }

    public static function cmp(string $a, string $b): int
    {
        return (new BigInteger($a ?: '0'))->compare(new BigInteger($b ?: '0')) <=> 0;
    }

    public static function sub(string $a, string $b): string
    {
        return (new BigInteger($a ?: '0'))->subtract(new BigInteger($b ?: '0'))->toString();
    }

    public static function add(string $a, string $b): string
    {
        return (new BigInteger($a ?: '0'))->add(new BigInteger($b ?: '0'))->toString();
    }
}

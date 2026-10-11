<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Services\Chain\Address;
use App\Services\Chain\Units;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * การแสดงผลของหลังบ้าน: เวลาไทย (พ.ศ., Asia/Bangkok) และจำนวนเหรียญ
 */
final class AdminFormat
{
    public const TZ = 'Asia/Bangkok';

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public static function at(CarbonInterface|int|string|null $time): ?Carbon
    {
        if ($time === null || $time === '' || $time === 0) {
            return null;
        }
        $c = is_int($time) ? Carbon::createFromTimestampUTC($time) : Carbon::parse($time);

        return $c->setTimezone(self::TZ);
    }

    /** 11 ต.ค. 2569 */
    public static function date(CarbonInterface|int|string|null $time): string
    {
        $c = self::at($time);

        return $c ? $c->day.' '.self::MONTHS[$c->month - 1].' '.($c->year + 543) : '—';
    }

    /** 11 ต.ค. 2569 14:05 */
    public static function dateTime(CarbonInterface|int|string|null $time): string
    {
        $c = self::at($time);

        return $c ? self::date($c).' '.$c->format('H:i') : '—';
    }

    /** 11 ต.ค. */
    public static function dayMonth(CarbonInterface|int|string|null $time): string
    {
        $c = self::at($time);

        return $c ? $c->day.' '.self::MONTHS[$c->month - 1] : '—';
    }

    /** ค่าเริ่มต้นของ input datetime-local ในเวลาไทย */
    public static function inputValue(CarbonInterface|int|string|null $time): string
    {
        $c = self::at($time);

        return $c ? $c->format('Y-m-d\TH:i') : '';
    }

    public static function usdt(?string $raw, int $frac = 2): string
    {
        return $raw === null ? '—' : Units::format($raw, 6, $frac);
    }

    public static function idtc(?string $raw, int $frac = 0): string
    {
        return $raw === null ? '—' : Units::format($raw, 18, $frac);
    }

    public static function tpix(?string $raw, int $frac = 4): string
    {
        return $raw === null ? '—' : Units::format($raw, 18, $frac);
    }

    public static function money(float $n, int $frac = 2): string
    {
        return number_format($n, $frac);
    }

    /** ราคาเล็กมาก ๆ เช่น 0.0000015 — แสดงเลขนัยสำคัญ 4 หลัก */
    public static function price(?float $n): string
    {
        if ($n === null) {
            return '—';
        }
        if ($n == 0.0) {
            return '0';
        }
        $digits = max(2, (int) -floor(log10(abs($n))) + 3);

        return rtrim(rtrim(number_format($n, min($digits, 18), '.', ','), '0'), '.');
    }

    public static function addr(?string $address): string
    {
        return Address::isValid($address) ? Address::checksum((string) $address) : '—';
    }

    /**
     * รายละเอียดของบันทึกการทำงานเป็นบรรทัดอ่านง่าย [ป้าย, ค่า]
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function auditLines(AuditLog $log): array
    {
        $d = $log->details ?? [];
        $lines = [];
        $show = function (mixed $v) use (&$show): string {
            return match (true) {
                $v === null || $v === '' || $v === [] => '(ว่าง)',
                is_bool($v) => $v ? 'ใช่' : 'ไม่',
                is_array($v) && array_is_list($v) => implode(' · ', array_map(fn ($x) => is_array($x) ? trim(($x['label'] ?? '').' '.($x['address'] ?? '')) : $show($x), $v)),
                is_array($v) => implode(' · ', array_map(fn ($k, $x) => $k.': '.$show($x), array_keys($v), $v)),
                default => (string) $v,
            };
        };

        if (isset($d['key'])) {
            $lines[] = ['รายการ', (string) $d['key']];
        }
        if (array_key_exists('old', $d) || array_key_exists('new', $d)) {
            $lines[] = ['เดิม → ใหม่', $show($d['old'] ?? null).' → '.$show($d['new'] ?? null)];
        }
        if (isset($d['address'])) {
            $lines[] = ['กระเป๋า', (string) $d['address']];
        }
        if (isset($d['contract'])) {
            $lines[] = ['สัญญา', (string) $d['contract']];
        }
        foreach ((array) ($d['params'] ?? []) as $name => $value) {
            $lines[] = [self::paramLabel((string) $name), self::paramValue((string) $name, (string) $value)];
        }
        if (isset($d['from'])) {
            $lines[] = ['ส่งจากกระเป๋า', (string) $d['from']];
        }
        if (isset($d['result']['reason'])) {
            $lines[] = ['ผล', (string) $d['result']['reason']];
        }
        if (isset($d['message'])) {
            $lines[] = ['ผล', (string) $d['message']];
        }

        return $lines;
    }

    private static function paramLabel(string $name): string
    {
        return [
            'minPurchase' => 'ขั้นต่ำต่อครั้ง',
            'maxPerWallet' => 'เพดานต่อกระเป๋า',
            'startTime' => 'เปิดขาย',
            'endTime' => 'ปิดขาย',
            'treasury' => 'กระเป๋ารับเงินใหม่',
            'to' => 'ปลายทาง',
            'newOwner' => 'เจ้าของใหม่',
            'newBeneficiary' => 'ผู้รับใหม่',
            'token' => 'เหรียญ',
        ][$name] ?? $name;
    }

    private static function paramValue(string $name, string $value): string
    {
        return match ($name) {
            'minPurchase' => Units::format($value, 6, 6).' USDT',
            'maxPerWallet' => $value === '0' ? 'ไม่จำกัด' : Units::format($value, 18, 0).' IDTC',
            'startTime', 'endTime' => self::dateTime((int) $value).' (เวลาไทย)',
            default => $value,
        };
    }
}

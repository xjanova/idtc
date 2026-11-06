<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * กำหนดการเปิดขายและตารางปล่อยเหรียญตามแผน (config/idtc.php → sale, launchpad, vesting) ในภาษาของหน้า
 *
 * ทุกวันที่เป็น "แผน" — เมื่อสัญญาขึ้นเชน หน้า presale นับถอยหลังจากเวลาในสัญญา และหน้าความโปร่งใสอ่านงวดจริงจากสัญญาล็อก
 * vesting เริ่มนับวันเดียวกับวันเปิดขาย (LaunchpadFactory ตั้ง vesting.start = startTime)
 */
class Launch
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** วันในแผน: start / end (config idtc.sale) หรือ lp_unlock (config idtc.launchpad) */
    public static function at(string $key): ?CarbonImmutable
    {
        $value = $key === 'lp_unlock' ? config('idtc.launchpad.lp_unlock') : config("idtc.sale.$key");

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->setTimezone(self::TIMEZONE) : null;
    }

    /** "1 ธ.ค. 2569" · "1 Dec 2026" */
    public static function date(string|CarbonImmutable $when): string
    {
        $at = $when instanceof CarbonImmutable ? $when->setTimezone(self::TIMEZONE) : static::at($when);
        if ($at === null) {
            return __('site.pending');
        }
        $months = (array) __('launch.months');

        return $at->day.' '.($months[$at->month - 1] ?? $at->format('M')).' '.SiteLocale::year((int) $at->year);
    }

    /** "1 ธ.ค. 2569 เวลา 10:00 น." · "1 Dec 2026, 10:00 Bangkok time (UTC+7)" */
    public static function dateTime(string $key): string
    {
        $at = static::at($key);

        return $at === null ? __('site.pending') : __('launch.at_time', ['date' => static::date($at), 'time' => $at->format('H:i')]);
    }

    /** ค่าสำหรับ <time datetime> */
    public static function iso(string $key): ?string
    {
        return static::at($key)?->toIso8601String();
    }

    public static function durationDays(): ?int
    {
        [$start, $end] = [static::at('start'), static::at('end')];

        return $start && $end ? (int) round($start->diffInSeconds($end) / 86400) : null;
    }

    public static function poolSharePercent(): string
    {
        return static::percent((int) config('idtc.launchpad.pool_share_bps', 0));
    }

    public static function bandPercent(): string
    {
        return static::percent((int) config('idtc.launchpad.band_bps', 0));
    }

    /**
     * ตารางปลดของสัญญาล็อก: แต่ละก้อนล็อกเต็ม cliff งวด แล้วปลดงวดละเท่ากัน release งวด
     * งวดแรกปลดเมื่อครบ cliff + 1 งวดนับจากวันเริ่ม (สูตรเดียวกับ TrancheVesting.vestedAmount)
     *
     * @return list<array{name: string, amount: float, cliff: int, release: int, per_period: float, first: ?string, last: ?string}>
     */
    public static function releaseSchedule(): array
    {
        $start = static::at('start');
        $period = (int) config('idtc.vesting.period_seconds');

        return array_map(function (array $t) use ($start, $period) {
            $at = fn (int $periods) => $start ? static::date($start->addSeconds($periods * $period)) : null;

            return [
                'name' => $t['name'],
                'amount' => (float) $t['amount'],
                'cliff' => $t['cliff'],
                'release' => $t['release'],
                'per_period' => $t['release'] > 0 ? (float) $t['amount'] / $t['release'] : (float) $t['amount'],
                'first' => $at($t['cliff'] + 1),
                'last' => $at($t['cliff'] + $t['release']),
            ];
        }, Tokenomics::tranches());
    }

    private static function percent(int $bps): string
    {
        $out = number_format($bps / 100, 2);

        return preg_replace('/\.?0+$/', '', $out) ?? $out;
    }
}

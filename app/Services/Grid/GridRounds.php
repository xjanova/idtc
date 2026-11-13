<?php

namespace App\Services\Grid;

use App\Services\Chain\RewardsReader;
use App\Services\Payout\PayoutRounds;
use Carbon\CarbonImmutable;

/**
 * รอบจ่ายรางวัล Grid (สาย grid): สร้างรายชื่อจากยอดครบระยะ → เจ้าของประกาศ root บนสัญญา GridRewards → สมาชิกกดรับเอง
 * กลไกรอบจ่าย (จัดสรร/ประกาศ/ยกเลิก/รับ) อยู่ใน PayoutRounds — คลาสนี้กำหนดเฉพาะของ Grid:
 *
 *  - ยอดของรอบ = ยอด "ครบระยะถือ" (GridLedger: ready รวม Sprint) − ยอดที่จัดสรรไปแล้วในรอบ draft/live
 *    กระเป๋าที่ถูกพักไม่เข้ารอบ
 *  - ก่อนปิดการขาย แบ่งตามแผน "instant": ส่วนรับได้ทันที (รอบ instant) + ส่วนที่ปลดเมื่อปิดการขาย (รอบ deferred)
 *  - จำนวนเก็บเป็นหน่วยเล็กสุด ปัดลงที่ 0.000001 IDTC (เศษคืนเข้างบ)
 */
final class GridRounds extends PayoutRounds
{
    public const STREAM = 'grid';

    public const MIN_IDTC = 1.0;

    public function __construct(protected GridLedger $ledger, protected GridPlan $plans, RewardsReader $reader)
    {
        $this->reader = $reader;
    }

    public function stream(): string
    {
        return self::STREAM;
    }

    public function unit(): string
    {
        return 'IDTC';
    }

    public function minDue(): float
    {
        return self::MIN_IDTC;
    }

    public function raw(int $micro): string
    {
        return self::wei($micro);
    }

    protected function lockKey(): string
    {
        return 'idtc:grid:rounds:build';
    }

    /**
     * ยอดที่รอบถัดไปจะจ่าย
     *
     * @return list<array{wallet: string, ready: float, allocated: float, due: float}>
     */
    public function due(GridBook $book): array
    {
        return $this->dueFrom($this->readyOf($book));
    }

    protected function readyNow(CarbonImmutable $now): array
    {
        return $this->readyOf($this->ledger->book($now, true));
    }

    /** @return array<string, float> ยอดครบระยะของกระเป๋าที่ไม่ถูกพัก */
    private function readyOf(GridBook $book): array
    {
        $ready = [];
        foreach ($book->nodes as $w => $n) {
            if (! $n['held']) {
                $ready[$w] = (float) $n['ready'];
            }
        }

        return $ready;
    }

    protected function split(CarbonImmutable $now): ?array
    {
        $instant = (float) $this->plans->current($now)['instant'];
        $saleEnd = CarbonImmutable::parse((string) config('idtc.sale.end'))->setTimezone(config('app.timezone')); // คอลัมน์เวลาเก็บตามเขตเวลาแอป
        if (! ($instant > 0 && $instant < 1 && $now->lt($saleEnd))) {
            return null;
        }

        return ['ppm' => (int) round($instant * 1_000_000), 'unlock' => $saleEnd];
    }

    /** หน่วย 0.000001 IDTC → หน่วยเล็กสุด (18 ทศนิยม) */
    public static function wei(int $micro): string
    {
        return $micro <= 0 ? '0' : $micro.str_repeat('0', (int) config('idtc.token.decimals', 18) - 6);
    }
}

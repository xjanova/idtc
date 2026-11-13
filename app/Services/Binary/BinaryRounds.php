<?php

namespace App\Services\Binary;

use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Services\Chain\RewardsReader;
use App\Services\Grid\GridRounds;
use App\Services\Payout\PayoutRounds;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * รอบจ่ายของแผน Binary โปรโมชั่น — สองสาย แต่ละสายมีสัญญา GridRewards ของตัวเอง (เจ้าของเซ็นประกาศรอบเอง เซิร์ฟเวอร์ไม่ถือคีย์):
 *
 *   bin_usdt  รางวัลจับคู่ จ่ายเป็น USDT  · สัญญา contracts.binpay (GridRewards ที่ deploy ให้เหรียญ USDT)
 *             ยอดถึงกำหนด = รางวัลของสัปดาห์ที่ปิดแล้ว (BinaryLedger: ready)
 *   bin_idtc  IDTC ที่สมาชิกซื้อในแผน     · สัญญา contracts.bindel (GridRewards ที่ deploy ให้เหรียญ IDTC)
 *             ยอดถึงกำหนด = IDTC ของรายการฝากที่นับแล้วทั้งหมด (ส่งได้ทันที ไม่มีระยะรอ)
 *             คิดจาก binary_deposits.idtc_micro (จำนวนเต็มแม่นยำ ปัดลง) — ผู้ซื้อได้ไม่เกินที่จ่ายจริงแม้เศษเสี้ยวหน่วย
 *
 * กระเป๋าที่ถูกพัก (ตรวจสอบ) ไม่เข้ารอบทั้งสองสายจนกว่าจะปลด · กลไกรอบจ่ายอยู่ใน PayoutRounds
 */
final class BinaryRounds extends PayoutRounds
{
    public const USDT = 'bin_usdt';

    public const IDTC = 'bin_idtc';

    /** สาย => key ของสัญญาใน config idtc.contracts */
    public const CONTRACT = [self::USDT => 'binpay', self::IDTC => 'bindel'];

    public const LABELS = [self::USDT => 'รางวัลจับคู่ (USDT)', self::IDTC => 'ส่ง IDTC ที่ซื้อ'];

    public function __construct(protected BinaryLedger $ledger, RewardsReader $reader, protected string $stream = self::USDT)
    {
        if (! isset(self::CONTRACT[$stream])) {
            throw new InvalidArgumentException('unknown binary payout stream: '.$stream);
        }
        $this->reader = $reader->for(self::CONTRACT[$stream]);
    }

    public static function of(string $stream): self
    {
        return app()->makeWith(self::class, ['stream' => $stream]);
    }

    public function stream(): string
    {
        return $this->stream;
    }

    public function unit(): string
    {
        return $this->stream === self::USDT ? 'USDT' : 'IDTC';
    }

    public function minDue(): float
    {
        return $this->stream === self::USDT ? 0.01 : 1.0;
    }

    /** USDT มี 6 ทศนิยม: หน่วย 0.000001 = หน่วยเล็กสุดพอดี · IDTC 18 ทศนิยม */
    public function raw(int $micro): string
    {
        return $this->stream === self::USDT ? ($micro <= 0 ? '0' : (string) $micro) : GridRounds::wei($micro);
    }

    protected function readyMicroNow(CarbonImmutable $now): ?array
    {
        if ($this->stream !== self::IDTC) {
            return null;
        }
        $held = GridMember::query()->whereNotNull('held_at')->pluck('wallet')->flip();
        $ready = [];
        foreach (BinaryDeposit::query()->where('status', BinaryDeposit::OK)->toBase()->get(['wallet', 'idtc_micro']) as $d) {
            if (! isset($held[$d->wallet])) {
                $ready[$d->wallet] = ($ready[$d->wallet] ?? 0) + (int) $d->idtc_micro;
            }
        }

        return $ready;
    }

    protected function readyNow(CarbonImmutable $now): array
    {
        $field = $this->stream === self::USDT ? 'ready' : 'idtc';
        $ready = [];
        foreach ($this->ledger->compute($now)['nodes'] as $w => $n) {
            if (! $n['held'] && $n[$field] > 0) {
                $ready[$w] = (float) $n[$field];
            }
        }

        return $ready;
    }
}

<?php

namespace Tests\Feature\Grid;

use App\Models\GridMember;
use App\Models\IndexerState;
use App\Models\Purchase;
use App\Services\Chain\Abi;
use App\Services\Chain\TransferIndexer;
use App\Services\Grid\GridLedger;
use App\Services\Grid\HoldCheck;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * ตรวจการถือครบระยะจาก event Transfer — ขาย/โอนออกก่อนครบ = ตัดรางวัลส่วนนั้น
 */
class GridHoldTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const DAY = 86400;

    private const HOLD = 14 * self::DAY;

    private const T0 = 1_796_000_000;

    private string $token = '0x7777777777777777777777777777777777777777';

    private string $sale = '0x3333333333333333333333333333333333333333';

    private string $me = '0x00000000000000000000000000000000000000a1';

    private string $dex = '0x00000000000000000000000000000000000000d1';

    private function p(int $id, float $idtc, int $day, int $block): array
    {
        return ['id' => $id, 'buyer' => $this->me, 'idtc' => $idtc, 'block' => $block, 'log' => 1, 'at' => self::T0 + $day * self::DAY];
    }

    private function t(string $from, string $to, float $amount, int $day, int $block, int $log = 0): array
    {
        return ['from' => $from, 'to' => $to, 'amount' => $amount, 'block' => $block, 'log' => $log, 'at' => self::T0 + $day * self::DAY];
    }

    /** ซื้อ = เหรียญเข้าจากสัญญาขาย (log 0) + ล็อก (log 1) ในบล็อกเดียวกัน */
    private function bought(float $idtc, int $day, int $block): array
    {
        return $this->t($this->sale, $this->me, $idtc, $day, $block);
    }

    public function test_holding_through_the_period_keeps_everything(): void
    {
        $r = HoldCheck::run([$this->p(1, 1000, 0, 10)], [$this->bought(1000, 0, 10), $this->t($this->me, $this->dex, 1000, 15, 50)], self::HOLD);

        $this->assertSame(1.0, $r['lots'][1]['kept']);   // ขายหลังครบ 14 วัน = ไม่กระทบ
        $this->assertSame([], $r['gaps']);
    }

    public function test_selling_inside_the_period_cuts_that_part(): void
    {
        $r = HoldCheck::run([$this->p(1, 1000, 0, 10)], [$this->bought(1000, 0, 10), $this->t($this->me, $this->dex, 400, 3, 20)], self::HOLD);

        $this->assertEqualsWithDelta(0.6, $r['lots'][1]['kept'], 1e-12);
        $this->assertSame(self::T0 + 3 * self::DAY, $r['lots'][1]['cut_at']);
    }

    public function test_coins_held_before_the_purchase_are_a_buffer(): void
    {
        $r = HoldCheck::run([$this->p(1, 1000, 1, 10)], [
            $this->t($this->dex, $this->me, 500, 0, 5),        // มีอยู่ก่อน 500
            $this->bought(1000, 1, 10),
            $this->t($this->me, $this->dex, 400, 2, 20),       // ออก 400 จากกันชน → ไม่ตัด
            $this->t($this->me, $this->dex, 300, 3, 30),       // กันชนเหลือ 100 → ตัด 200
        ], self::HOLD);

        $this->assertEqualsWithDelta(0.8, $r['lots'][1]['kept'], 1e-12);
    }

    public function test_the_newest_purchase_is_cut_first_and_cuts_never_come_back(): void
    {
        $r = HoldCheck::run([$this->p(1, 1000, 0, 10), $this->p(2, 1000, 5, 20)], [
            $this->bought(1000, 0, 10),
            $this->bought(1000, 5, 20),
            $this->t($this->me, $this->dex, 1500, 6, 30),
            $this->t($this->dex, $this->me, 1500, 7, 40),      // ซื้อคืนก็ไม่คืนรางวัลที่ตัดแล้ว
        ], self::HOLD);

        $this->assertSame(0.0, $r['lots'][2]['kept']);
        $this->assertEqualsWithDelta(0.5, $r['lots'][1]['kept'], 1e-12);
    }

    public function test_missing_history_never_cuts(): void
    {
        // ออกมากกว่าที่เห็นว่าเข้า = มีเหรียญก่อนบล็อกเริ่มดึง → ไม่ตัดอะไรของกระเป๋านี้
        $r = HoldCheck::run([$this->p(1, 1000, 0, 10)], [$this->bought(1000, 0, 10), $this->t($this->me, $this->dex, 5000, 2, 20)], self::HOLD);

        $this->assertSame(1.0, $r['lots'][1]['kept']);
        $this->assertArrayHasKey($this->me, $r['gaps']);
    }

    public function test_transfers_to_self_are_ignored(): void
    {
        $r = HoldCheck::run([$this->p(1, 1000, 0, 10)], [$this->bought(1000, 0, 10), $this->t($this->me, $this->me, 1000, 2, 20)], self::HOLD);

        $this->assertSame(1.0, $r['lots'][1]['kept']);
    }

    // ---- ตัวดึง event Transfer ----

    public function test_indexer_stores_transfers_and_the_time_it_has_covered(): void
    {
        config(['idtc.contracts.token' => $this->token]);
        app(Settings::class)->put('indexer.start_block', 100);
        $word = fn (string $a) => '0x'.str_pad(substr($a, 2), 64, '0', STR_PAD_LEFT);
        $this->fakeRpc(function (string $m, array $p) use ($word) {
            return match ($m) {
                'eth_blockNumber' => '0x'.dechex(300),
                'eth_getBlockByNumber' => ['number' => $p[0], 'timestamp' => '0x'.dechex(self::T0 + hexdec($p[0]))],
                'eth_getLogs' => [
                    ['address' => $this->token, 'topics' => [TransferIndexer::topic(), $word($this->sale), $word($this->me)],
                        'data' => '0x'.Abi::encodeUint('2500000000000000000000'), 'blockNumber' => '0x96', 'transactionHash' => '0x'.str_repeat('a', 64), 'logIndex' => '0x0'],
                    ['address' => '0x1212121212121212121212121212121212121212', 'topics' => [TransferIndexer::topic(), $word($this->sale), $word($this->me)],
                        'data' => '0x'.Abi::encodeUint('1'), 'blockNumber' => '0x97', 'transactionHash' => '0x'.str_repeat('b', 64), 'logIndex' => '0x0'],
                ],
                default => ['__error' => 'not faked: '.$m],
            };
        });

        $res = app(TransferIndexer::class)->run();

        $this->assertTrue($res['ok']);
        $this->assertSame(1, DB::table('token_transfers')->count());   // สัญญาอื่นไม่เก็บ
        $row = DB::table('token_transfers')->first();
        $this->assertSame($this->me, $row->to);
        $this->assertEqualsWithDelta(2500.0, (float) $row->amount, 1e-9);
        $this->assertSame(self::T0 + 300, app(TransferIndexer::class)->coveredUntil());
        $this->assertSame(100, $res['from']);
    }

    public function test_indexer_is_idle_without_a_token_address(): void
    {
        config(['idtc.contracts.token' => null]);

        $res = app(TransferIndexer::class)->run();

        $this->assertSame('idle', $res['status']);
        $this->assertFalse(app(HoldCheck::class)->enabled());
    }

    // ---- เข้ากับการคำนวณรางวัล ----

    private function seedLedger(float $soldIdtc, ?int $coveredAt): void
    {
        config(['idtc.contracts.token' => $this->token]);
        $ref = '0x00000000000000000000000000000000000000b1';
        GridMember::query()->create(['wallet' => $ref, 'referrer' => null]);
        GridMember::query()->create(['wallet' => $this->me, 'referrer' => $ref, 'bound_at' => '2026-12-01 12:00:00']);
        // สายบนต้องซื้อเองถึงสปาร์กจึงปลดวง 1
        foreach ([[$ref, 50, 'c'], [$this->me, 100, 'd']] as $i => [$who, $usdt, $b]) {
            Purchase::query()->create([
                'contract' => $this->sale, 'tx_hash' => '0x'.str_repeat($b, 64), 'log_index' => 1, 'buyer' => $who,
                'pay_amount' => (string) ($usdt * 1e6), 'tokens_out' => '0', 'pay_usdt' => $usdt, 'tokens_idtc' => $usdt * 1000,
                'block_number' => 10 + $i, 'block_time' => '2026-12-02 0'.($i + 1).':00:00',
            ]);
            DB::table('token_transfers')->insert([
                'contract' => $this->token, 'tx_hash' => '0x'.str_repeat($b, 64), 'log_index' => 0, 'from' => $this->sale, 'to' => $who,
                'value' => '0', 'amount' => $usdt * 1000, 'block_number' => 10 + $i, 'block_time' => '2026-12-02 0'.($i + 1).':00:00',
            ]);
        }
        if ($soldIdtc > 0) {
            DB::table('token_transfers')->insert([
                'contract' => $this->token, 'tx_hash' => '0x'.str_repeat('e', 64), 'log_index' => 0, 'from' => $this->me, 'to' => $this->dex,
                'value' => '0', 'amount' => $soldIdtc, 'block_number' => 20, 'block_time' => '2026-12-05 00:00:00',
            ]);
        }
        IndexerState::query()->create([
            'key' => TransferIndexer::STATE_KEY, 'contract' => $this->token, 'last_block' => 99,
            'last_block_time' => $coveredAt ? CarbonImmutable::createFromTimestampUTC($coveredAt) : null,
        ]);
    }

    public function test_ledger_returns_the_sold_part_to_the_budget(): void
    {
        $this->seedLedger(25_000, CarbonImmutable::parse('2026-12-30')->getTimestamp());
        $book = app(GridLedger::class)->compute(CarbonImmutable::parse('2026-12-31'));

        $ev = $book->events[1];
        $this->assertEqualsWithDelta(0.75, $ev['kept'], 1e-12);
        $byStatus = [];
        foreach ($ev['rewards'] as $r) {
            $byStatus[$r['kind'].$r['ring'].$r['status']] = $r['amount'];
        }
        $idtc = 100_000.0;
        $this->assertEqualsWithDelta($idtc * 0.07 * 0.75, $byStatus['ring1ready'], 1e-6);   // 5% + โบนัสสัปดาห์แรก 2%
        $this->assertEqualsWithDelta($idtc * 0.07 * 0.25, $byStatus['ring1sold'], 1e-6);
        $this->assertEqualsWithDelta($idtc * 0.01 * 0.25, $byStatus['buyer0sold'], 1e-6);
        $this->assertEqualsWithDelta($idtc * 0.08 * 0.25, $book->totals['returned_sold'], 1e-6);
        // ยอดซื้อของตัวเองนับเฉพาะส่วนที่ยังถือ
        $this->assertEqualsWithDelta(75.0, $book->node($this->me)['own_usdt'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $book->node($this->me)['bought_usdt'], 1e-9);
        $this->assertTrue($book->totals['hold']['enabled']);
    }

    public function test_nothing_is_ready_until_transfers_are_indexed_past_the_hold_date(): void
    {
        // ครบ 14 วันตามเวลาแล้ว แต่ดึงการโอนได้แค่ถึงวันที่ 10 → ยังตัดสินไม่ได้
        $this->seedLedger(0, CarbonImmutable::parse('2026-12-10')->getTimestamp());
        $book = app(GridLedger::class)->compute(CarbonImmutable::parse('2026-12-31'));

        $this->assertSame(0.0, $book->totals['ready']);
        $this->assertGreaterThan(0, $book->totals['wait']);
    }

    public function test_without_a_token_address_the_ledger_uses_time_only(): void
    {
        $this->seedLedger(25_000, null);
        config(['idtc.contracts.token' => null]);
        $book = app(GridLedger::class)->compute(CarbonImmutable::parse('2026-12-31'));

        $this->assertSame(1.0, $book->events[1]['kept']);
        $this->assertGreaterThan(0, $book->totals['ready']);
        $this->assertFalse($book->totals['hold']['enabled']);
    }
}

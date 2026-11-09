<?php

namespace Tests\Feature\Grid;

use App\Models\GridMember;
use App\Models\GridPlanVersion;
use App\Models\Purchase;
use App\Services\Grid\GridBook;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * กติกาคำนวณรางวัล Grid — ตรงกับต้นแบบ design/network (วง/ระดับ/โบนัส/ระยะถือ/Sprint/งบ)
 */
class GridLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** 1 USDT = 666,666.67 IDTC (อัตราใน config sale) */
    private const PER_USDT = 2e6 / 3;

    private int $seq = 0;

    private function w(string $name): string
    {
        return '0x'.str_pad(bin2hex($name), 40, '0', STR_PAD_LEFT);
    }

    private function member(string $who, ?string $ref, string $at = '2026-12-01 12:00:00'): void
    {
        GridMember::query()->create(['wallet' => $this->w($who), 'referrer' => $ref ? $this->w($ref) : null, 'bound_at' => $ref ? $at : null]);
    }

    private function buy(string $who, float $usdt, string $at = '2026-12-02 05:00:00'): void
    {
        $this->seq++;
        Purchase::query()->create([
            'contract' => '0x3333333333333333333333333333333333333333',
            'tx_hash' => '0x'.str_pad(dechex($this->seq), 64, '0', STR_PAD_LEFT),
            'log_index' => 0,
            'buyer' => $this->w($who),
            'pay_amount' => (string) (int) round($usdt * 1e6),
            'tokens_out' => '0',
            'pay_usdt' => $usdt,
            'tokens_idtc' => $usdt * self::PER_USDT,
            'block_number' => $this->seq,
            'block_time' => $at,
        ]);
    }

    private function book(string $now): GridBook
    {
        return app(GridLedger::class)->compute(CarbonImmutable::parse($now));
    }

    /** @return array<string, mixed> รางวัลจากการซื้อรายการที่ $i (เรียงตามเวลา) */
    private function rewardsOf(GridBook $book, int $i): array
    {
        $out = [];
        foreach ($book->events[$i]['rewards'] as $r) {
            $out[$r['kind'] === 'buyer' ? 'buyer' : 'r'.$r['ring']] = $r;
        }

        return $out;
    }

    /** A ← B ← C ← D แต่ละคนซื้อ 100 USDT */
    private function chain(): void
    {
        $this->member('A', null);
        $this->member('B', 'A');
        $this->member('C', 'B');
        $this->member('D', 'C');
        foreach (['A', 'B', 'C', 'D'] as $i => $who) {
            $this->buy($who, 100, '2026-12-02 0'.($i + 1).':00:00');
        }
    }

    public function test_rings_pay_by_rank_with_no_compression_and_wait_for_the_hold_period(): void
    {
        $this->chain();
        $book = $this->book('2026-12-10 00:00:00');
        $idtc = 100 * self::PER_USDT;

        // ทุกคนมีเพื่อนตรงคนเดียว → สปาร์ก (ปลดวง 1 เท่านั้น)
        foreach (['A', 'B', 'C', 'D'] as $who) {
            $this->assertSame(1, $book->node($this->w($who))['rank'], $who);
        }
        $r = $this->rewardsOf($book, 3); // การซื้อของ D
        $this->assertSame($this->w('C'), $r['r1']['to']);
        $this->assertEqualsWithDelta($idtc * 0.07, $r['r1']['amount'], 1e-6); // 5% + โบนัสสัปดาห์แรก 2%
        $this->assertSame(GridLedger::WAIT, $r['r1']['status']);              // ยังถือไม่ครบ 14 วัน
        $this->assertSame(GridLedger::RANK, $r['r2']['status']);               // B ยังไม่ถึงเซลล์ → คืนงบ ไม่ส่งต่อ
        $this->assertSame(GridLedger::RANK, $r['r3']['status']);
        $this->assertSame($this->w('D'), $r['buyer']['to']);
        $this->assertEqualsWithDelta($idtc * 0.01, $r['buyer']['amount'], 1e-6);

        $r = $this->rewardsOf($book, 0); // A ซื้อ ไม่มีสายบน
        $this->assertSame(GridLedger::NONE, $r['r1']['status']);
        $this->assertSame(GridLedger::NONE, $r['buyer']['status']);

        $later = $this->book('2026-12-17 00:00:00');
        $this->assertSame(GridLedger::READY, $this->rewardsOf($later, 3)['r1']['status']);
        $this->assertEqualsWithDelta($idtc * 0.07 + $idtc * 0.01, $later->node($this->w('C'))['ready'], 1e-3); // วง 1 จาก D + ส่วนผู้ซื้อของตัวเอง
    }

    public function test_two_direct_buyers_unlock_ring_two(): void
    {
        $this->chain();
        $this->member('C2', 'B');
        $this->buy('C2', 10, '2026-12-02 06:00:00');
        $book = $this->book('2026-12-20 00:00:00');

        $this->assertSame(2, $book->node($this->w('B'))['rank']);
        $r = $this->rewardsOf($book, 3);
        $this->assertSame($this->w('B'), $r['r2']['to']);
        $this->assertSame(GridLedger::READY, $r['r2']['status']);
        $this->assertEqualsWithDelta(100 * self::PER_USDT * 0.03, $r['r2']['amount'], 1e-6);
    }

    public function test_purchase_made_before_binding_earns_the_upline_nothing(): void
    {
        $this->member('A', null);
        $this->buy('A', 50);
        $this->buy('B', 50, '2026-12-02 05:00:00');
        $this->member('B', 'A', '2026-12-02 06:00:00'); // ผูกหลังซื้อ (เช่น ตัวดึงยอดช้า)
        $r = $this->rewardsOf($this->book('2026-12-20 00:00:00'), 1);
        $this->assertSame(GridLedger::NONE, $r['r1']['status']);
        $this->assertSame(GridLedger::NONE, $r['buyer']['status']);
    }

    public function test_launch_boost_steps_down_by_sale_week(): void
    {
        $this->member('A', null);
        $this->member('B', 'A');
        $this->buy('A', 100);
        $this->buy('B', 10, '2026-12-02 05:00:00');  // สัปดาห์ 1 → 7%
        $this->buy('B', 10, '2026-12-23 05:00:00');  // สัปดาห์ 4 → 6%
        $this->buy('B', 10, '2027-01-13 05:00:00');  // สัปดาห์ 7 → 5%
        $book = $this->book('2027-02-01 00:00:00');
        $this->assertEqualsWithDelta(0.07, $this->rewardsOf($book, 1)['r1']['rate'], 1e-12);
        $this->assertEqualsWithDelta(0.06, $this->rewardsOf($book, 2)['r1']['rate'], 1e-12);
        $this->assertEqualsWithDelta(0.05, $this->rewardsOf($book, 3)['r1']['rate'], 1e-12);
    }

    public function test_a_plan_version_never_rewrites_earlier_weeks(): void
    {
        $plan = GridPlan::defaults();
        $plan['levels'][0] = 0.04;
        GridPlanVersion::query()->create(['from_week' => 1, 'plan' => $plan, 'note' => 'test']);
        $this->member('A', null);
        $this->member('B', 'A');
        $this->buy('A', 100);
        $this->buy('B', 10, '2026-12-02 05:00:00'); // สัปดาห์ 1 ใช้แผนเดิม
        $this->buy('B', 10, '2026-12-09 05:00:00'); // สัปดาห์ 2 ใช้แผนใหม่
        $book = $this->book('2027-01-01 00:00:00');
        $this->assertEqualsWithDelta(0.07, $this->rewardsOf($book, 1)['r1']['rate'], 1e-12);
        $this->assertEqualsWithDelta(0.06, $this->rewardsOf($book, 2)['r1']['rate'], 1e-12);
    }

    public function test_cap_split_redistributes_the_excess(): void
    {
        $rows = GridLedger::capSplit(100, [
            ['score' => 80, 'share' => 0.0, 'capped' => false],
            ['score' => 10, 'share' => 0.0, 'capped' => false],
            ['score' => 10, 'share' => 0.0, 'capped' => false],
        ], 50);
        $this->assertEqualsWithDelta(50, $rows[0]['share'], 1e-9);
        $this->assertTrue($rows[0]['capped']);
        $this->assertEqualsWithDelta(25, $rows[1]['share'], 1e-9);
        $this->assertEqualsWithDelta(25, $rows[2]['share'], 1e-9);

        $alone = GridLedger::capSplit(100, [['score' => 1, 'share' => 0.0, 'capped' => false]], 20);
        $this->assertEqualsWithDelta(20, $alone[0]['share'], 1e-9); // ที่เหลือ 80 ไม่มีใครรับ → คืนงบ
    }

    public function test_sprint_pool_goes_to_grid_rank_capped_and_the_rest_returns(): void
    {
        $this->member('G', null);
        $this->buy('G', 10);
        foreach (['F1', 'F2', 'F3', 'F4'] as $f) {
            $this->member($f, 'G');
            $this->buy($f, 250, '2026-12-03 05:00:00');
        }
        $book = $this->book('2026-12-30 00:00:00');
        $this->assertSame(3, $book->node($this->w('G'))['rank']); // เพื่อนตรง 4 + ทีม 1,000 = กริด
        $s = $book->sprint[0];
        $pool = 1010 * self::PER_USDT * 0.02;
        $this->assertEqualsWithDelta($pool, $s['pool'], 1e-3);
        $this->assertEqualsWithDelta($pool * 0.2, $s['rows'][0]['share'], 1e-3);
        $this->assertSame(GridLedger::READY, $s['state']);
        $this->assertEqualsWithDelta($pool * 0.8, $book->totals['returned_sprint'], 1e-3);
    }

    public function test_held_wallet_holds_rewards_to_it_and_from_its_purchases(): void
    {
        $this->chain();
        GridMember::query()->where('wallet', $this->w('C'))->update(['held_at' => now(), 'held_reason' => 'test hold']);
        $book = $this->book('2026-12-20 00:00:00');
        $this->assertSame(GridLedger::HELD, $this->rewardsOf($book, 3)['r1']['status']); // รางวัลเข้า C
        $this->assertSame(GridLedger::HELD, $this->rewardsOf($book, 2)['r1']['status']); // จากการซื้อของ C ไปให้ B
        $this->assertSame(GridLedger::HELD, $this->rewardsOf($book, 2)['buyer']['status']);
        $this->assertGreaterThan(0, $book->totals['held']);
    }

    public function test_default_plan_is_valid_and_its_worst_case_fits_the_budget(): void
    {
        $plan = GridPlan::defaults();
        $this->assertSame([], GridPlan::errors($plan));
        $this->assertEqualsWithDelta(0.14, GridPlan::maxRate($plan), 1e-12);
        $this->assertLessThanOrEqual((float) $plan['budget'], GridPlan::saleCap() * GridPlan::maxRate($plan));

        $book = $this->book('2026-11-20 00:00:00');
        $this->assertSame(-1, $book->nowWeek);
        $this->assertLessThanOrEqual($book->totals['budget'], $book->totals['worst']);

        $bad = $plan;
        $bad['levels'] = [0.3, 0.4, 0.01];
        $bad['budget'] = '200000000000';
        $errors = GridPlan::errors($bad);
        $this->assertArrayHasKey('levels.0', $errors);
        $this->assertArrayHasKey('levels.1', $errors);
        $this->assertArrayHasKey('budget', $errors);
    }

    public function test_a_corrupt_referral_loop_does_not_hang_the_ledger(): void
    {
        GridMember::query()->insert([
            ['wallet' => $this->w('X'), 'referrer' => $this->w('Y'), 'bound_at' => '2026-12-01 00:00:00'],
            ['wallet' => $this->w('Y'), 'referrer' => $this->w('X'), 'bound_at' => '2026-12-01 00:00:00'],
        ]);
        $this->buy('X', 20);
        $book = $this->book('2026-12-20 00:00:00');
        $this->assertCount(2, $book->nodes);
        $this->assertTrue($book->node($this->w('X'))['referrer'] === null || $book->node($this->w('Y'))['referrer'] === null);
    }

    public function test_cached_book_survives_a_serializing_cache_store(): void
    {
        // production ใช้ cache ที่ serialize (database/file) และไม่ยอม unserialize object — ต้องแคชเป็น array
        config(['cache.default' => 'database']);
        $this->chain();
        $ledger = app(GridLedger::class);
        $first = $ledger->book(CarbonImmutable::parse('2026-12-20 00:00:00'));
        $again = $ledger->book(CarbonImmutable::parse('2026-12-20 00:00:00'));
        $this->assertInstanceOf(GridBook::class, $again);
        $this->assertEquals($first->totals, $again->totals);
        $this->assertSame($first->node($this->w('C'))['ready'], $again->node($this->w('C'))['ready']);
    }
}

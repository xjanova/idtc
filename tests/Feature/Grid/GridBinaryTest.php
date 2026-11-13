<?php

namespace Tests\Feature\Grid;

use App\Models\GridMember;
use App\Models\GridPlanVersion;
use App\Models\Purchase;
use App\Services\Grid\GridBinder;
use App\Services\Grid\GridBook;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridPlacer;
use App\Services\Grid\GridPlan;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * แผน Binary (สองขา) ของ IDTC Grid: วางตำแหน่งตอนผูกผู้แนะนำ · จับคู่ยอดขารายสัปดาห์ · ยกยอด · เพดาน · ไม่จ่ายเกินกอง
 */
class GridBinaryTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    /** 1 USDT = 666,666.67 IDTC (อัตราใน config sale) */
    private const P = 2e6 / 3;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        Carbon::setTestNow('2026-12-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function w(string $name): string
    {
        return '0x'.str_pad(bin2hex($name), 40, '0', STR_PAD_LEFT);
    }

    /** ผูกผู้แนะนำผ่าน GridBinder จริง (ได้ตำแหน่ง Binary ด้วย) */
    private function bind(string $who, string $ref, ?string $side = null): GridMember
    {
        $row = app(GridBinder::class)->bind($this->w($who), $this->w($ref), 'msg', 'sig', null, $side);
        $this->assertInstanceOf(GridMember::class, $row, is_string($row) ? $row : '');

        return $row->fresh();
    }

    private function slot(string $who): string
    {
        $m = GridMember::query()->where('wallet', $this->w($who))->firstOrFail();

        return $m->bin_leg.':'.$m->bin_parent;
    }

    private function at(string $leg, string $parent): string
    {
        return $leg.':'.$this->w($parent);
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
            'tokens_idtc' => $usdt * self::P,
            'block_number' => $this->seq,
            'block_time' => $at,
        ]);
    }

    /** เปิด Binary ตั้งแต่สัปดาห์แรก */
    private function binaryOn(array $binary = []): void
    {
        $plan = GridPlan::defaults();
        $plan['binary'] = $binary + ['pool' => 0.02, 'pair' => 0.10, 'cap' => 1.0, 'directs' => 1];
        GridPlanVersion::query()->create(['from_week' => 0, 'plan' => $plan, 'note' => 'binary on']);
        app(GridPlan::class)->forget();
    }

    private function book(string $now): GridBook
    {
        return app(GridLedger::class)->compute(CarbonImmutable::parse($now));
    }

    // ───────────── ตำแหน่ง ─────────────

    public function test_auto_placement_fills_the_weaker_leg_and_spills_down_the_outer_edge(): void
    {
        $this->bind('B', 'A');
        $this->assertSame($this->at('L', 'A'), $this->slot('B'));   // เท่ากัน = ซ้าย
        $this->bind('C', 'A');
        $this->assertSame($this->at('R', 'A'), $this->slot('C'));   // ขวาคนน้อยกว่า
        $this->bind('D', 'A');
        $this->assertSame($this->at('L', 'B'), $this->slot('D'));   // เท่ากันอีก = ซ้าย → ต่อท้ายขอบนอกของขาซ้าย
        $this->bind('E', 'A');
        $this->assertSame($this->at('R', 'C'), $this->slot('E'));   // ขวา 1 คน < ซ้าย 2 คน → ต่อท้ายขอบนอกของขาขวา
        $this->assertNotNull(GridMember::query()->where('wallet', $this->w('E'))->value('bin_placed_at'));
    }

    public function test_link_side_and_sponsor_preference_choose_the_leg(): void
    {
        $this->bind('B', 'A');
        $this->bind('C', 'A');
        $this->bind('D', 'A', 'R');                                  // ลิงก์ระบุขาขวา แม้ซ้ายจะคนเท่ากัน
        $this->assertSame($this->at('R', 'C'), $this->slot('D'));

        GridMember::query()->updateOrCreate(['wallet' => $this->w('A')], ['bin_side_pref' => 'R']);
        $this->bind('E', 'A');                                       // ไม่ระบุขา → ตามที่ผู้ชวนตั้งไว้ (ขวา) ไม่ใช่ขาที่คนน้อยกว่า
        $this->assertSame($this->at('R', 'D'), $this->slot('E'));
        $this->bind('F', 'A', 'l');                                  // ลิงก์ชนะค่าที่ตั้งไว้
        $this->assertSame($this->at('L', 'B'), $this->slot('F'));
        $this->assertSame('L', GridPlacer::side('LEFT'));
        $this->assertNull(GridPlacer::side('x'));
    }

    public function test_a_sponsor_who_binds_later_keeps_the_tree_below(): void
    {
        $this->bind('Y', 'X');                  // X ยังไม่มีผู้แนะนำ = บนสุดของผังตัวเอง
        $this->bind('X', 'A');                  // X ลงใต้ A พร้อมสายล่างเดิม
        $this->assertSame($this->at('L', 'A'), $this->slot('X'));
        $this->assertSame($this->at('L', 'X'), $this->slot('Y'));

        $book = $this->book('2026-12-03 00:00:00');
        $this->assertSame(2, $book->node($this->w('A'))['bin_nl']);
        $this->assertSame([$this->w('X'), $this->w('A')], $book->upline($this->w('Y'), 10, true));
    }

    public function test_members_bound_before_the_tree_existed_are_placed_in_binding_order(): void
    {
        foreach (['B' => '2026-12-01 01:00:00', 'C' => '2026-12-01 03:00:00', 'D' => '2026-12-01 02:00:00'] as $who => $at) {
            GridMember::query()->create(['wallet' => $this->w($who), 'referrer' => $this->w('A'), 'bound_at' => $at]);
        }
        $this->assertSame(3, app(GridPlacer::class)->missing());

        $this->artisan('idtc:grid-place')->assertExitCode(0);
        $this->assertSame(0, app(GridPlacer::class)->missing());
        $this->assertSame($this->at('L', 'A'), $this->slot('B'));   // ผูกก่อน
        $this->assertSame($this->at('R', 'A'), $this->slot('D'));   // ผูกคนที่สอง
        $this->assertSame($this->at('L', 'B'), $this->slot('C'));
        $this->assertSame('2026-12-01 03:00:00', GridMember::query()->where('wallet', $this->w('C'))->value('bin_placed_at')->format('Y-m-d H:i:s'));

        // ผูกคนใหม่: คนเก่าที่ค้างถูกวางก่อนเสมอ
        GridMember::query()->create(['wallet' => $this->w('E'), 'referrer' => $this->w('A'), 'bound_at' => '2026-12-01 04:00:00']);
        $this->bind('F', 'A');
        $this->assertSame($this->at('R', 'D'), $this->slot('E'));
        $this->assertSame($this->at('L', 'C'), $this->slot('F'));
        $this->artisan('idtc:grid-place')->assertExitCode(0);       // รันซ้ำ ไม่ย้ายใคร
        $this->assertSame($this->at('L', 'A'), $this->slot('B'));
    }

    public function test_one_slot_holds_one_wallet(): void
    {
        $this->bind('B', 'A');
        $this->expectException(UniqueConstraintViolationException::class);
        GridMember::query()->create(['wallet' => $this->w('Z'), 'referrer' => $this->w('A'), 'bin_parent' => $this->w('A'), 'bin_leg' => 'L']);
    }

    // ───────────── รางวัลจับคู่ ─────────────

    /** A บนสุด · B ขาซ้าย · C ขาขวา (ทั้งคู่ A ชวนเอง) */
    private function pair(): void
    {
        $this->bind('B', 'A');
        $this->bind('C', 'A');
    }

    public function test_binary_is_off_by_default_and_changes_nothing(): void
    {
        $this->pair();
        foreach (['A' => 100, 'B' => 300, 'C' => 100] as $who => $usdt) {
            $this->buy($who, $usdt);
        }
        $book = $this->book('2026-12-25 00:00:00');

        $this->assertSame([], $book->binary[0]['rows']);
        $this->assertSame(0.0, $book->binary[0]['pool']);
        $this->assertSame(0.0, $book->node($this->w('A'))['bin']);
        $this->assertSame(0.0, $book->node($this->w('A'))['bin_cl']);            // ปิดอยู่ = ไม่ยกยอด
        $this->assertSame(0.0, $book->totals['returned_binary']);
        $this->assertFalse($book->totals['binary_on']);
        $this->assertEqualsWithDelta(0.14, $book->totals['max_rate'], 1e-12);     // ตัวกันงบเดิมไม่ขยับ
        $this->assertEqualsWithDelta(300 * self::P, $book->node($this->w('A'))['bin_l'], 1e-3); // ยอดขายังแสดงให้เห็น
    }

    public function test_pairing_pays_the_weaker_leg_and_carries_the_rest(): void
    {
        $this->binaryOn();
        $this->pair();
        foreach (['A' => 100, 'B' => 300, 'C' => 100] as $who => $usdt) {
            $this->buy($who, $usdt);
        }

        $live = $this->book('2026-12-03 00:00:00');
        $a = $live->node($this->w('A'));
        $this->assertTrue($a['bin_q']);
        $this->assertSame([1, 1], [$a['bin_dl'], $a['bin_dr']]);
        $row = $live->binary[0]['rows'][0];
        $this->assertSame($this->w('A'), $row['wallet']);
        $this->assertEqualsWithDelta(100 * self::P, $row['matched'], 1e-3);       // ขาขวา (น้อยกว่า)
        $this->assertEqualsWithDelta(10 * self::P, $row['share'], 1e-3);          // 10% ของยอดจับคู่ = กองพอดี (2% × 500)
        $this->assertSame('live', $live->binary[0]['state']);
        $this->assertEqualsWithDelta(200 * self::P, $a['bin_cl'], 1e-3);          // ส่วนเกินขาซ้ายยกไป
        $this->assertSame(0.0, $a['bin_cr']);
        $this->assertEqualsWithDelta(0.16, $live->totals['max_rate'], 1e-12);     // กองนับในอัตรารวมที่ใช้ตรวจงบ
        $ringsOnly = 100 * self::P * 0.07 + 300 * self::P * 0.07;                 // วง 1 จาก B และ C (สัปดาห์แรก 5% + 2%)
        $this->assertEqualsWithDelta($ringsOnly + 10 * self::P, $a['wait'], 1e-2); // ยังไม่ปิดสัปดาห์/ยังไม่ครบระยะถือ
        $this->assertSame(0.0, $a['ready']);

        // สัปดาห์ถัดไป C ซื้ออีก 150 → จับคู่กับยอดยกมาของขาซ้าย · กองสัปดาห์นี้เล็ก (2% × 150) → ถึงเพดานกอง
        $this->buy('C', 150, '2026-12-09 05:00:00');
        $next = $this->book('2026-12-10 00:00:00');
        $row = $next->binary[1]['rows'][0];
        $this->assertEqualsWithDelta(150 * self::P, $row['matched'], 1e-3);
        $this->assertEqualsWithDelta(15 * self::P, $row['nominal'], 1e-3);
        $this->assertEqualsWithDelta(3 * self::P, $row['share'], 1e-3);
        $this->assertTrue($row['capped']);
        $this->assertEqualsWithDelta(50 * self::P, $next->node($this->w('A'))['bin_cl'], 1e-3);
        $this->assertEqualsWithDelta(13 * self::P, $next->node($this->w('A'))['bin'], 1e-3);

        // ปิดสัปดาห์แรก + ครบระยะถือ 14 วัน → รางวัลจับคู่ของสัปดาห์แรกเข้ายอดครบระยะ (เข้ารอบจ่ายได้)
        $done = $this->book('2026-12-23 00:00:00');
        $this->assertSame(GridLedger::READY, $done->binary[0]['state']);
        $this->assertSame(GridLedger::WAIT, $done->binary[1]['state']);
        $this->assertEqualsWithDelta($ringsOnly + 10 * self::P, $done->node($this->w('A'))['ready'], 1e-2);
        $this->assertEqualsWithDelta(0.0, $done->totals['returned_binary'], 1e-3);
    }

    public function test_total_pairing_never_exceeds_the_weekly_pool(): void
    {
        $this->binaryOn();
        $this->pair();
        $this->bind('D', 'B');
        $this->bind('E', 'B');
        foreach (['A', 'B', 'C', 'D', 'E'] as $who) {
            $this->buy($who, 100);
        }
        $book = $this->book('2026-12-03 00:00:00');
        $w = $book->binary[0];

        $this->assertEqualsWithDelta(10 * self::P, $w['pool'], 1e-3);             // 2% × 500
        $this->assertEqualsWithDelta(20 * self::P, $w['nominal'], 1e-3);          // A และ B จับคู่ได้คนละ 100 → 10 + 10
        $this->assertEqualsWithDelta(0.5, $w['factor'], 1e-9);                    // เกินกอง → ย่อเท่ากัน
        $this->assertEqualsWithDelta(10 * self::P, $w['given'], 1e-3);            // จ่ายรวมพอดีกอง
        $this->assertCount(2, $w['rows']);
        foreach ($w['rows'] as $row) {
            $this->assertEqualsWithDelta(5 * self::P, $row['share'], 1e-3);
        }
        $this->assertEqualsWithDelta(10 * self::P, $book->totals['binary_paid'], 1e-3);
    }

    public function test_per_member_cap_returns_the_unpaid_pool_to_the_budget(): void
    {
        $this->binaryOn(['cap' => 0.2]);
        $this->pair();
        $this->bind('D', 'B');
        $this->bind('E', 'B');
        foreach (['A', 'B', 'C', 'D', 'E'] as $who) {
            $this->buy($who, 100);
        }
        $book = $this->book('2026-12-03 00:00:00');
        $w = $book->binary[0];

        foreach ($w['rows'] as $row) {
            $this->assertEqualsWithDelta(2 * self::P, $row['share'], 1e-3);       // 20% ของกอง 10
            $this->assertTrue($row['capped']);
        }
        $this->assertEqualsWithDelta(1.0, $w['factor'], 1e-9);
        $this->assertEqualsWithDelta(6 * self::P, $book->totals['returned_binary'], 1e-3);
        $this->assertEqualsWithDelta(6 * self::P, $book->weeks[0]['binary_pool'] - $book->weeks[0]['binary_paid'], 1e-3);
    }

    public function test_pairing_needs_rank_and_an_active_direct_on_each_leg(): void
    {
        $this->binaryOn();
        $this->pair();
        $this->bind('D', 'B');                  // D เป็นเพื่อนตรงของ B ไม่ใช่ของ A
        $this->bind('E', 'B');
        $this->buy('A', 100);
        $this->buy('B', 100);
        $this->buy('D', 100);
        $this->buy('E', 100);                   // ขาขวาของ A (C) ยังไม่ซื้อ → A ไม่มีเพื่อนตรงที่ซื้อแล้วในขาขวา และไม่มียอดขาขวา

        $book = $this->book('2026-12-03 00:00:00');
        $this->assertFalse($book->node($this->w('A'))['bin_q']);
        $this->assertSame([$this->w('B')], array_column($book->binary[0]['rows'], 'wallet'));

        // C ซื้อแล้ว แต่ A เองไม่ได้ซื้อ (ไม่ถึงสปาร์ก) → ยังไม่ได้
        Purchase::query()->where('buyer', $this->w('A'))->delete();
        $this->buy('C', 100);
        $book = $this->book('2026-12-03 00:00:00');
        $this->assertSame(0, $book->node($this->w('A'))['rank']);
        $this->assertFalse($book->node($this->w('A'))['bin_q']);

        // ตั้งให้ไม่ต้องมีเพื่อนตรงต่อขา → ยังต้องเป็นสปาร์กอยู่ดี
        GridPlanVersion::query()->delete();
        $this->binaryOn(['directs' => 0]);
        $this->assertFalse($this->book('2026-12-03 00:00:00')->node($this->w('A'))['bin_q']);
    }

    public function test_qualifying_later_never_reopens_a_closed_week(): void
    {
        $this->binaryOn();
        $this->pair();
        $this->buy('B', 300);
        $this->buy('C', 100);                               // สัปดาห์แรก: A ยังไม่ซื้อ = ยังไม่ถึงสปาร์ก

        $before = $this->book('2026-12-03 00:00:00');
        $this->assertSame([], $before->binary[0]['rows']);
        $this->assertFalse($before->node($this->w('A'))['bin_q']);

        // A ซื้อในสัปดาห์ที่สอง → ผ่านเงื่อนไขตั้งแต่สัปดาห์ที่สองเท่านั้น
        $this->buy('A', 100, '2026-12-09 05:00:00');
        $this->buy('B', 50, '2026-12-09 06:00:00');
        $this->buy('C', 20, '2026-12-09 07:00:00');
        $after = $this->book('2026-12-10 00:00:00');

        $this->assertTrue($after->node($this->w('A'))['bin_q']);
        $this->assertSame([], $after->binary[0]['rows']);                         // สัปดาห์ที่ปิดแล้วไม่ถูกแบ่งใหม่
        $this->assertEqualsWithDelta(8 * self::P, $after->binary[0]['pool'], 1e-3);
        $this->assertEqualsWithDelta(8 * self::P, $after->totals['returned_binary'] - ($after->binary[1]['pool'] - $after->binary[1]['given']), 1e-3);
        $row = $after->binary[1]['rows'][0];
        $this->assertEqualsWithDelta(20 * self::P, $row['matched'], 1e-3);        // ยอดสัปดาห์แรกไม่ยกมา (ตอนนั้นยังไม่ผ่านเงื่อนไข)
        $this->assertEqualsWithDelta(30 * self::P, $after->node($this->w('A'))['bin_cl'], 1e-3);
    }

    public function test_held_wallets_do_not_feed_or_collect_pairing(): void
    {
        $this->binaryOn();
        $this->pair();
        foreach (['A' => 100, 'B' => 300, 'C' => 100] as $who => $usdt) {
            $this->buy($who, $usdt);
        }
        // พักผู้รับ: รางวัลจับคู่ไปอยู่กอง "พักไว้"
        GridMember::query()->updateOrCreate(['wallet' => $this->w('A')], ['held_at' => now(), 'held_reason' => 'ตรวจสอบ']);
        $book = $this->book('2026-12-23 00:00:00');
        $this->assertEqualsWithDelta(10 * self::P, $book->node($this->w('A'))['bin'], 1e-3);
        $this->assertSame(0.0, $book->node($this->w('A'))['ready']);
        $this->assertGreaterThanOrEqual(10 * self::P - 1e-3, $book->node($this->w('A'))['held_amt']);

        // พักผู้ซื้อในขา: ยอดของกระเป๋านั้นไม่นับเป็นยอดขา
        GridMember::query()->where('wallet', $this->w('A'))->update(['held_at' => null]);
        GridMember::query()->where('wallet', $this->w('C'))->update(['held_at' => now(), 'held_reason' => 'ตรวจสอบ']);
        $book = $this->book('2026-12-23 00:00:00');
        $this->assertSame(0.0, $book->node($this->w('A'))['bin_r']);
        $this->assertSame([], $book->binary[0]['rows']);
    }

    public function test_pairing_follows_the_plan_of_each_sale_week(): void
    {
        // เปิด Binary ตั้งแต่สัปดาห์ขายที่ 2 — ยอดของสัปดาห์แรกไม่นับและไม่ยกยอด
        $plan = GridPlan::defaults();
        $plan['binary'] = ['pool' => 0.02, 'pair' => 0.10, 'cap' => 1.0, 'directs' => 1];
        GridPlanVersion::query()->create(['from_week' => 1, 'plan' => $plan, 'note' => 'binary from week 2']);
        app(GridPlan::class)->forget();
        $this->pair();
        foreach (['A' => 100, 'B' => 300, 'C' => 100] as $who => $usdt) {
            $this->buy($who, $usdt);
        }
        $this->buy('B', 30, '2026-12-09 05:00:00');
        $this->buy('C', 60, '2026-12-09 06:00:00');

        $book = $this->book('2026-12-10 00:00:00');
        $this->assertSame([], $book->binary[0]['rows']);
        $row = $book->binary[1]['rows'][0];
        $this->assertEqualsWithDelta(30 * self::P, $row['matched'], 1e-3);        // เฉพาะยอดสัปดาห์ที่สอง
        $this->assertEqualsWithDelta(1.8 * self::P, $row['share'], 1e-3);         // min(10% × 30, กอง 2% × 90)
        $this->assertEqualsWithDelta(30 * self::P, $book->node($this->w('A'))['bin_cr'], 1e-3);
    }

    // ───────────── งบ / หลังบ้าน / หน้าสมาชิก ─────────────

    public function test_plan_form_saves_binary_and_the_budget_guard_counts_the_pool(): void
    {
        $form = fn (array $over) => $over + [
            'l1' => '5', 'l2' => '3', 'l3' => '1', 'buyer' => '1', 'sprint' => '2', 'sprint_cap' => '20', 'instant' => '50',
            'hold_days' => '14', 'budget' => '15000000000',
            'boost' => [['from' => '1', 'to' => '3', 'add' => '2'], ['from' => '4', 'to' => '6', 'add' => '1']],
            'spark_own' => '10', 'cell_d' => '2', 'grid_d' => '4', 'grid_t' => '1000', 'core_d' => '10', 'core_t' => '10000',
            'bin_pool' => '0.5', 'bin_pair' => '10', 'bin_cap' => '20', 'bin_directs' => '1',
            'note' => 'เปิดกอง Binary ทดสอบ',
        ];
        $this->loginAsOwner();

        $this->get('/admin/grid/plan')->assertOk()->assertSee('name="bin_pool"', false)->assertSee('ปิดอยู่ (กอง 0%)');

        // 14% + 2% = 16% × 100,000 ล้าน เกินงบ 15,000 ล้าน
        $this->post('/admin/grid/plan', $form(['bin_pool' => '2']))->assertSessionHasErrors('budget');
        $this->post('/admin/grid/plan', $form(['bin_pool' => '11']))->assertSessionHasErrors('binary.pool');
        $this->post('/admin/grid/plan', $form(['bin_pair' => '0']))->assertSessionHasErrors('binary.pair');
        $this->post('/admin/grid/plan', $form(['bin_cap' => '0']))->assertSessionHasErrors('binary.cap');
        $this->post('/admin/grid/plan', $form(['bin_directs' => 'x']))->assertSessionHasErrors('binary.directs');
        $this->assertSame(0, GridPlanVersion::query()->count());

        $this->post('/admin/grid/plan', $form([]))->assertRedirect('/admin/grid/plan')->assertSessionHas('status');
        $saved = GridPlanVersion::query()->sole()->plan['binary'];
        $this->assertEqualsWithDelta(0.005, $saved['pool'], 1e-12);
        $this->assertEqualsWithDelta(0.1, $saved['pair'], 1e-12);
        $this->assertEqualsWithDelta(0.2, $saved['cap'], 1e-12);
        $this->assertSame(1, $saved['directs']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'grid.plan']);
    }

    public function test_admin_pages_show_the_binary_tree(): void
    {
        $this->pair();
        $this->bind('D', 'A');
        $this->loginAsOwner();

        $this->get('/admin/grid/members/'.$this->w('A'))->assertOk()
            ->assertSee('data-testid="member-binary"', false)->assertSee('ขาซ้าย')->assertSee('tree=binary', false);
        $this->get('/admin/grid/members/'.$this->w('D'))->assertOk()->assertSee('ขาซ้าย ของ');
        $this->get('/admin/grid/map?tree=binary')->assertOk()->assertSee('ผัง Binary')->assertSee('ดูผังสายแนะนำ');
        $this->get('/admin/grid/map?tree=binary&root='.$this->w('B'))->assertOk();
        $this->get('/admin/grid')->assertOk()->assertSee('Binary จ่ายไม่ถึงกอง');

        // ผัง Binary: D อยู่ใต้ B (ไม่ใช่ใต้ A ผู้แนะนำ)
        $slice = app(GridLedger::class)->book(fresh: true)->slice($this->w('A'), 6, 2500, true);
        $parents = array_column($slice['nodes'], 1, 0);
        $this->assertSame(3, $slice['total']);
        $this->assertNull($parents[$this->w('B')]);
        $this->assertSame($this->w('B'), $parents[$this->w('D')]);
    }

    public function test_member_api_exposes_legs_links_and_the_side_preference(): void
    {
        app(Settings::class)->put('grid.enabled', true);
        $this->binaryOn();
        $this->pair();
        foreach (['A' => 100, 'B' => 300, 'C' => 100] as $who => $usdt) {
            $this->buy($who, $usdt);
        }
        Carbon::setTestNow('2026-12-03 00:00:00');
        $me = $this->w('A');

        $this->postJson('/grid/side', ['side' => 'R'])->assertStatus(401);
        $this->withSession(['grid' => ['address' => $me]]);
        $b = $this->getJson('/grid/me')->assertOk()->json('binary');
        $this->assertTrue($b['on']);
        $this->assertTrue($b['qualified']);
        $this->assertSame(1, $b['left']['count']);
        $this->assertEqualsWithDelta(300 * self::P, $b['left']['idtc'], 1e-3);
        $this->assertEqualsWithDelta(200 * self::P, $b['left']['carry'], 1e-3);
        $this->assertEqualsWithDelta(10 * self::P, $b['week']['mine']['share'], 1e-3);
        $this->assertStringEndsWith('/l', $b['links']['left']);
        $this->assertStringEndsWith('/r', $b['links']['right']);
        $this->assertNull($b['pref']);

        $this->postJson('/grid/side', ['side' => 'R'])->assertOk()->assertJson(['pref' => 'R']);
        $this->assertSame('R', $this->getJson('/grid/me')->json('binary.pref'));
        $this->bind('D', 'A');
        $this->assertSame($this->at('R', 'C'), $this->slot('D'));
        $this->postJson('/grid/side', ['side' => ''])->assertOk()->assertJson(['pref' => null]);

        $this->get('/grid')->assertOk()->assertSee('data-testid="grid-binary"', false);
        $this->get('/en/grid')->assertOk()->assertSee('Binary tree (two legs)');
    }

    public function test_leg_links_carry_the_side_into_the_bind(): void
    {
        app(Settings::class)->put('grid.enabled', true);
        $a = $this->w('A');

        $this->get('/r/'.$a.'/r')->assertRedirect()->assertCookie('idtc_side', 'r')->assertCookie('idtc_ref', $a);
        $this->get('/r/'.$a)->assertRedirect()->assertCookieExpired('idtc_side');   // ลิงก์ปกติล้างขาที่ค้างจากลิงก์ก่อน
        $this->get('/r/'.$a.'/x')->assertNotFound();

        // หน้า /grid บอกผู้ถูกชวนว่าจะลงขาไหน (เมื่อแผนเปิด Binary) — จาก query หรือคุกกี้ของลิงก์
        $this->get('/grid?ref='.$a.'&side=l')->assertOk()->assertDontSee('คำชวนนี้ให้คุณลงขาซ้ายของผู้ชวน');
        $this->binaryOn();
        $this->get('/grid?ref='.$a.'&side=l')->assertOk()->assertSee('คำชวนนี้ให้คุณลงขาซ้ายของผู้ชวน');
        $this->withCookie('idtc_side', 'r')->get('/grid?ref='.$a)->assertOk()->assertSee('คำชวนนี้ให้คุณลงขาขวาของผู้ชวน');
    }
}

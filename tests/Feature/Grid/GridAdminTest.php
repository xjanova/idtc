<?php

namespace Tests\Feature\Grid;

use App\Models\AuditLog;
use App\Models\GridMember;
use App\Models\GridPlanVersion;
use App\Models\IndexerState;
use App\Models\Purchase;
use App\Services\Chain\Address;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridPlan;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * หลังบ้าน IDTC Grid — สิทธิ์ เปิด/ปิด แผน (ตรวจงบ มีผลสัปดาห์ถัดไป) พักรางวัล ส่งออก
 */
class GridAdminTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const ADMIN = '0x2222222222222222222222222222222222222222';

    private const VIEWER = '0x3333333333333333333333333333333333333333';

    private const A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        Carbon::setTestNow('2026-12-16 05:00:00'); // สัปดาห์ขายที่ 3
        GridMember::query()->create(['wallet' => self::B, 'referrer' => self::A, 'bound_at' => '2026-12-02 00:00:00']);
        foreach ([[self::A, 100, 1], [self::B, 50, 2]] as [$who, $usdt, $i]) {
            Purchase::query()->create([
                'contract' => '0x4444444444444444444444444444444444444444', 'tx_hash' => '0x'.str_repeat((string) $i, 64), 'log_index' => 0,
                'buyer' => $who, 'pay_amount' => (string) ($usdt * 1000000), 'tokens_out' => '0', 'pay_usdt' => $usdt,
                'tokens_idtc' => $usdt * 2e6 / 3, 'block_number' => $i, 'block_time' => '2026-12-02 0'.$i.':00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> ฟอร์มแผนเริ่มต้น (หน่วย % แบบหน้าจอ) */
    private function form(array $over = []): array
    {
        return $over + [
            'l1' => '5', 'l2' => '3', 'l3' => '1', 'buyer' => '1', 'sprint' => '2', 'sprint_cap' => '20', 'instant' => '50',
            'hold_days' => '14', 'budget' => '15000000000',
            'boost' => [['from' => '1', 'to' => '3', 'add' => '2'], ['from' => '4', 'to' => '6', 'add' => '1'], ['from' => '', 'to' => '', 'add' => '']],
            'spark_own' => '10', 'cell_d' => '2', 'grid_d' => '4', 'grid_t' => '1000', 'core_d' => '10', 'core_t' => '10000',
            'note' => 'ทดสอบแก้แผนรางวัล',
        ];
    }

    public function test_every_grid_page_renders_for_a_viewer(): void
    {
        $this->loginAs(self::VIEWER, 'viewer');
        $this->get('/admin/grid')->assertOk()->assertSee('ปิดอยู่')->assertSee('งบรางวัล');
        $this->get('/admin/grid/members')->assertOk()->assertSee(Address::checksum(self::A))->assertSee(Address::checksum(self::B));
        $this->get('/admin/grid/members?q=0xaa')->assertOk()->assertSee(Address::checksum(self::A))->assertDontSee(Address::checksum(self::B));
        $this->get('/admin/grid/members?q=zz')->assertOk()->assertSee('ค้นด้วยที่อยู่กระเป๋า');
        $this->get('/admin/grid/members/'.self::B)->assertOk()->assertSee('สายบน');
        $this->get('/admin/grid/members/0x'.str_repeat('c', 40))->assertNotFound();
        $this->get('/admin/grid/ledger')->assertOk()->assertSee('วง 1');
        $this->get('/admin/grid/ledger?status=ready')->assertOk();
        $this->get('/admin/grid/plan')->assertOk()->assertSee('แผนเริ่มต้น')->assertDontSee('บันทึกแผน</button>', false);
        $this->post('/admin/grid/plan', $this->form())->assertForbidden();
        $this->post('/admin/grid/members/'.self::B.'/hold', ['reason' => 'ทดสอบพักรางวัล'])->assertForbidden();
        $this->post('/admin/grid/toggle', ['enabled' => 1])->assertForbidden();
    }

    public function test_overview_says_whether_holding_is_checked_on_chain(): void
    {
        $this->loginAs(self::VIEWER, 'viewer');
        config(['idtc.contracts.token' => null]);
        $this->get('/admin/grid')->assertOk()->assertSee('ยังไม่ตรวจการถือจริง');

        config(['idtc.contracts.token' => '0x7777777777777777777777777777777777777777']);
        $this->get('/admin/grid')->assertOk()->assertSee('รอดึงการโอนเหรียญ');

        IndexerState::query()->create(['key' => 'transfers', 'contract' => '0x7777777777777777777777777777777777777777', 'last_block' => 9, 'last_block_time' => '2026-12-16 04:00:00']);
        // B โอนออกมากกว่าที่เห็นว่าเข้า → ประวัติไม่ครบ ไม่ตัดรางวัล แต่แจ้งแอดมิน
        DB::table('token_transfers')->insert(['contract' => '0x7777777777777777777777777777777777777777', 'tx_hash' => '0x'.str_repeat('9', 64), 'log_index' => 0,
            'from' => self::B, 'to' => self::A, 'value' => '0', 'amount' => 1e12, 'block_number' => 5, 'block_time' => '2026-12-03 00:00:00']);
        $this->get('/admin/grid')->assertOk()->assertSee('ตรวจการถือจากการโอนบนเชน')->assertSee('ประวัติการโอนก่อนบล็อกเริ่มดึงไม่ครบ');
        $this->get('/admin/grid/members/'.self::B)->assertOk()->assertSee('ประวัติการโอนไม่ครบ');
    }

    public function test_only_the_owner_turns_the_grid_on_and_off(): void
    {
        $this->loginAs(self::ADMIN, 'admin')->post('/admin/grid/toggle', ['enabled' => 1])->assertForbidden();
        $this->loginAsOwner()->post('/admin/grid/toggle', ['enabled' => 1])->assertRedirect();
        $this->assertTrue(app(GridPlan::class)->enabled());
        $this->assertSame(1, AuditLog::query()->where('action', 'grid.enabled')->count());
        $this->post('/admin/grid/toggle', ['enabled' => 0]);
        app(Settings::class)->flush();
        $this->assertFalse(app(GridPlan::class)->enabled());
    }

    public function test_plan_change_takes_effect_next_week_and_is_audited(): void
    {
        $this->loginAs(self::ADMIN, 'admin')->post('/admin/grid/plan', $this->form(['l1' => '4']))->assertRedirect('/admin/grid/plan')->assertSessionHas('status');
        $v = GridPlanVersion::query()->sole();
        $this->assertSame(3, $v->from_week); // ตอนนี้สัปดาห์ 3 (index 2) → มีผลสัปดาห์ 4
        $this->assertEqualsWithDelta(0.04, $v->plan['levels'][0], 1e-12);
        $this->assertSame([['from' => 0, 'to' => 3, 'add' => 0.02], ['from' => 3, 'to' => 6, 'add' => 0.01]], $v->plan['boost']);
        $this->assertSame(1, AuditLog::query()->where('action', 'grid.plan')->count());

        // แก้อีกครั้งก่อนถึงสัปดาห์ที่มีผล → แก้เวอร์ชันที่รอ ไม่ซ้อน
        $this->post('/admin/grid/plan', $this->form(['l1' => '4.5']));
        $this->assertSame(1, GridPlanVersion::query()->count());
        $this->assertEqualsWithDelta(0.045, GridPlanVersion::query()->sole()->plan['levels'][0], 1e-12);
        // อัตราที่ใช้อยู่ตอนนี้ยังเป็นของเดิม
        $this->assertEqualsWithDelta(0.05, app(GridPlan::class)->current()['levels'][0], 1e-12);
    }

    public function test_plan_that_could_overpay_the_budget_is_refused(): void
    {
        $this->loginAs(self::ADMIN, 'admin');
        $this->post('/admin/grid/plan', $this->form(['budget' => '10000000000']))->assertSessionHasErrors('budget');
        $this->post('/admin/grid/plan', $this->form(['l1' => '30']))->assertSessionHasErrors('levels.0');
        $this->post('/admin/grid/plan', $this->form(['l2' => '6']))->assertSessionHasErrors('levels.1');
        $this->post('/admin/grid/plan', $this->form(['note' => 'สั้น']))->assertSessionHasErrors('note');
        $this->post('/admin/grid/plan', $this->form(['budget' => '200000000000']))->assertSessionHasErrors('budget');
        $this->post('/admin/grid/plan', $this->form(['boost' => [['from' => '3', 'to' => '1', 'add' => '1']]]))->assertSessionHasErrors('boost.0');
        $this->post('/admin/grid/plan', $this->form(['grid_d' => '1']))->assertSessionHasErrors('rank');
        $this->assertSame(0, GridPlanVersion::query()->count());
        $this->post('/admin/grid/plan', $this->form())->assertSessionHas('status', 'แผนไม่ได้เปลี่ยน');
    }

    public function test_hold_and_release_a_wallet(): void
    {
        $this->loginAs(self::ADMIN, 'admin');
        $this->post('/admin/grid/members/'.self::A.'/hold', ['reason' => 'สั้น'])->assertSessionHasErrors('reason');
        $this->post('/admin/grid/members/'.self::A.'/hold', ['reason' => 'ค่าแก๊สจากแหล่งเดียวกัน 6 ใบ'])->assertSessionHas('status');
        $row = GridMember::query()->where('wallet', self::A)->sole();
        $this->assertNotNull($row->held_at);
        $this->assertSame(self::ADMIN, $row->held_by);
        $this->get('/admin/grid/members/'.self::A)->assertSee('พักรางวัลอยู่');
        $this->get('/admin/grid/ledger?status=held')->assertSee(Address::checksum(self::A));

        $this->post('/admin/grid/members/'.self::A.'/unhold')->assertSessionHas('status');
        $this->assertNull($row->fresh()->held_at);
        $this->assertSame(['grid.hold', 'grid.unhold'], AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_ledger_exports_csv(): void
    {
        $this->loginAs(self::VIEWER, 'viewer');
        $csv = $this->get('/admin/grid/ledger/export')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFtime_utc,week,tx_hash", $csv);
        $this->assertStringContainsString(self::B, $csv);
        $this->assertStringContainsString(',ring,1,'.self::A.',0.070000,', $csv);
    }

    public function test_network_map_slices_the_line_and_folds_what_it_cuts(): void
    {
        // สาย A → B → C → D
        $c = '0xcccccccccccccccccccccccccccccccccccccccc';
        $d = '0xdddddddddddddddddddddddddddddddddddddddd';
        GridMember::query()->create(['wallet' => $c, 'referrer' => self::B, 'bound_at' => '2026-12-03 00:00:00']);
        GridMember::query()->create(['wallet' => $d, 'referrer' => $c, 'bound_at' => '2026-12-04 00:00:00']);
        $book = app(GridLedger::class)->book(null, true);

        $all = $book->slice(null, 6);
        $this->assertSame(4, $all['total']);
        $this->assertSame([self::A, null, 1], array_slice($all['nodes'][0], 0, 3));
        $this->assertSame([self::B, self::A, 2], array_slice($all['nodes'][1], 0, 3));

        // ลึก 2 วงจาก A: เห็น B, C · D พับไว้ที่ C
        $two = $book->slice(self::A, 2);
        $this->assertSame([self::B, $c], array_column($two['nodes'], 0));
        $this->assertSame(1, $two['nodes'][1][8]);
        $this->assertSame(3, $two['total']);

        // จำกัดจำนวน: เห็นแค่ B · ที่เหลือพับไว้ที่ B
        $cap = $book->slice(self::A, 6, 1);
        $this->assertSame([self::B], array_column($cap['nodes'], 0));
        $this->assertSame(2, $cap['nodes'][0][8]);

        $this->loginAs(self::VIEWER, 'viewer');
        $this->get('/admin/grid/map')->assertOk()->assertSee('ทั้งระบบ')->assertSee('data-map', false)->assertSee('แสดง 4 จาก 4 กระเป๋า');
        $this->get('/admin/grid/map?root='.self::B.'&depth=1')->assertOk()->assertSee('แสดง 1 จาก 2 กระเป๋า');
        $this->get('/admin/grid/map?root='.$d)->assertOk()->assertSee('กระเป๋านี้ยังไม่มีสายล่าง');
        $this->get('/admin/grid/map?root=0x'.str_repeat('e', 40))->assertNotFound();
        $this->get('/admin/grid/map?root=nope')->assertNotFound();
    }
}

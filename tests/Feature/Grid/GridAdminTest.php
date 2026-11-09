<?php

namespace Tests\Feature\Grid;

use App\Models\AuditLog;
use App\Models\GridMember;
use App\Models\GridPlanVersion;
use App\Models\Purchase;
use App\Services\Chain\Address;
use App\Services\Grid\GridPlan;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
}

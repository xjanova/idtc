<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\Admin\AdminPermissions;
use App\Services\Admin\AdminRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** สิทธิ์รายเมนู: owner ติ๊ก ดู/แก้ ต่อส่วน · route gate · ซ่อนเมนู · ชุดสำเร็จรูป viewer/admin · กันล็อก owner คนสุดท้าย */
class AdminPermissionsTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private string $staff = '0x6666666666666666666666666666666666666666';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        $this->fakeRpc(fn (string $method) => match ($method) {
            'eth_blockNumber' => '0x100',
            'eth_chainId' => '0x10c1',
            default => ['__error' => 'not faked'],
        });
    }

    public function test_normalize_keeps_known_sections_and_downgrades_read_only_edit(): void
    {
        $this->assertSame(
            ['sales' => 'view', 'presale' => 'edit', 'audit' => 'view'],
            AdminPermissions::normalize(['presale' => ['edit' => '1'], 'sales' => ['view' => '1'], 'audit' => 'edit', 'admins' => 'edit', 'nope' => 'view', 'vesting' => 'god']),
        );
        $this->assertSame([], AdminPermissions::normalize('edit'));
        $this->assertSame('viewer', AdminPermissions::roleFor(['sales' => 'view']));
        $this->assertSame('admin', AdminPermissions::roleFor(['sales' => 'edit']));
    }

    public function test_custom_permissions_gate_routes_per_section(): void
    {
        $this->loginAs($this->staff, 'admin', ['sales' => 'view', 'presale' => 'edit', 'grid_rounds' => 'view']);

        $this->get('/admin/sales')->assertOk();
        $this->get('/admin/sales/export')->assertOk();
        $this->post('/admin/sales/sync')->assertForbidden()->assertSee('ดูได้อย่างเดียว');
        $this->get('/admin/presale')->assertOk();
        $this->get('/admin/settings')->assertForbidden()->assertSee('ยังไม่มีสิทธิ์เปิดส่วน');
        $this->post('/admin/settings/indexer', ['start_block' => '1'])->assertForbidden();
        $this->get('/admin/audit')->assertForbidden();
        $this->get('/admin/vesting')->assertForbidden();
        $this->get('/admin/grid/members')->assertForbidden();
        $this->get('/admin/grid/rounds')->assertOk();
        $this->post('/admin/grid/rounds')->assertForbidden();
        $this->get('/admin/admins')->assertForbidden();

        // คำสั่งบนเชน: สิทธิ์ตามส่วนของคำสั่ง
        $hash = '0x'.str_repeat('b', 64);
        $this->postJson('/admin/tx', ['action' => 'vesting.release', 'tx_hash' => $hash])->assertForbidden();
        $this->postJson('/admin/tx', ['action' => 'rewards.pause', 'tx_hash' => $hash])->assertForbidden();
        $this->postJson('/admin/tx', ['action' => 'presale.pause', 'tx_hash' => $hash])->assertStatus(422); // ผ่านด่านสิทธิ์ — ติดแค่สัญญายังไม่ตั้งที่อยู่
    }

    public function test_tx_check_needs_edit_on_the_action_section(): void
    {
        $log = AuditLog::query()->create(['action' => 'rewards.addRound', 'tx_hash' => '0x'.str_repeat('c', 64), 'tx_status' => AuditLog::PENDING]);
        $this->loginAs($this->staff, 'admin', ['grid_rounds' => 'view']);
        $this->postJson("/admin/tx/{$log->id}/check")->assertForbidden();
    }

    public function test_nav_hides_sections_the_admin_cannot_view(): void
    {
        $this->loginAs($this->staff, 'admin', ['sales' => 'view', 'grid_map' => 'view']);

        $res = $this->get('/admin/sales')->assertOk();
        $res->assertSee('admin/sales"', false);
        $res->assertSee('admin/grid/map"', false);
        $res->assertSee('IDTC Grid');
        $res->assertDontSee('admin/settings"', false);
        $res->assertDontSee('admin/presale"', false);
        $res->assertDontSee('admin/audit"', false);
        $res->assertDontSee('admin/admins"', false);
        $res->assertDontSee('ควบคุมเหรียญ'); // หัวข้อกลุ่มที่ไม่เหลือเมนูถูกซ่อน
        $res->assertSee('สิทธิ์กำหนดเอง');
    }

    public function test_viewer_preset_sees_every_page_but_no_edit_buttons(): void
    {
        $this->loginAs($this->staff, 'viewer');
        $this->get('/admin/sales')->assertOk()->assertDontSee('ซิงก์ตอนนี้');
        $this->get('/admin/settings')->assertOk()->assertSee('admin/audit"', false);
    }

    public function test_dashboard_redirects_to_first_viewable_section(): void
    {
        $this->loginAs($this->staff, 'admin', ['grid_ledger' => 'view']);
        $this->get('/admin')->assertRedirect('/admin/grid/ledger');
    }

    public function test_admin_with_no_permissions_gets_forbidden_but_can_manage_password(): void
    {
        $this->loginAs($this->staff, 'viewer', []);
        $this->get('/admin')->assertForbidden()->assertSee('ยังไม่มีส่วนไหนที่คุณเปิดได้');
        $this->get('/admin/password')->assertOk();
    }

    public function test_owner_always_has_everything_even_with_stale_ticks(): void
    {
        config(['idtc.admin_wallets' => []]);
        $this->loginAs($this->staff, 'owner', ['sales' => 'view']);
        $this->get('/admin/settings')->assertOk();
        $this->get('/admin/admins')->assertOk();
    }

    public function test_owner_saves_ticked_permissions(): void
    {
        $row = Admin::query()->create(['address' => $this->staff, 'role' => 'viewer']);
        $this->loginAsOwner();

        $this->get('/admin/admins')->assertOk()->assertSee('ตั้งสิทธิ์')->assertSee('แก้ไข/สั่งงาน');

        $this->patch("/admin/admins/{$row->id}", [
            'role' => 'admin', 'perm_form' => '1',
            'perm' => ['sales' => ['view' => '1', 'edit' => '1'], 'audit' => ['view' => '1', 'edit' => '1'], 'presale' => ['edit' => '1']],
        ])->assertSessionHas('status');
        $row->refresh();
        $this->assertSame('admin', $row->role);
        $this->assertSame(['sales' => 'edit', 'presale' => 'edit', 'audit' => 'view'], $row->permissions);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.permissions']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.role']);

        // ติ๊กดูอย่างเดียว → role กลายเป็น viewer
        $this->patch("/admin/admins/{$row->id}", ['role' => 'admin', 'perm_form' => '1', 'perm' => ['sales' => ['view' => '1']]]);
        $this->assertSame('viewer', $row->fresh()->role);
        $this->assertSame(['sales' => 'view'], $row->fresh()->permissions);

        // ติ๊กครบตรงชุด admin = เก็บเป็นชุด (null) ไม่ใช่ค่ากำหนดเอง
        $all = [];
        foreach (AdminPermissions::full() as $s => $level) {
            $all[$s] = ['view' => '1', 'edit' => '1'];
        }
        $this->patch("/admin/admins/{$row->id}", ['role' => 'admin', 'perm_form' => '1', 'perm' => $all]);
        $this->assertSame('admin', $row->fresh()->role);
        $this->assertNull($row->fresh()->permissions);

        // เลือกเจ้าของ = ทุกอย่าง ไม่เก็บค่าติ๊ก
        $this->patch("/admin/admins/{$row->id}", ['role' => 'owner', 'perm_form' => '1', 'perm' => ['sales' => ['view' => '1']]]);
        $this->assertSame('owner', $row->fresh()->role);
        $this->assertNull($row->fresh()->permissions);
    }

    public function test_owner_adds_admin_with_ticked_permissions(): void
    {
        $this->loginAsOwner();
        $this->post('/admin/admins', ['address' => $this->staff, 'label' => 'บัญชี', 'role' => 'admin', 'perm_form' => '1', 'perm' => ['sales' => ['view' => '1']]])
            ->assertSessionHas('status');
        $row = Admin::query()->where('address', $this->staff)->sole();
        $this->assertSame('viewer', $row->role);
        $this->assertSame(['sales' => 'view'], $row->permissions);

        $this->flushSession();
        $this->loginAs($this->staff)->get('/admin/sales')->assertOk();
        $this->get('/admin/presale')->assertForbidden();
    }

    public function test_permission_change_applies_on_the_next_request(): void
    {
        $row = Admin::query()->create(['address' => $this->staff, 'role' => 'admin']);
        $this->loginAs($this->staff)->get('/admin/settings')->assertOk();

        $row->update(['role' => 'viewer', 'permissions' => ['sales' => 'view']]);
        $this->get('/admin/settings')->assertForbidden();
    }

    public function test_last_owner_cannot_be_demoted_or_removed(): void
    {
        config(['idtc.admin_wallets' => []]);
        $me = Admin::query()->create(['address' => $this->staff, 'role' => 'owner']);
        $this->loginAs($this->staff);

        $this->patch("/admin/admins/{$me->id}", ['role' => 'admin', 'perm_form' => '1', 'perm' => []])->assertSessionHas('error');
        $this->delete("/admin/admins/{$me->id}")->assertSessionHas('error');
        $this->assertSame('owner', $me->fresh()->role);

        // owner คนที่สองถูกลดได้ แต่พอเหลือคนเดียว (ตัวเอง) ก็ลดไม่ได้อีก
        $other = Admin::query()->create(['address' => '0x7777777777777777777777777777777777777777', 'role' => 'owner']);
        $this->patch("/admin/admins/{$other->id}", ['role' => 'viewer'])->assertSessionHas('status');
        $this->assertSame(1, app(AdminRegistry::class)->ownerCount());
    }

    public function test_last_owner_guard_counts_owners_not_actors(): void
    {
        // ป้องกันซ้อน: ถ้าเหลือ owner คนเดียวในระบบ ใครก็ลด/ลบไม่ได้ แม้จะไม่ใช่ตัวเอง
        config(['idtc.admin_wallets' => []]);
        $only = Admin::query()->create(['address' => '0x7777777777777777777777777777777777777777', 'role' => 'owner']);
        $registry = new class extends AdminRegistry
        {
            public function roleFor(?string $address): ?string
            {
                return strtolower((string) $address) === '0x6666666666666666666666666666666666666666' ? 'owner' : parent::roleFor($address);
            }
        };
        $this->app->instance(AdminRegistry::class, $registry);
        $this->loginAs($this->staff);

        $this->patch("/admin/admins/{$only->id}", ['role' => 'viewer'])->assertSessionHas('error');
        $this->delete("/admin/admins/{$only->id}")->assertSessionHas('error');
        $this->assertSame('owner', $only->fresh()->role);
    }
}

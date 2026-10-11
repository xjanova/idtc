<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class AdminRolesTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private string $viewer = '0x5555555555555555555555555555555555555555';

    private string $admin = '0x6666666666666666666666666666666666666666';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        // ยังไม่ deploy อะไร — RPC ตอบแค่บล็อกล่าสุด/chain id
        $this->fakeRpc(fn (string $method) => match ($method) {
            'eth_blockNumber' => '0x100',
            'eth_chainId' => '0x10c1',
            default => ['__error' => 'not faked'],
        });
    }

    /** @return array<int, string> */
    private function pages(): array
    {
        return ['/admin', '/admin/sales', '/admin/presale', '/admin/vesting', '/admin/pools', '/admin/wallets', '/admin/content', '/admin/settings', '/admin/audit'];
    }

    public function test_every_page_renders_for_viewer_in_empty_state(): void
    {
        foreach ($this->pages() as $page) {
            $this->loginAs($this->viewer, 'viewer')->get($page)->assertOk()->assertSee('หลังบ้าน IDTC', false);
        }
    }

    public function test_viewer_cannot_change_settings_or_send_actions(): void
    {
        $this->loginAs($this->viewer, 'viewer');

        $this->post('/admin/content', ['section' => 'announcement', 'announcement' => 'hi'])->assertForbidden();
        $this->post('/admin/settings/contracts', ['key' => 'presale', 'address' => ''])->assertForbidden();
        $this->post('/admin/settings/indexer', ['start_block' => '1'])->assertForbidden();
        $this->post('/admin/wallets', ['label' => ['a'], 'address' => [$this->admin]])->assertForbidden();
        $this->post('/admin/sales/sync')->assertForbidden();
        $this->postJson('/admin/tx', ['action' => 'presale.pause', 'tx_hash' => '0x'.str_repeat('a', 64)])->assertForbidden();
        $this->get('/admin/admins')->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'announcement']);
    }

    public function test_admin_can_change_settings_but_not_manage_admins(): void
    {
        $this->loginAs($this->admin, 'admin');

        $this->post('/admin/content', ['section' => 'announcement', 'announcement' => 'เปิดขายรอบแรก'])->assertRedirect();
        $this->assertDatabaseHas('settings', ['key' => 'announcement']);

        $this->get('/admin/admins')->assertForbidden();
        $this->post('/admin/admins', ['address' => $this->viewer, 'role' => 'owner'])->assertForbidden();
        $target = Admin::query()->create(['address' => $this->viewer, 'role' => 'viewer']);
        $this->patch("/admin/admins/{$target->id}", ['role' => 'owner'])->assertForbidden();
        $this->delete("/admin/admins/{$target->id}")->assertForbidden();
        $this->assertSame('viewer', $target->fresh()->role);
    }

    public function test_owner_can_add_change_and_remove_admins(): void
    {
        $this->loginAsOwner();

        $this->get('/admin/admins')->assertOk()->assertSee('IDTC_ADMIN_WALLETS');
        $this->post('/admin/admins', ['address' => $this->viewer, 'role' => 'viewer', 'label' => 'ทีมบัญชี'])->assertRedirect()->assertSessionHas('status');
        $row = Admin::query()->where('address', $this->viewer)->firstOrFail();

        $this->patch("/admin/admins/{$row->id}", ['role' => 'admin'])->assertSessionHas('status');
        $this->assertSame('admin', $row->fresh()->role);

        $this->delete("/admin/admins/{$row->id}")->assertSessionHas('status');
        $this->assertDatabaseMissing('admins', ['address' => $this->viewer]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.add']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.role']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.remove']);
    }

    public function test_owner_cannot_add_invalid_or_env_addresses(): void
    {
        $this->loginAsOwner();

        $this->post('/admin/admins', ['address' => '0x123', 'role' => 'viewer'])->assertSessionHas('error');
        $this->post('/admin/admins', ['address' => $this->ownerAddress, 'role' => 'viewer'])->assertSessionHas('error');
        $this->post('/admin/admins', ['address' => $this->viewer, 'role' => 'god'])->assertSessionHas('error');
        // checksum ผิด (ตัวพิมพ์ผสมแบบสุ่ม)
        $this->post('/admin/admins', ['address' => '0x399679922b673FEE28c507B07FF12e17aBFD78A8', 'role' => 'viewer'])->assertSessionHas('error');
        $this->assertSame(0, Admin::query()->count());
    }

    public function test_admin_errors_are_thai_and_hide_internals(): void
    {
        config(['app.debug' => false]);
        $this->get('/admin/nope')->assertNotFound()->assertSee('ไม่พบหน้านี้')->assertDontSee('Symfony');
        $this->loginAsOwner()->delete('/admin/admins/999999')->assertNotFound()->assertSee('ไม่พบหน้านี้');
        // หน้าเว็บสาธารณะไม่ถูกแตะ
        $this->get('/no-such-public-page')->assertNotFound()->assertDontSee('กลับหน้าหลังบ้าน');
    }

    public function test_db_owner_cannot_demote_or_remove_self(): void
    {
        config(['idtc.admin_wallets' => []]);
        $me = Admin::query()->create(['address' => $this->admin, 'role' => 'owner']);
        $this->loginAs($this->admin);

        $this->patch("/admin/admins/{$me->id}", ['role' => 'viewer'])->assertSessionHas('error');
        $this->delete("/admin/admins/{$me->id}")->assertSessionHas('error');
        $this->assertSame('owner', $me->fresh()->role);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminCredential;
use App\Models\AuditLog;
use App\Services\Admin\AdminPasswords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\SignsAdminMessages;
use Tests\TestCase;

/** เข้าระบบ = ลายเซ็นกระเป๋า + รหัสผ่าน · ตั้ง/เปลี่ยน/รีเซ็ตรหัสผ่าน · ล็อกเมื่อใส่ผิดบ่อย */
class AdminPasswordTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase, SignsAdminMessages;

    private string $pw = 'signer-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpSignedVectors();
        config(['idtc.admin_wallets' => [$this->f['address']]]); // ผู้เซ็นเป็น owner จาก .env
        $this->fakeRpc(fn (string $method) => $method === 'eth_blockNumber' ? '0x2a' : ['__error' => 'not faked']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function password(string $password, ?string $confirm = null)
    {
        return $this->postJson('/admin/auth/password', array_filter(['password' => $password, 'password_confirmation' => $confirm], fn ($v) => $v !== null));
    }

    private function assertPasswordNeverStored(string $password): void
    {
        $dump = json_encode(AuditLog::query()->get()->toArray()).json_encode(AdminCredential::query()->get()->toArray());
        $this->assertStringNotContainsString($password, $dump);
        $this->assertStringNotContainsString('password_hash', json_encode(AdminCredential::query()->get()->toArray()));
    }

    public function test_env_owner_without_password_must_set_one_before_entering(): void
    {
        $this->verify($this->prime())->assertOk()->assertJson(['next' => 'set_password']);
        $this->assertNull(session('admin.address'));
        $this->get('/admin')->assertRedirect('/admin/login'); // ยังไม่ผ่านขั้นรหัสผ่าน = ยังไม่ได้เข้า

        $this->password('short', 'short')->assertStatus(422)->assertJson(['code' => 'invalid']);
        $this->password($this->pw, 'mismatch-'.$this->pw)->assertStatus(422)->assertJson(['code' => 'invalid']);
        $this->password($this->pw, $this->pw)->assertOk()->assertJson(['redirect' => route('admin.dashboard')]);

        $cred = AdminCredential::query()->where('address', $this->signer())->sole();
        $this->assertTrue(Hash::check($this->pw, $cred->password_hash));
        $this->assertSame($this->signer(), session('admin.address'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_set']);
        $this->get('/admin')->assertOk();
        $this->assertPasswordNeverStored($this->pw);
    }

    public function test_existing_owner_session_without_password_is_sent_to_set_one(): void
    {
        // session ก่อนมีระบบรหัสผ่าน (ไม่มี pw_v) — owner ต้องกลับไปตั้งรหัสผ่าน
        $this->withSession(['admin' => ['address' => $this->signer(), 'login_at' => time()]])
            ->get('/admin')->assertRedirect('/admin/login')->assertSessionHas('error');
        $this->assertNull(session('admin.address'));
    }

    public function test_owner_with_password_must_enter_it(): void
    {
        app(AdminPasswords::class)->set($this->signer(), $this->pw);

        $this->verify($this->prime())->assertOk()->assertJson(['next' => 'password']);
        $this->password('wrong-password-1')->assertStatus(422)->assertJson(['code' => 'password', 'left' => 4]);
        $this->assertNull(session('admin.address'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_failed']);

        $this->password($this->pw)->assertOk();
        $this->assertSame($this->signer(), session('admin.address'));
        $this->get('/admin')->assertOk();
        $this->assertPasswordNeverStored($this->pw);
        $this->assertPasswordNeverStored('wrong-password-1');
    }

    public function test_password_step_needs_a_fresh_signature(): void
    {
        app(AdminPasswords::class)->set($this->signer(), $this->pw);

        $this->password($this->pw)->assertStatus(422)->assertJson(['code' => 'expired']); // ไม่ได้เซ็นมาก่อน

        $this->verify($this->prime())->assertOk();
        Carbon::setTestNow('2026-10-11 05:07:00'); // เกิน 5 นาที
        $this->password($this->pw)->assertStatus(422)->assertJson(['code' => 'expired']);
        $this->assertNull(session('admin.address'));
    }

    public function test_wrong_passwords_lock_the_wallet_for_15_minutes(): void
    {
        app(AdminPasswords::class)->set($this->signer(), $this->pw);
        $this->verify($this->prime())->assertOk();

        for ($i = 4; $i >= 1; $i--) {
            $this->password('nope-nope-'.$i)->assertStatus(422)->assertJson(['left' => $i]);
        }
        $this->password('nope-nope-0')->assertStatus(429)->assertJson(['code' => 'locked']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_locked']);

        // รหัสถูกก็เข้าไม่ได้ระหว่างล็อก — ล็อกผูกกับกระเป๋า เซ็นใหม่ก็ยังล็อก
        $this->password($this->pw)->assertStatus(429);
        $this->travel(61)->seconds(); // พ้นเพดานต่อ IP ของ throttle:admin-auth
        $this->verify($this->prime('valid2'))->assertOk()->assertJson(['next' => 'password']);
        $this->assertGreaterThan(0, $this->postJson('/admin/auth/password', ['password' => $this->pw])->assertStatus(429)->json('locked'));
        $this->assertNull(session('admin.address'));

        $this->assertGreaterThan(0, app(AdminPasswords::class)->lockedSeconds($this->signer()));
        $this->travel(16)->minutes();
        $this->assertSame(0, app(AdminPasswords::class)->lockedSeconds($this->signer()));
    }

    public function test_password_endpoint_shares_the_per_ip_auth_throttle(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->password('x')->assertStatus(422);
        }
        $this->password('x')->assertStatus(429);
    }

    public function test_db_admin_with_password_must_enter_it_and_without_one_signs_in_directly(): void
    {
        config(['idtc.admin_wallets' => [$this->ownerAddress]]);
        Admin::query()->create(['address' => $this->signer(), 'role' => 'admin']);

        $this->verify($this->prime())->assertOk()->assertJsonStructure(['redirect']); // ไม่มีรหัสผ่าน = เข้าได้เลย
        $this->post('/admin/logout');

        app(AdminPasswords::class)->set($this->signer(), $this->pw);
        $this->verify($this->prime('valid2'))->assertOk()->assertJson(['next' => 'password']);
        $this->password($this->pw)->assertOk();
    }

    public function test_owner_reset_forces_a_new_password_and_ends_the_session(): void
    {
        $target = '0x7777777777777777777777777777777777777777';
        Admin::query()->create(['address' => $target, 'role' => 'admin']);
        app(AdminPasswords::class)->set($target, 'target-pass-123');
        $this->loginAs($target)->get('/admin')->assertOk();

        $this->flushSession();
        $this->loginAs($this->signer())->post('/admin/admins/password-reset', ['address' => $target])->assertSessionHas('status');
        $cred = AdminCredential::query()->where('address', $target)->sole();
        $this->assertTrue($cred->must_change);
        $this->assertNull($cred->password_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.password_reset']);

        // session เดิมของคนที่ถูกรีเซ็ต (pw_v เก่า) หลุดทันที
        $this->flushSession();
        $this->withSession(['admin' => ['address' => $target, 'login_at' => time(), 'pw_v' => 1]])
            ->get('/admin')->assertRedirect('/admin/login');
        $this->assertSame(AdminPasswords::STEP_SET, app(AdminPasswords::class)->stepFor($target, 'admin'));
    }

    public function test_owner_cannot_reset_own_or_unknown_password(): void
    {
        $this->loginAs($this->signer());
        $this->post('/admin/admins/password-reset', ['address' => $this->signer()])->assertSessionHas('error');
        $this->post('/admin/admins/password-reset', ['address' => '0x8888888888888888888888888888888888888888'])->assertSessionHas('error');
        $this->assertFalse(AdminCredential::query()->where('must_change', true)->exists());
    }

    public function test_non_owner_cannot_reset_passwords(): void
    {
        $admin = '0x6666666666666666666666666666666666666666';
        app(AdminPasswords::class)->set($this->signer(), $this->pw);
        $this->loginAs($admin, 'admin')->post('/admin/admins/password-reset', ['address' => $this->signer()])->assertForbidden();
        $this->assertSame(AdminPasswords::STEP_ENTER, app(AdminPasswords::class)->stepFor($this->signer(), 'owner'));
    }

    public function test_change_password_page(): void
    {
        $this->loginAs($this->signer()); // ตั้งรหัสผ่าน testPassword ให้แล้ว
        $old = $this->testPassword;
        $this->get('/admin/password')->assertOk()->assertSee('name="current_password"', false)->assertDontSee($old);

        $this->post('/admin/password', ['current_password' => 'not-it-at-all', 'password' => 'new-pass-2026!', 'password_confirmation' => 'new-pass-2026!'])
            ->assertSessionHas('error');
        $this->post('/admin/password', ['current_password' => $old, 'password' => $old, 'password_confirmation' => $old])->assertSessionHas('error');
        $this->post('/admin/password', ['current_password' => $old, 'password' => 'new-pass-2026!', 'password_confirmation' => 'new-pass-2026?'])->assertSessionHas('error');

        $this->post('/admin/password', ['current_password' => $old, 'password' => 'new-pass-2026!', 'password_confirmation' => 'new-pass-2026!'])
            ->assertRedirect('/admin/password')->assertSessionHas('status');
        $this->assertTrue(app(AdminPasswords::class)->check($this->signer(), 'new-pass-2026!'));
        $this->get('/admin')->assertOk(); // session นี้ไปต่อได้
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);
        $this->assertPasswordNeverStored('new-pass-2026!');

        // เครื่องอื่นที่ยังถือ version เก่าหลุด
        $this->flushSession();
        $this->withSession(['admin' => ['address' => $this->signer(), 'login_at' => time(), 'pw_v' => 1]])->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_without_password_can_opt_in_from_the_password_page(): void
    {
        $admin = '0x6666666666666666666666666666666666666666';
        $this->loginAs($admin, 'viewer');
        $this->get('/admin/password')->assertOk()->assertDontSee('name="current_password"', false);
        $this->post('/admin/password', ['password' => 'viewer-pass-99', 'password_confirmation' => 'viewer-pass-99'])->assertSessionHas('status');
        $this->get('/admin')->assertOk();
        $this->assertSame(AdminPasswords::STEP_ENTER, app(AdminPasswords::class)->stepFor($admin, 'viewer'));
    }

    public function test_console_reset_recovers_a_sole_owner(): void
    {
        app(AdminPasswords::class)->set($this->signer(), $this->pw);
        $this->artisan('idtc:admin-password-reset', ['address' => $this->f['address']])->assertExitCode(0);
        $this->assertSame(AdminPasswords::STEP_SET, app(AdminPasswords::class)->stepFor($this->signer(), 'owner'));
        $this->artisan('idtc:admin-password-reset', ['address' => '0x9999999999999999999999999999999999999999'])->assertExitCode(1);

        $this->verify($this->prime())->assertOk()->assertJson(['next' => 'set_password']);
    }

    public function test_removing_and_readding_an_admin_drops_the_old_password(): void
    {
        $addr = '0x5555555555555555555555555555555555555555';
        $row = Admin::query()->create(['address' => $addr, 'role' => 'viewer']);
        app(AdminPasswords::class)->set($addr, 'old-viewer-pass');

        $this->loginAs($this->signer())->delete("/admin/admins/{$row->id}")->assertSessionHas('status');
        $this->assertNull(app(AdminPasswords::class)->find($addr));
    }
}

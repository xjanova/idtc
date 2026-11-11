<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\SetupController;
use App\Models\Admin;
use App\Models\AdminCredential;
use App\Models\AuditLog;
use App\Services\Admin\AdminRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\SignsAdminMessages;
use Tests\TestCase;

/** ตั้งค่าครั้งแรก: ยังไม่มีผู้ดูแลเลย → คนแรกที่เซ็น + ตั้งรหัสผ่าน เป็น owner (กันแข่งกันด้วย lock + transaction) */
class AdminSetupTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase, SignsAdminMessages;

    private string $pw = 'first-owner-pass';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpSignedVectors();
        config(['idtc.admin_wallets' => []]);
        $this->fakeRpc(fn (string $method) => $method === 'eth_blockNumber' ? '0x2a' : ['__error' => 'not faked']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runSetup(string $case = 'valid', array $extra = [])
    {
        $c = $this->prime($case);

        return $this->postJson('/admin/setup', $extra + [
            'message' => $c['message'], 'signature' => $c['signature'],
            'password' => $this->pw, 'password_confirmation' => $this->pw, 'ack' => true,
        ]);
    }

    public function test_login_page_sends_first_visitor_to_setup_when_no_admin_exists(): void
    {
        $this->get('/admin/login')->assertRedirect('/admin/setup');
        $this->get('/admin/setup')->assertOk()->assertSee('ตั้งค่าหลังบ้านครั้งแรก')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_first_visitor_becomes_owner_with_password(): void
    {
        $res = $this->runSetup()->assertCreated();

        $this->assertSame(route('admin.dashboard'), $res->json('redirect'));
        $owner = Admin::query()->sole();
        $this->assertSame($this->signer(), $owner->address);
        $this->assertSame('owner', $owner->role);
        $cred = AdminCredential::query()->where('address', $this->signer())->sole();
        $this->assertTrue(Hash::check($this->pw, $cred->password_hash));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.setup', 'actor' => $this->signer(), 'role' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor' => $this->signer()]);
        $this->assertStringNotContainsString($this->pw, (string) json_encode(AuditLog::query()->get()->toArray()));

        $this->assertSame($this->signer(), session('admin.address'));
        $this->get('/admin')->assertOk()->assertSee('ภาพรวม');
    }

    public function test_setup_routes_return_404_once_any_admin_exists(): void
    {
        $this->runSetup()->assertCreated();
        $this->post('/admin/logout');

        $this->get('/admin/setup')->assertNotFound();
        $this->runSetup('valid2')->assertNotFound();
        $this->assertSame(1, Admin::query()->count());
        $this->get('/admin/login')->assertOk()->assertSee('เข้าสู่หลังบ้าน');
    }

    public function test_setup_is_closed_when_env_owner_exists(): void
    {
        config(['idtc.admin_wallets' => [$this->ownerAddress]]);
        $this->get('/admin/setup')->assertNotFound();
        $this->runSetup()->assertNotFound();
        $this->assertSame(0, Admin::query()->count());
    }

    public function test_setup_is_closed_when_a_db_admin_exists(): void
    {
        Admin::query()->create(['address' => '0x2222222222222222222222222222222222222222', 'role' => 'viewer']);
        $this->get('/admin/setup')->assertNotFound();
        $this->runSetup()->assertNotFound();
        $this->assertSame(1, Admin::query()->count());
    }

    public function test_concurrent_setup_waiting_on_the_lock_is_refused(): void
    {
        // อีกคำขอถือ lock อยู่ (กำลังตั้งค่า) — คำขอนี้ต้องไม่สร้าง owner คนที่สอง
        $held = Cache::lock(SetupController::LOCK, 30);
        $this->assertTrue($held->get());

        $this->runSetup()->assertStatus(409)->assertJson(['code' => 'busy']);
        $this->assertSame(0, Admin::query()->count());
        $held->release();
    }

    public function test_recheck_inside_the_lock_stops_a_second_owner(): void
    {
        // แข่งกัน: ตอนตรวจครั้งแรกยังไม่มีใคร แต่พอได้ lock คู่แข่งตั้งเสร็จไปแล้ว → ต้องไม่สร้างซ้ำ
        $this->app->instance(AdminRegistry::class, new class extends AdminRegistry
        {
            public int $calls = 0;

            public function hasAnyAdmin(): bool
            {
                return ++$this->calls > 1;
            }
        });

        $this->runSetup()->assertNotFound();
        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(0, AdminCredential::query()->count());
        $this->assertNull(session('admin.address'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admins.setup_denied']);
    }

    public function test_weak_password_is_rejected_before_the_nonce_is_spent(): void
    {
        $c = $this->prime();
        $this->postJson('/admin/setup', ['message' => $c['message'], 'signature' => $c['signature'], 'password' => 'short', 'password_confirmation' => 'short', 'ack' => true])
            ->assertStatus(422)->assertJson(['code' => 'invalid']);
        $this->postJson('/admin/setup', ['message' => $c['message'], 'signature' => $c['signature'], 'password' => $this->pw, 'password_confirmation' => 'something-else', 'ack' => true])
            ->assertStatus(422)->assertJson(['code' => 'invalid']);
        $this->postJson('/admin/setup', ['message' => $c['message'], 'signature' => $c['signature'], 'password' => str_repeat('ก', 25), 'password_confirmation' => str_repeat('ก', 25), 'ack' => true])
            ->assertStatus(422)->assertJson(['code' => 'invalid']); // 75 ไบต์ เกิน bcrypt 72

        $this->assertNotNull(Cache::get('idtc:siwe:nonce:'.$c['nonce']));
        $this->assertSame(0, Admin::query()->count());
        // nonce เดิมยังใช้ได้
        $this->postJson('/admin/setup', ['message' => $c['message'], 'signature' => $c['signature'], 'password' => $this->pw, 'password_confirmation' => $this->pw, 'ack' => true])->assertCreated();
    }

    public function test_setup_requires_ack_and_a_valid_signature(): void
    {
        $this->runSetup('valid', ['ack' => false])->assertStatus(422)->assertJson(['code' => 'ack']);

        $c = $this->prime('valid');
        $other = $this->f['cases']['valid2'];
        $this->postJson('/admin/setup', ['message' => $c['message'], 'signature' => $other['signature'], 'password' => $this->pw, 'password_confirmation' => $this->pw, 'ack' => true])
            ->assertStatus(422)->assertJson(['code' => 'signature']);

        $this->runSetup('wrong_domain')->assertStatus(422)->assertJson(['code' => 'domain']);
        $this->assertSame(0, Admin::query()->count());
    }

    public function test_setup_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/admin/setup', ['password' => 'x'])->assertStatus(422);
        }
        $this->runSetup()->assertStatus(429);
        $this->assertSame(0, Admin::query()->count());
    }
}

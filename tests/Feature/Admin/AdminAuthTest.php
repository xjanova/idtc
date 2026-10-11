<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\Auth\SiweMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    /** @var array<string, mixed> เวกเตอร์จาก ethers (คีย์สุ่มทิ้ง ใช้ทดสอบเท่านั้น) */
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // เวกเตอร์ถูกเซ็นสำหรับโดเมน localhost — ไม่ขึ้นกับ APP_URL ใน .env ของเครื่องที่รันเทสต์
        URL::forceRootUrl('http://localhost');
        $this->f = json_decode((string) file_get_contents(base_path('tests/Fixtures/ethers-vectors.json')), true);
        config(['idtc.admin_wallets' => [$this->f['address']]]);
        Carbon::setTestNow('2026-10-11 05:01:00');
        $this->fakeRpc(fn (string $method) => $method === 'eth_blockNumber' ? '0x2a' : ['__error' => 'not faked']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** จำลองว่า nonce ของเคสนี้ถูกออกให้ที่อยู่นี้แล้ว (เหมือนเรียก /admin/auth/nonce) */
    private function prime(string $case, ?string $for = null): array
    {
        $c = $this->f['cases'][$case];
        Cache::put('idtc:siwe:nonce:'.$c['nonce'], ['address' => strtolower($for ?? $this->f['address'])], 300);

        return $c;
    }

    private function verify(array $case)
    {
        return $this->postJson('/admin/auth/verify', ['message' => $case['message'], 'signature' => $case['signature']]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('เข้าสู่หลังบ้าน');
    }

    public function test_nonce_endpoint_issues_eip4361_message(): void
    {
        $res = $this->postJson('/admin/auth/nonce', ['address' => strtolower($this->f['address'])])->assertOk();

        $msg = SiweMessage::parse($res->json('message'));
        $this->assertNotNull($msg);
        $this->assertSame('localhost', $msg->domain);
        $this->assertSame($this->f['address'], $msg->address); // checksum EIP-55
        $this->assertSame(4289, $msg->chainId);
        $this->assertSame('เข้าสู่หลังบ้าน IDTC', $msg->statement);
        $this->assertSame('http://localhost/admin', $msg->uri);
        $this->assertSame($res->json('nonce'), $msg->nonce);
        $this->assertNotNull(Cache::get('idtc:siwe:nonce:'.$msg->nonce));
    }

    public function test_nonce_endpoint_rejects_bad_address(): void
    {
        $this->postJson('/admin/auth/nonce', ['address' => '0x123'])->assertStatus(422);
        $this->postJson('/admin/auth/nonce', [])->assertStatus(422);
    }

    public function test_valid_signature_from_owner_logs_in(): void
    {
        $this->verify($this->prime('valid'))
            ->assertOk()
            ->assertJson(['redirect' => route('admin.dashboard')]);

        $this->assertSame(strtolower($this->f['address']), session('admin.address'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor' => strtolower($this->f['address']), 'role' => 'owner']);
        $this->get('/admin')->assertOk()->assertSee('ภาพรวม');
    }

    public function test_nonce_is_single_use(): void
    {
        $case = $this->prime('valid');
        $this->verify($case)->assertOk();

        $this->post('/admin/logout');
        $this->verify($case)->assertStatus(422)->assertJson(['code' => 'nonce']);
    }

    public function test_unknown_or_expired_nonce_is_rejected(): void
    {
        $case = $this->f['cases']['valid']; // ไม่เคยออก nonce นี้
        $this->verify($case)->assertStatus(422)->assertJson(['code' => 'nonce']);

        $this->prime('valid');
        Carbon::setTestNow('2026-10-11 05:07:00'); // nonce อายุ 5 นาที
        $this->verify($case)->assertStatus(422)->assertJson(['code' => 'nonce']);
        $this->assertNull(session('admin.address'));
    }

    public function test_stale_message_is_rejected_even_with_fresh_nonce(): void
    {
        Carbon::setTestNow('2026-10-11 05:30:00');
        $this->verify($this->prime('valid'))->assertStatus(422)->assertJson(['code' => 'expired']);
    }

    public function test_wrong_domain_is_rejected(): void
    {
        $this->verify($this->prime('wrong_domain'))->assertStatus(422)->assertJson(['code' => 'domain']);
        $this->assertNull(session('admin.address'));
    }

    public function test_wrong_chain_is_rejected(): void
    {
        $this->verify($this->prime('wrong_chain'))->assertStatus(422)->assertJson(['code' => 'chain']);
    }

    public function test_nonce_is_bound_to_the_requesting_address(): void
    {
        $this->verify($this->prime('valid', '0x2222222222222222222222222222222222222222'))
            ->assertStatus(422)->assertJson(['code' => 'address']);
    }

    public function test_signature_over_a_different_message_is_rejected(): void
    {
        $valid2 = $this->prime('valid2');
        $other = $this->f['cases']['valid'];
        $this->postJson('/admin/auth/verify', ['message' => $valid2['message'], 'signature' => $other['signature']])
            ->assertStatus(422)->assertJson(['code' => 'signature']);
    }

    public function test_tampered_message_is_rejected(): void
    {
        $case = $this->prime('valid');
        $this->postJson('/admin/auth/verify', ['message' => str_replace('เข้าสู่หลังบ้าน IDTC', 'อนุมัติทุกอย่าง', $case['message']), 'signature' => $case['signature']])
            ->assertStatus(422)->assertJson(['code' => 'format']);
    }

    public function test_valid_signature_from_non_admin_is_rejected(): void
    {
        config(['idtc.admin_wallets' => ['0x3333333333333333333333333333333333333333']]);

        $this->verify($this->prime('valid'))->assertStatus(403)->assertJson(['code' => 'not_admin']);
        $this->assertNull(session('admin.address'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.denied']);
    }

    public function test_db_admin_can_log_in_with_its_role(): void
    {
        config(['idtc.admin_wallets' => []]);
        Admin::query()->create(['address' => $this->f['address'], 'role' => 'viewer']);

        $this->verify($this->prime('valid'))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'role' => 'viewer']);
    }

    public function test_revoked_admin_is_logged_out_on_next_request(): void
    {
        $addr = '0x4444444444444444444444444444444444444444';
        $this->loginAs($addr, 'admin')->get('/admin')->assertOk();

        Admin::query()->where('address', $addr)->delete();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertNull(session('admin.address'));
    }

    public function test_logout_clears_session(): void
    {
        $this->loginAsOwner();
        config(['idtc.admin_wallets' => [$this->ownerAddress]]);
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertNull(session('admin.address'));
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.logout')->count());
    }

    public function test_auth_endpoints_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/admin/auth/nonce', ['address' => strtolower($this->f['address'])])->assertOk();
        }
        $this->postJson('/admin/auth/nonce', ['address' => strtolower($this->f['address'])])->assertStatus(429);
        $this->verify($this->prime('valid'))->assertStatus(429);
    }
}

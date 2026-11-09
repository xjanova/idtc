<?php

namespace Tests\Feature\Grid;

use App\Models\GridMember;
use App\Models\Purchase;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * หน้า /grid ลิงก์ชวน และการเซ็นเข้าระบบ/ผูกผู้ชวนของสมาชิก
 */
class GridPublicTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    /** @var array<string, mixed> เวกเตอร์จาก ethers (คีย์สุ่มทิ้ง ใช้ทดสอบเท่านั้น) */
    private array $f;

    private string $me;

    private string $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        URL::forceRootUrl('http://localhost');
        $this->setUpAdmins();
        $this->f = json_decode((string) file_get_contents(base_path('tests/Fixtures/grid-vectors.json')), true);
        $this->me = strtolower($this->f['address']);
        $this->ref = strtolower($this->f['referrer']);
        Carbon::setTestNow('2026-12-02 05:01:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(bool $on = true): void
    {
        app(Settings::class)->put('grid.enabled', $on);
    }

    /** จำลองว่าเว็บออก nonce ของเคสนี้ให้แล้ว (เหมือนเรียก /grid/auth/nonce) */
    private function prime(string $case, array $extra = []): array
    {
        $c = $this->f['cases'][$case];
        Cache::put('idtc:siwe:nonce:'.$c['nonce'], ['address' => $this->me, 'path' => '/grid', 'statement' => $c['statement'], 'extra' => $extra], 300);

        return $c;
    }

    private function verify(array $c, string $prefix = '')
    {
        return $this->postJson($prefix.'/grid/auth/verify', ['message' => $c['message'], 'signature' => $c['signature']]);
    }

    public function test_closed_grid_is_invisible_to_the_public(): void
    {
        $this->get('/grid')->assertNotFound();
        $this->get('/en/grid')->assertNotFound();
        $this->get('/r/'.$this->ref)->assertRedirect('http://localhost');
        $this->postJson('/grid/auth/nonce', ['address' => $this->me])->assertNotFound();
        $this->getJson('/grid/me')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('href="http://localhost/grid"', false);
    }

    public function test_admin_can_preview_a_closed_grid(): void
    {
        $this->loginAsOwner()->get('/grid')->assertOk()->assertSee('data-testid="grid-preview"', false);
    }

    public function test_open_grid_pages_render_in_both_languages(): void
    {
        $this->enable();
        $this->get('/grid')->assertOk()->assertSee('IDTC Grid')->assertSee('ชวนเพื่อน')->assertDontSee('data-testid="grid-preview"', false);
        $html = $this->get('/en/grid')->assertOk()->assertSee('<html lang="en">', false)->getContent();
        $this->assertDoesNotMatchRegularExpression('/[\x{0E00}-\x{0E7F}]/u', $html, 'English /grid has Thai text');
        // ตัวเลขบนหน้ามาจากแผน: วง 1 5% · งบ 15,000,000,000
        $this->assertStringContainsString('15,000,000,000', $html);
        $this->get('/')->assertSee('href="http://localhost/grid"', false);
    }

    public function test_invite_link_sets_cookie_and_page_shows_the_inviter(): void
    {
        $this->enable();
        $res = $this->get('/r/'.$this->f['referrer'])->assertRedirect('http://localhost/grid');
        $res->assertCookie('idtc_ref', $this->ref);
        $this->get('/en/r/'.$this->f['referrer'])->assertRedirect('http://localhost/en/grid');
        $this->withCookie('idtc_ref', $this->ref)->get('/grid')->assertSee($this->f['referrer']);
        $this->get('/r/0x123')->assertNotFound();
    }

    public function test_nonce_issues_a_grid_message_and_bind_rules_are_checked_before_signing(): void
    {
        $this->enable();
        $msg = $this->postJson('/grid/auth/nonce', ['address' => $this->f['address']])->assertOk()->json('message');
        $this->assertStringContainsString("\n\nเข้าสู่ IDTC Grid\n\n", $msg);
        $this->assertStringContainsString('URI: http://localhost/grid', $msg);
        $en = $this->postJson('/en/grid/auth/nonce', ['address' => $this->f['address']])->json('message');
        $this->assertStringContainsString("\n\nSign in to IDTC Grid\n\n", $en);

        $bind = $this->postJson('/grid/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => $this->ref])->assertOk()->json('message');
        $this->assertStringContainsString($this->f['referrer'], $bind);

        $this->postJson('/grid/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => $this->me])->assertStatus(422)->assertJson(['code' => 'self']);
        $this->postJson('/grid/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => 'nope'])->assertStatus(422)->assertJson(['code' => 'referrer']);
        $this->postJson('/grid/auth/nonce', ['address' => 'nope'])->assertStatus(422)->assertJson(['code' => 'address']);

        // ผู้ชวนอยู่ในทีมของเราเอง → วงวน
        GridMember::query()->create(['wallet' => $this->ref, 'referrer' => $this->me, 'bound_at' => now()]);
        $this->postJson('/grid/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => $this->ref])->assertStatus(422)->assertJson(['code' => 'cycle']);
    }

    public function test_bind_is_refused_after_the_first_purchase(): void
    {
        $this->enable();
        Purchase::query()->create([
            'contract' => '0x3333333333333333333333333333333333333333', 'tx_hash' => '0x'.str_repeat('a', 64), 'log_index' => 0, 'buyer' => $this->me,
            'pay_amount' => '10000000', 'tokens_out' => '0', 'pay_usdt' => 10, 'tokens_idtc' => 6666666.67, 'block_number' => 1, 'block_time' => now(),
        ]);
        $this->postJson('/grid/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => $this->ref])->assertStatus(422)->assertJson(['code' => 'bought']);
    }

    public function test_sign_in_then_me_returns_the_dashboard(): void
    {
        $this->enable();
        $this->getJson('/grid/me')->assertStatus(401);
        $this->verify($this->prime('login_th', ['purpose' => 'login']))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame($this->me, session('grid.address'));
        $this->assertSame(0, GridMember::query()->count()); // เข้าระบบอย่างเดียว ไม่ผูกอะไร

        $this->getJson('/grid/me')->assertOk()->assertJson([
            'address' => $this->f['address'],
            'referrer' => null,
            'can_bind' => true,
            'link' => 'http://localhost/r/'.$this->f['address'],
            'rank' => 'none',
        ]);
        // ใช้ข้อความเดิมซ้ำไม่ได้
        $this->verify($this->f['cases']['login_th'])->assertStatus(422)->assertJson(['code' => 'nonce']);
        $this->postJson('/grid/logout')->assertOk();
        $this->getJson('/grid/me')->assertStatus(401);
    }

    public function test_signed_bind_stores_the_proof_and_can_happen_only_once(): void
    {
        $this->enable();
        $this->verify($this->prime('bind_th', ['purpose' => 'bind', 'referrer' => $this->ref]))->assertOk()->assertJson(['bound' => true]);
        $row = GridMember::query()->where('wallet', $this->me)->firstOrFail();
        $this->assertSame($this->ref, $row->referrer);
        $this->assertSame($this->f['cases']['bind_th']['signature'], $row->signature);
        $this->assertNotNull($row->bound_at);

        $this->verify($this->prime('bind_th2', ['purpose' => 'bind', 'referrer' => $this->ref]))
            ->assertStatus(422)->assertJson(['code' => 'bound', 'signed_in' => true]);
        $this->getJson('/grid/me')->assertOk()->assertJson(['referrer' => $this->f['referrer'], 'can_bind' => false]);
    }

    public function test_bound_referrer_must_be_the_one_shown_in_the_signed_message(): void
    {
        $this->enable();
        // nonce ถูกออกให้ผูกกับอีกคน แต่ข้อความที่เซ็นแสดงผู้ชวนคนละคน → ไม่บันทึก
        $other = '0x9999999999999999999999999999999999999999';
        $this->verify($this->prime('bind_th', ['purpose' => 'bind', 'referrer' => $other]))->assertStatus(422)->assertJson(['code' => 'format']);
        $this->assertSame(0, GridMember::query()->count());
        $this->assertNull(session('grid.address'));
    }

    public function test_admin_and_grid_messages_are_not_interchangeable(): void
    {
        $this->enable();
        $admin = json_decode((string) file_get_contents(base_path('tests/Fixtures/ethers-vectors.json')), true);
        $c = $admin['cases']['valid'];
        Carbon::setTestNow('2026-10-11 05:01:00');
        Cache::put('idtc:siwe:nonce:'.$c['nonce'], ['address' => strtolower($admin['address']), 'path' => '/grid', 'statement' => 'เข้าสู่หลังบ้าน IDTC', 'extra' => []], 300);
        $this->verify($c)->assertStatus(422)->assertJson(['code' => 'format']);

        Carbon::setTestNow('2026-12-02 05:01:00');
        $g = $this->f['cases']['login_th'];
        Cache::put('idtc:siwe:nonce:'.$g['nonce'], ['address' => $this->me], 300); // nonce แบบหลังบ้าน
        $this->postJson('/admin/auth/verify', ['message' => $g['message'], 'signature' => $g['signature']])->assertStatus(422);
        $this->assertNull(session('admin'));
    }

    public function test_wrong_signer_is_rejected(): void
    {
        $this->enable();
        $c = $this->f['cases']['login_th'];
        Cache::put('idtc:siwe:nonce:'.$c['nonce'], ['address' => $this->ref, 'path' => '/grid', 'statement' => $c['statement'], 'extra' => []], 300);
        $this->verify($c)->assertStatus(422)->assertJson(['code' => 'address_mismatch']);
        $this->assertNull(session('grid.address'));
    }
}

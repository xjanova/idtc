<?php

namespace Tests\Feature\Binary;

use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Services\Grid\GridBinder;
use App\Services\Grid\GridPlan;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * แผน Binary โปรโมชั่น (เฟส 2): หน้า /binary สองภาษา · ลิงก์ชวน /b/{wallet}/{l|r} · เซ็นเข้าระบบ/ผูกผู้ชวน · API ของสมาชิก
 */
class BinaryPageTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const WALLET = '0x399679922b673fee28c507b07ff12e17abfd78a8';

    private const THAI = '/[\x{0E00}-\x{0E7F}]/u';

    /** @var array<string, mixed> เวกเตอร์จาก ethers (คีย์สุ่มทิ้ง ใช้ทดสอบเท่านั้น) */
    private array $f;

    private string $me;

    private string $ref;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        URL::forceRootUrl('http://localhost');
        $this->setUpAdmins();
        $this->f = json_decode((string) file_get_contents(base_path('tests/Fixtures/binary-vectors.json')), true);
        $this->me = strtolower($this->f['address']);
        $this->ref = strtolower($this->f['referrer']);
        Carbon::setTestNow('2026-12-02 05:01:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** เปิดแผน: กระเป๋า + โควตา + ก้อนที่มา + เปิด */
    private function open(float $quota = 1e12): void
    {
        $s = app(Settings::class);
        $s->put('binary.wallet', self::WALLET);
        $s->put('binary.quota', (string) $quota);
        $s->put('binary.source', 'operations');
        $s->put('binary.enabled', true);
    }

    private function prime(string $case, array $extra = []): array
    {
        $c = $this->f['cases'][$case];
        Cache::put('idtc:siwe:nonce:'.$c['nonce'], ['address' => $this->me, 'path' => '/binary', 'statement' => $c['statement'], 'extra' => $extra], 300);

        return $c;
    }

    private function verify(array $c, string $prefix = '')
    {
        return $this->postJson($prefix.'/binary/auth/verify', ['message' => $c['message'], 'signature' => $c['signature']]);
    }

    private function deposit(string $wallet, float $usdt, string $at = '2026-12-02 05:00:00', string $status = 'ok'): BinaryDeposit
    {
        $this->seq++;
        $ts = CarbonImmutable::parse($at);

        return BinaryDeposit::query()->create([
            'tx_hash' => '0x'.str_pad(dechex($this->seq), 64, 'f', STR_PAD_LEFT), 'log_index' => 0, 'wallet' => strtolower($wallet), 'to' => self::WALLET,
            'amount' => (string) (int) round($usdt * 1e6), 'usdt' => $usdt, 'block_number' => $this->seq, 'block_time' => $ts,
            'week' => GridPlan::purchaseWeek($ts->getTimestamp()), 'status' => $status, 'price' => '0.0000015',
            'idtc' => $status === 'ok' ? $usdt / 0.0000015 : 0, 'idtc_micro' => $status === 'ok' ? BinaryDeposit::microIdtc((string) (int) round($usdt * 1e6), '0.0000015') : '0', 'pool_usdt' => $status === 'ok' ? $usdt * 0.30 : 0,
        ]);
    }

    public function test_closed_plan_is_invisible_to_the_public(): void
    {
        $this->get('/binary')->assertNotFound();
        $this->get('/en/binary')->assertNotFound();
        $this->get('/b/'.$this->ref.'/l')->assertRedirect('http://localhost');
        $this->postJson('/binary/auth/nonce', ['address' => $this->me])->assertNotFound();
        $this->getJson('/binary/me')->assertNotFound();
        $this->postJson('/binary/side', ['side' => 'L'])->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('href="http://localhost/binary"', false);
    }

    public function test_admin_can_preview_a_closed_plan(): void
    {
        $this->loginAsOwner()->get('/binary')->assertOk()
            ->assertSee('data-testid="binary-preview"', false)->assertSee('data-testid="binary-not-ready"', false);
    }

    public function test_open_plan_renders_in_both_languages_with_the_plan_numbers(): void
    {
        $this->open();
        $th = $this->get('/binary')->assertOk();
        $th->assertSee('แผนโปรโมชั่น')->assertSee('อ่านก่อนเข้าแผน')->assertSee('0.0000015 USDT')
            ->assertSee('30%')->assertSee('70%')                                         // เข้ากอง 30% · ที่เหลือ 70%
            ->assertSee('เข้าพูลสภาพคล่อง IDTC/USDT')
            ->assertSee('0x399679922b673FEE28c507B07FF12e17aBFD78a8')                    // กระเป๋า Binary (checksum)
            ->assertSee('ดำเนินงาน การตลาด และ listing')
            ->assertDontSee('data-testid="binary-not-ready"', false)
            ->assertSee('<link rel="canonical" href="http://localhost/binary">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="http://localhost/en/binary">', false);
        $this->get('/')->assertSee('href="http://localhost/binary"', false);

        app(Settings::class)->put('binary.rest', 'treasury');
        app(Settings::class)->put('binary.ends', '2027-12-31T23:59:59+07:00');
        $html = $this->get('/en/binary')->assertOk()->assertSee('Promotional plan')->assertSee('To the project wallet')
            ->assertSee('Operations, marketing and listings')->assertSee('31 Dec 2027')->getContent();
        $this->assertSame(0, preg_match(self::THAI, $html), '/en/binary contains Thai');
        $this->assertStringNotContainsStringIgnoringCase('\u0e', $html, '/en/binary has Thai inside JSON');
        $this->get('/en')->assertSee('href="http://localhost/en/binary"', false);
    }

    public function test_leg_links_set_cookies_and_the_page_shows_the_inviter(): void
    {
        $this->open();
        $this->get('/b/'.$this->f['referrer'].'/r')->assertRedirect('http://localhost/binary')
            ->assertCookie('idtc_ref', $this->ref)->assertCookie('idtc_side', 'r');
        $this->get('/en/b/'.$this->f['referrer'])->assertRedirect('http://localhost/en/binary')->assertCookieExpired('idtc_side');
        $this->get('/b/'.$this->f['referrer'].'/x')->assertNotFound();
        $this->get('/b/0x123')->assertNotFound();

        $this->withCookie('idtc_ref', $this->ref)->withCookie('idtc_side', 'r')->get('/binary')
            ->assertSee($this->f['referrer'])->assertSee('คำชวนนี้ให้คุณลงขาขวาของผู้ชวน');
    }

    public function test_nonce_issues_a_binary_message_and_checks_bind_rules(): void
    {
        $this->open();
        $msg = $this->postJson('/binary/auth/nonce', ['address' => $this->f['address']])->assertOk()->json('message');
        $this->assertStringContainsString("\n\nเข้าสู่แผน Binary ของ IDTC\n\n", $msg);
        $this->assertStringContainsString('URI: http://localhost/binary', $msg);
        $this->assertStringContainsString("\n\nSign in to the IDTC Binary plan\n\n", $this->postJson('/en/binary/auth/nonce', ['address' => $this->f['address']])->json('message'));

        $bind = $this->postJson('/binary/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => $this->ref, 'side' => 'r'])->assertOk()->json('message');
        $this->assertStringContainsString($this->f['referrer'], $bind);
        $this->postJson('/binary/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => $this->me])->assertStatus(422)->assertJson(['code' => 'self']);
        $this->postJson('/binary/auth/nonce', ['address' => 'nope'])->assertStatus(422)->assertJson(['code' => 'address']);

        // ฝากแล้ว = ผูกผู้ชวนไม่ได้
        $this->deposit($this->me, 50);
        $this->postJson('/binary/auth/nonce', ['address' => $this->me, 'purpose' => 'bind', 'referrer' => $this->ref])->assertStatus(422)->assertJson(['code' => 'deposited']);
    }

    public function test_sign_in_then_me_returns_the_dashboard(): void
    {
        $this->open();
        $this->getJson('/binary/me')->assertStatus(401);
        $this->verify($this->prime('login_th', ['purpose' => 'login']))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame($this->me, session('grid.address'));          // session สมาชิกเดียวกับ /grid
        $this->assertSame(0, GridMember::query()->count());

        $me = $this->getJson('/binary/me')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame($this->f['address'], $me['address']);
        $this->assertNull($me['referrer']);
        $this->assertTrue($me['can_bind']);
        $this->assertTrue($me['open']);
        $this->assertSame('0x399679922b673FEE28c507B07FF12e17aBFD78a8', $me['wallet']);
        $this->assertSame('0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1', $me['usdt']);
        $this->assertSame('0.0000015', $me['plan']['price']);
        $this->assertSame('http://localhost/b/'.$this->f['address'].'/l', $me['links']['left']);
        $this->assertSame('http://localhost/b/'.$this->f['address'].'/r', $me['links']['right']);
        $this->assertSame([], $me['deposits']);
        $this->assertSame(0.0, (float) $me['mine']['usdt']);

        $this->postJson('/binary/logout')->assertOk();
        $this->getJson('/binary/me')->assertStatus(401);
    }

    public function test_signed_bind_places_the_wallet_on_the_chosen_leg(): void
    {
        $this->open();
        $this->verify($this->prime('bind_th', ['purpose' => 'bind', 'referrer' => $this->ref, 'side' => 'R']))
            ->assertOk()->assertJson(['bound' => true])->assertCookieExpired('idtc_ref')->assertCookieExpired('idtc_side');
        $row = GridMember::query()->where('wallet', $this->me)->sole();
        $this->assertSame($this->ref, $row->referrer);
        $this->assertSame($this->ref, $row->bin_parent);
        $this->assertSame('R', $row->bin_leg);

        $me = $this->getJson('/binary/me')->json();
        $this->assertSame($this->f['referrer'], $me['referrer']);
        $this->assertFalse($me['can_bind']);
        $this->assertSame('R', $me['mine']['leg']);
    }

    public function test_grid_and_binary_messages_are_not_interchangeable(): void
    {
        $this->open();
        app(Settings::class)->put('grid.enabled', true);
        $grid = json_decode((string) file_get_contents(base_path('tests/Fixtures/grid-vectors.json')), true);
        $c = $grid['cases']['login_th'];
        Cache::put('idtc:siwe:nonce:'.$c['nonce'], ['address' => strtolower($grid['address']), 'path' => '/grid', 'statement' => $c['statement'], 'extra' => ['purpose' => 'login']], 300);
        $this->postJson('/binary/auth/verify', ['message' => $c['message'], 'signature' => $c['signature']])->assertStatus(422);
        $this->assertNull(session('grid.address'));

        // และข้อความของ /binary ใช้เข้า /grid ไม่ได้
        $b = $this->prime('login_th', ['purpose' => 'login']);
        $this->postJson('/grid/auth/verify', ['message' => $b['message'], 'signature' => $b['signature']])->assertStatus(422);
    }

    public function test_me_shows_deposits_legs_and_whether_deposits_are_open(): void
    {
        $this->open(quota: 200_000_000);                                      // 300 USDT ที่ราคาตั้งต้น
        $l = '0x'.str_repeat('a', 40);
        $r = '0x'.str_repeat('b', 40);
        Carbon::setTestNow('2026-12-01 12:00:00');
        foreach ([$l, $r] as $w) {
            $this->assertInstanceOf(GridMember::class, app(GridBinder::class)->bind($w, $this->me, 'm', 's'));
        }
        Carbon::setTestNow('2026-12-02 05:01:00');
        $this->deposit($this->me, 100);
        $this->deposit($l, 120);
        $this->deposit($r, 40);
        $this->deposit($this->me, 5, '2026-12-02 05:00:30', 'min');

        $this->withSession(['grid' => ['address' => $this->me]]);
        $me = $this->getJson('/binary/me')->assertOk()->json();
        $this->assertEqualsWithDelta(100.0, $me['mine']['usdt'], 1e-9);
        $this->assertEqualsWithDelta(100 / 0.0000015, $me['mine']['idtc'], 1e-3);
        $this->assertTrue($me['mine']['qualified']);
        $this->assertSame(1, $me['mine']['left']['count']);
        $this->assertEqualsWithDelta(120.0, $me['mine']['left']['usdt'], 1e-9);
        $this->assertEqualsWithDelta(80.0, $me['mine']['left']['carry'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $me['week']['mine']['share'], 1e-9);       // 10% ของยอดจับคู่ 40
        $this->assertEqualsWithDelta(4.0, $me['mine']['wait'], 1e-9);
        $this->assertSame(['min', 'ok'], array_column($me['deposits'], 'status'));
        $this->assertTrue($me['open']);
        $this->assertEqualsWithDelta(200_000_000 - 260 / 0.0000015, $me['quota_left'], 1);

        // คืนเงินรายการที่ไม่นับแล้ว → สมาชิกเห็นว่าคืนแล้ว
        BinaryDeposit::query()->where('status', 'min')->update(['refunded_at' => now(), 'refund_tx' => '0x'.str_repeat('e', 64)]);
        $this->assertSame('refunded', $this->getJson('/binary/me')->json('deposits.0.status'));

        // หมดเวลาแผน = ฝากไม่ได้
        app(Settings::class)->put('binary.ends', '2026-12-02T00:00:00+00:00');
        $this->assertFalse($this->getJson('/binary/me')->json('open'));
    }

    public function test_default_leg_preference_is_saved_per_member(): void
    {
        $this->open();
        $this->postJson('/binary/side', ['side' => 'R'])->assertStatus(401);
        $this->withSession(['grid' => ['address' => $this->me]]);
        $this->postJson('/binary/side', ['side' => 'R'])->assertOk()->assertJson(['pref' => 'R']);
        $this->assertSame('R', $this->getJson('/binary/me')->json('pref'));
        $this->postJson('/binary/side', ['side' => ''])->assertOk()->assertJson(['pref' => null]);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Support\Idtc;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private string $contract = '0x399679922b673FEE28c507B07FF12e17aBFD78a8';

    private string $eoa = '0x7777777777777777777777777777777777777777';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        config(['idtc.contracts.presale' => null, 'idtc.contracts.token' => null]);
        $this->fakeRpc(function (string $method, array $params) {
            return match ($method) {
                'eth_getCode' => strtolower($params[0]) === strtolower($this->contract) ? '0x6080604052' : '0x',
                'eth_blockNumber' => '0x1f4', // 500
                'eth_chainId' => '0x10c1',
                default => ['__error' => 'not faked'],
            };
        });
    }

    public function test_rejects_malformed_address(): void
    {
        $this->loginAsOwner()->post('/admin/settings/contracts', ['key' => 'presale', 'address' => '0x1234'])
            ->assertSessionHasErrors('address_presale');
        $this->assertNull(Idtc::contract('presale'));
    }

    public function test_rejects_bad_checksum(): void
    {
        $this->loginAsOwner()->post('/admin/settings/contracts', ['key' => 'presale', 'address' => '0x399679922b673FEE28c507B07FF12e17aBFD78A8'])
            ->assertSessionHasErrors('address_presale');
    }

    public function test_rejects_address_without_contract_code(): void
    {
        $this->loginAsOwner()->post('/admin/settings/contracts', ['key' => 'presale', 'address' => $this->eoa])
            ->assertSessionHasErrors('address_presale');
        $this->assertNull(Idtc::contract('presale'));
        $this->assertSame(0, AuditLog::query()->where('action', 'settings.contract')->count());
    }

    public function test_rejects_address_already_used_by_another_contract(): void
    {
        $usdt = config('idtc.contracts.usdt');
        $this->loginAsOwner()->post('/admin/settings/contracts', ['key' => 'token', 'address' => $usdt])
            ->assertSessionHasErrors('address_token');
    }

    public function test_rpc_failure_blocks_save_with_thai_message(): void
    {
        $this->fakeRpc(fn () => ['__error' => 'boom']);
        $this->loginAsOwner()->post('/admin/settings/contracts', ['key' => 'presale', 'address' => $this->contract])
            ->assertSessionHasErrors('address_presale')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ตรวจที่อยู่กับเชนไม่ได้'));
        $this->assertNull(Idtc::contract('presale'));
    }

    public function test_valid_contract_is_saved_applied_and_audited(): void
    {
        $this->loginAsOwner()->post('/admin/settings/contracts', ['key' => 'presale', 'address' => strtolower($this->contract)])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        // แก้ทับ config ผ่าน Idtc::resolveContractsUsing — หน้าเว็บสาธารณะเห็นค่าใหม่ทันที
        $this->assertSame($this->contract, Idtc::contract('presale'));
        $this->assertSame($this->contract, Idtc::publicConfig()['contracts']['presale']);

        $log = AuditLog::query()->where('action', 'settings.contract')->firstOrFail();
        $this->assertSame(['key' => 'presale', 'old' => null, 'new' => $this->contract], $log->details);
        $this->assertSame(strtolower($this->ownerAddress), $log->actor);

        // ตั้ง presale ครั้งแรก → บล็อกเริ่มดึงยอด = บล็อกปัจจุบัน
        $this->assertSame(500, app(Settings::class)->indexerStartBlock());
    }

    public function test_clearing_override_falls_back_to_config(): void
    {
        $fromEnv = '0x8888888888888888888888888888888888888888';
        config(['idtc.contracts.presale' => $fromEnv]);
        app(Settings::class)->put('contracts', ['presale' => $this->contract]);
        $this->assertSame($this->contract, Idtc::contract('presale'));

        $this->loginAsOwner()->post('/admin/settings/contracts', ['key' => 'presale', 'address' => ''])->assertSessionHas('status');
        $this->assertSame($fromEnv, Idtc::contract('presale'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.contract']);
    }

    public function test_override_is_ignored_when_invalid_and_public_page_still_renders(): void
    {
        app(Settings::class)->put('contracts', ['presale' => 'not-an-address', 'token' => $this->contract]);
        $this->assertNull(Idtc::contract('presale'));
        $this->assertSame($this->contract, Idtc::contract('token'));
    }

    public function test_announcement_feeds_public_layout(): void
    {
        $this->loginAsOwner()->post('/admin/content', ['section' => 'announcement', 'announcement' => "  presale รอบแรก\n<b>เปิด 1 พ.ย.</b>  "])
            ->assertSessionHas('status');

        $this->assertSame('presale รอบแรก <b>เปิด 1 พ.ย.</b>', app(Settings::class)->announcement());
        // layout ของหน้าสาธารณะได้ $announcement จาก View::composer และ escape HTML เสมอ
        $html = view('layouts.site')->render();
        $this->assertStringContainsString('presale รอบแรก', $html);
        $this->assertStringContainsString('&lt;b&gt;เปิด 1 พ.ย.&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>เปิด 1 พ.ย.</b>', $html);

        $log = AuditLog::query()->where('action', 'content.announcement')->firstOrFail();
        $this->assertNull($log->details['old']);
    }

    public function test_english_announcement_is_saved_separately_and_shown_only_on_english_pages(): void
    {
        $this->loginAsOwner()->post('/admin/content', ['section' => 'announcement', 'announcement' => 'เปิดขายรอบแรก', 'announcement_en' => "  Round 1\n<b>opens</b> "])
            ->assertSessionHas('status');

        $settings = app(Settings::class);
        $this->assertSame('เปิดขายรอบแรก', $settings->announcement());
        $this->assertSame('Round 1 <b>opens</b>', $settings->announcement('en'));
        $this->assertSame(1, AuditLog::query()->where('action', 'content.announcement_en')->count());

        $this->get('/en')->assertSee('Round 1 &lt;b&gt;opens&lt;/b&gt;', false)->assertDontSee('เปิดขายรอบแรก');
        $this->get('/')->assertSee('เปิดขายรอบแรก')->assertDontSee('Round 1');

        // ฟอร์มที่ไม่ส่งช่องภาษาอังกฤษมา ไม่ลบประกาศภาษาอังกฤษเดิม
        $this->loginAsOwner()->post('/admin/content', ['section' => 'announcement', 'announcement' => 'ข่าวใหม่']);
        $this->assertSame('Round 1 <b>opens</b>', app(Settings::class)->announcement('en'));

        // ล้างช่องภาษาอังกฤษ = หน้าอังกฤษไม่มีแถบประกาศ
        $this->loginAsOwner()->post('/admin/content', ['section' => 'announcement', 'announcement' => 'ข่าวใหม่', 'announcement_en' => '']);
        $this->assertNull(app(Settings::class)->announcement('en'));
        $this->get('/en')->assertDontSee('class="announce"', false);

        $this->loginAsOwner()->post('/admin/content', ['section' => 'announcement', 'announcement_en' => str_repeat('a', 241)])
            ->assertSessionHasErrors('announcement_en');
    }

    public function test_announcement_length_is_limited(): void
    {
        $this->loginAsOwner()->post('/admin/content', ['section' => 'announcement', 'announcement' => str_repeat('ก', 241)])
            ->assertSessionHasErrors('announcement');
    }

    public function test_social_links_must_be_https(): void
    {
        $this->loginAsOwner()->post('/admin/content', ['section' => 'social', 'social' => ['telegram' => 'javascript:alert(1)']])
            ->assertSessionHasErrors('social.telegram');
        $this->loginAsOwner()->post('/admin/content', ['section' => 'social', 'social' => ['telegram' => 'https://t.me/idtc']])
            ->assertSessionHas('status');
        $this->assertSame(['telegram' => 'https://t.me/idtc'], app(Settings::class)->social());
    }

    public function test_project_wallets_are_validated_and_saved(): void
    {
        $this->loginAsOwner()->post('/admin/wallets', ['label' => ['การตลาด'], 'address' => ['0xabc']])->assertSessionHas('error');
        $this->loginAsOwner()->post('/admin/wallets', ['label' => ['การตลาด', 'สำรอง'], 'address' => [$this->eoa, $this->eoa]])->assertSessionHas('error');
        $this->loginAsOwner()->post('/admin/wallets', ['label' => ['การตลาด'], 'address' => [$this->eoa]])->assertSessionHas('status');
        $this->assertSame([['label' => 'การตลาด', 'address' => $this->eoa]], app(Settings::class)->projectWallets());
    }

    public function test_indexer_start_block_validation(): void
    {
        $this->loginAsOwner()->post('/admin/settings/indexer', ['start_block' => '-5'])->assertSessionHasErrors('start_block');
        $this->loginAsOwner()->post('/admin/settings/indexer', ['start_block' => '1234'])->assertSessionHas('status');
        $this->assertSame(1234, app(Settings::class)->indexerStartBlock());
    }
}

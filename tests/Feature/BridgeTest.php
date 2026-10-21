<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** หน้า /bridge และตัวกลาง /api/bridge/* ไปยังสะพาน USDT ของ tpix.online */
class BridgeTest extends TestCase
{
    private const VAULT = '0xf46131C82819d7621163F482b3fe88a228A7807c';

    private const BSC_USDT = '0x55d398326f99059fF775485246999027B3197955';

    private const HASH = '0x'.'ab12000000000000000000000000000000000000000000000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config(['idtc.bridge.api' => 'https://bridge.test/api/v1/bridge']);
    }

    /** คำตอบ /info แบบเดียวกับ tpix.online (ตัวอย่างจริง 2026-10-22) */
    private function info(array $override = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'installed' => true,
            'paused_reason' => null,
            'vault_address' => strtolower(self::VAULT),
            'deposit_max_per_tx' => '1000',
            'tpix' => ['chain_id' => 4289, 'usdt_tpix' => strtolower(config('idtc.contracts.usdt')), 'usdt_decimals' => 6],
            'minter' => ['max_per_tx' => '1000', 'daily_cap' => '5000', 'remaining_today' => '4980.5', 'paused' => false],
            'chains' => [
                ['chain_id' => 1, 'enabled' => false, 'usdt_address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'usdt_decimals' => 6, 'withdraw_fee' => '1', 'eta_minutes' => 20],
                ['chain_id' => 56, 'enabled' => true, 'usdt_address' => self::BSC_USDT, 'usdt_decimals' => 18, 'withdraw_fee' => '0.3', 'eta_minutes' => 2],
            ],
            'limits' => [
                'min_deposit' => '1', 'max_deposit_per_tx' => '1000', 'max_deposit_per_address_daily' => '2000', 'deposit_fee' => '0',
                'min_withdraw' => '2', 'max_withdraw_per_tx' => '1000', 'max_payout_daily' => '5000',
            ],
        ], $override);
    }

    /** คำตอบ /info ปัจจุบัน — Http::fake ลงทะเบียนครั้งเดียว เปลี่ยนคำตอบด้วย fakeInfo() */
    private array $infoData = [];

    private bool $faked = false;

    private function fakeInfo(array $data): void
    {
        $this->infoData = $data;
        if (! $this->faked) {
            $this->faked = true;
            Http::fake(['bridge.test/api/v1/bridge/info' => fn () => Http::response(['success' => true, 'data' => $this->infoData])]);
        }
    }

    public function test_bridge_page_renders_in_both_languages(): void
    {
        $this->get('/bridge')->assertOk()->assertSee('x-data="bridge"', false)->assertSee('เติม USDT');
        $this->get('/en/bridge')->assertOk()->assertSee('Bring USDT from', false);
    }

    public function test_public_config_never_ships_the_vault_address(): void
    {
        // ที่อยู่ vault ต้องมาจาก /api/bridge/info ที่ตรวจแล้วเท่านั้น
        $this->get('/bridge')->assertOk()->assertDontSee(strtolower(self::VAULT))->assertDontSee(self::VAULT);
    }

    public function test_info_is_open_when_every_pinned_value_matches(): void
    {
        $this->fakeInfo($this->info());

        $this->getJson('/api/bridge/info')
            ->assertOk()
            ->assertJson([
                'open' => true,
                'reason' => null,
                'vault' => self::VAULT,
                'usdt' => self::BSC_USDT,
                'usdt_decimals' => 18,
                'deposit' => ['min' => '1', 'max_per_tx' => '1000', 'max_per_address_daily' => '2000', 'fee' => '0', 'remaining_today' => '4980.5'],
                'withdraw' => ['min' => '2', 'max_per_tx' => '1000', 'fee' => '0.3'],
                'eta_minutes' => 2,
            ])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_info_closes_deposits_when_the_vault_does_not_match(): void
    {
        $this->fakeInfo($this->info(['vault_address' => '0x000000000000000000000000000000000000beef']));

        $this->getJson('/api/bridge/info')->assertOk()->assertJson(['open' => false, 'reason' => 'vault_mismatch', 'vault' => null]);
    }

    public function test_info_closes_deposits_when_bsc_usdt_or_usdt_tpix_differ(): void
    {
        $info = $this->info();
        $info['chains'][1]['usdt_address'] = '0x000000000000000000000000000000000000dead';
        $this->fakeInfo($info);
        $this->getJson('/api/bridge/info')->assertJson(['open' => false, 'reason' => 'usdt_mismatch', 'vault' => null]);

        Cache::flush();
        $this->fakeInfo($this->info(['tpix' => ['usdt_tpix' => '0x000000000000000000000000000000000000dead']]));
        $this->getJson('/api/bridge/info')->assertJson(['open' => false, 'reason' => 'usdt_tpix_mismatch', 'vault' => null]);
    }

    public function test_info_reports_closed_states_but_keeps_the_verified_vault(): void
    {
        $this->fakeInfo($this->info(['enabled' => false]));
        $this->getJson('/api/bridge/info')->assertJson(['open' => false, 'reason' => 'closed', 'vault' => self::VAULT]);

        Cache::flush();
        $info = $this->info();
        $info['chains'][1]['enabled'] = false;
        $this->fakeInfo($info);
        $this->getJson('/api/bridge/info')->assertJson(['open' => false, 'reason' => 'chain_closed']);

        Cache::flush();
        $this->fakeInfo($this->info(['minter' => ['paused' => true]]));
        $this->getJson('/api/bridge/info')->assertJson(['open' => false, 'reason' => 'minter_paused']);
    }

    public function test_info_is_closed_when_the_bridge_is_unreachable_or_malformed(): void
    {
        Http::fake(['bridge.test/*' => Http::response('oops', 500)]);
        $this->getJson('/api/bridge/info')->assertOk()->assertJson(['open' => false, 'reason' => 'unreachable', 'vault' => null]);

        Cache::flush();
        Http::fake(['bridge.test/*' => Http::response(['success' => true, 'data' => 'nope'])]);
        $this->getJson('/api/bridge/info')->assertJson(['open' => false, 'reason' => 'unreachable']);
    }

    public function test_info_is_cached_between_requests(): void
    {
        $this->fakeInfo($this->info());
        $this->getJson('/api/bridge/info');
        $this->getJson('/api/bridge/info');
        Http::assertSentCount(1);
    }

    public function test_info_drops_non_numeric_limits(): void
    {
        $this->fakeInfo($this->info(['limits' => ['min_deposit' => '1e9', 'min_withdraw' => '<script>']]));
        $this->getJson('/api/bridge/info')->assertJsonPath('deposit.min', null)->assertJsonPath('withdraw.min', null);
    }

    public function test_deposit_status_is_relayed_and_sanitised(): void
    {
        Http::fake(['bridge.test/api/v1/bridge/status/56/*' => Http::response(['success' => true, 'data' => [
            'state' => 'minted',
            'deposits' => [['amount' => '25.5', 'mint_amount' => '25.5', 'mint_tx_hash' => '0x'.str_repeat('c', 64), 'review_reason' => null, 'from_address' => '0x1']],
        ]])]);

        $this->getJson('/api/bridge/deposit/'.self::HASH)
            ->assertOk()
            ->assertJson(['state' => 'minted', 'amount' => '25.5', 'mint_amount' => '25.5', 'mint_tx' => '0x'.str_repeat('c', 64)])
            ->assertJsonMissing(['from_address' => '0x1']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/status/56/'.self::HASH));
    }

    public function test_unknown_states_and_bad_hashes(): void
    {
        Http::fake(['bridge.test/*' => Http::response(['success' => true, 'data' => ['state' => 'hacked', 'withdrawals' => []]])]);

        $this->getJson('/api/bridge/withdrawal/'.self::HASH)->assertOk()->assertJson(['state' => 'unknown']);
        $this->getJson('/api/bridge/withdrawal/0x1234')->assertStatus(422)->assertJson(['state' => 'invalid_hash']);
        $this->getJson('/api/bridge/deposit/not-a-hash')->assertStatus(422);
    }

    public function test_withdrawal_status_keeps_only_valid_fields(): void
    {
        Http::fake(['bridge.test/api/v1/bridge/withdrawals/*' => Http::response(['success' => true, 'data' => [
            'state' => 'paid',
            'withdrawals' => [['amount' => '10', 'fee' => '0.3', 'payout_amount' => '9.7', 'target_address' => '0x00000000000000000000000000000000000000aa', 'payout_tx_hash' => 'javascript:alert(1)']],
        ]])]);

        $this->getJson('/api/bridge/withdrawal/'.self::HASH)
            ->assertJson(['state' => 'paid', 'payout_amount' => '9.7', 'fee' => '0.3', 'target' => '0x00000000000000000000000000000000000000aa', 'payout_tx' => null]);
    }

    public function test_status_endpoints_are_throttled(): void
    {
        Http::fake(['bridge.test/*' => Http::response(['success' => true, 'data' => ['state' => 'pending', 'deposits' => []]])]);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/bridge/deposit/'.self::HASH)->assertOk();
        }
        $this->getJson('/api/bridge/deposit/'.self::HASH)->assertStatus(429);
        // ทั้ง 60 ครั้งออกไปที่สะพานจริงครั้งเดียว (แคช) — tpix.online จำกัดคำขอต่อ IP ของเซิร์ฟเวอร์เรา
        Http::assertSentCount(1);
    }

    public function test_unreachable_status_is_not_cached(): void
    {
        $up = false;
        Http::fake(['bridge.test/*' => function () use (&$up) {
            return $up
                ? Http::response(['success' => true, 'data' => ['state' => 'minted', 'deposits' => []]])
                : Http::response('', 429);
        }]);

        $this->getJson('/api/bridge/deposit/'.self::HASH)->assertJson(['state' => 'unreachable']);
        $up = true;
        $this->getJson('/api/bridge/deposit/'.self::HASH)->assertJson(['state' => 'minted']);
    }
}

<?php

namespace Tests\Feature;

use App\Support\Idtc;
use Tests\TestCase;

/** หน้า /trade — ซื้อขาย IDTC ผ่าน TPIX DEX */
class TradePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['idtc.contracts.token' => null, 'idtc.contracts.pair_usdt' => null, 'idtc.contracts.pair_tpix' => null]);
    }

    protected function tearDown(): void
    {
        Idtc::resolveContractsUsing(null);
        parent::tearDown();
    }

    public function test_trade_page_shows_pending_deploy_before_the_token_exists(): void
    {
        $this->get('/trade')
            ->assertOk()
            ->assertSee('x-data="trade"', false)
            ->assertSee('data-trade-state="pending-deploy"', false)
            ->assertSee('data-testid="trade-pending"', false)
            ->assertSee(config('idtc.contracts.dex_router'));

        $this->get('/en/trade')->assertOk()->assertSee('Awaiting deployment');
    }

    public function test_trade_page_is_live_once_the_token_is_configured(): void
    {
        config(['idtc.contracts.token' => '0x1111111111111111111111111111111111111111']);

        $this->get('/trade')
            ->assertOk()
            ->assertSee('data-trade-state="live"', false)
            ->assertDontSee('data-testid="trade-pending"', false);
    }

    public function test_dex_and_bridge_settings_reach_the_browser(): void
    {
        $html = $this->get('/trade')->getContent();
        $this->assertStringContainsString('"dex":{"fee_bps":30,"slippage_bps":100', $html);
        $this->assertStringContainsString('"usdt":"0x55d398326f99059fF775485246999027B3197955"', $html);
        $this->assertStringContainsString('"trade":"'.str_replace('/', '\/', url('/trade')).'"', $html);
    }

    public function test_navigation_links_to_trade_and_bridge_in_the_page_language(): void
    {
        $this->get('/')->assertSee('href="'.url('/trade').'"', false)->assertSee('href="'.url('/bridge').'"', false);
        $this->get('/en')->assertSee('href="'.url('/en/trade').'"', false)->assertSee('href="'.url('/en/bridge').'"', false);
    }
}

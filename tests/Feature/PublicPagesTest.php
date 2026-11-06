<?php

namespace Tests\Feature;

use App\Support\ContractList;
use App\Support\Idtc;
use App\Support\Launch;
use App\Support\Whitepaper;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    private const TOKEN = '0x1111111111111111111111111111111111111111';

    private const VESTING = '0x2222222222222222222222222222222222222222';

    private const PRESALE = '0x3333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // เริ่มทุกเทสจากสภาพ "ยังไม่ deploy" ไม่ขึ้นกับ .env ของเครื่อง
        config([
            'idtc.contracts.token' => null,
            'idtc.contracts.vesting' => null,
            'idtc.contracts.presale' => null,
            'idtc.contracts.feeder' => null,
            'idtc.contracts.pair_usdt' => null,
            'idtc.contracts.pair_tpix' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Idtc::resolveContractsUsing(null);
        parent::tearDown();
    }

    private function deployAll(): void
    {
        config([
            'idtc.contracts.token' => self::TOKEN,
            'idtc.contracts.vesting' => self::VESTING,
            'idtc.contracts.presale' => self::PRESALE,
        ]);
    }

    public function test_every_public_route_returns_ok(): void
    {
        foreach (['/', '/presale', '/transparency', '/whitepaper', '/add-network'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_every_public_route_returns_ok_when_deployed(): void
    {
        $this->deployAll();
        foreach (['/', '/presale', '/transparency', '/whitepaper', '/add-network'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_presale_shows_pending_deploy_state_with_plan_numbers(): void
    {
        $this->get('/presale')
            ->assertOk()
            ->assertSee('data-presale-state="pending-deploy"', false)
            ->assertSee('data-testid="presale-pending"', false)
            ->assertSee('รอ deploy')
            ->assertSee('666,666.67')          // ราคาในแผน 1 USDT = 666,666.67 IDTC
            ->assertSee('66,666,666.66')       // ค่าเริ่มต้น 100 USDT
            ->assertSee('100,000 ล้าน')        // เพดานรวม
            ->assertSee('x-data="presale"', false)
            ->assertSee('https://tpix.online/bridge', false)
            ->assertSee(config('idtc.contracts.usdt'));
    }

    public function test_presale_shows_live_hooks_when_address_is_configured(): void
    {
        $this->deployAll();

        $this->get('/presale')
            ->assertOk()
            ->assertSee('data-presale-state="live"', false)
            ->assertDontSee('data-testid="presale-pending"', false)
            ->assertSee('กำลังอ่านสัญญา')
            ->assertSee(Idtc::explorerAddress(self::PRESALE), false)
            ->assertSee(Idtc::explorerAddress(self::TOKEN), false)
            // ที่อยู่ไปถึง JavaScript ผ่าน window.IDTC
            ->assertSee('"presale":"'.self::PRESALE.'"', false);
    }

    public function test_admin_override_resolver_switches_pages_to_live(): void
    {
        Idtc::resolveContractsUsing(fn () => ['presale' => self::PRESALE, 'vesting' => self::VESTING]);

        $this->get('/presale')->assertSee('data-presale-state="live"', false);
        $this->get('/transparency')->assertSee('data-vesting-state="live"', false);
    }

    public function test_invalid_address_is_treated_as_not_deployed(): void
    {
        config(['idtc.contracts.presale' => '0x123', 'idtc.contracts.vesting' => 'not-an-address']);

        $this->get('/presale')->assertSee('data-presale-state="pending-deploy"', false);
        $this->get('/transparency')->assertSee('data-vesting-state="pending-deploy"', false);
    }

    public function test_transparency_shows_the_plan_when_vesting_is_not_deployed(): void
    {
        $response = $this->get('/transparency')
            ->assertOk()
            ->assertSee('data-vesting-state="pending-deploy"', false)
            ->assertSee('สัญญาล็อกยังไม่ขึ้นเชน — ตัวเลขด้านล่างคือแผน')
            ->assertSee('770,000')
            ->assertSee('ล็อก 1 ปี')
            ->assertSee('ล็อก 4 ปี')
            ->assertSee('18,333,333,333.33')   // 220,000 ล้าน ÷ 12 งวด
            ->assertSee('รอ deploy');

        // ไทม์ไลน์ 60 ช่องต่อก้อน × 4 ก้อน
        $this->assertSame(4, substr_count($response->getContent(), 'class="timeline"'));
        // ก้อน "ล็อก 1 ปี": ช่องล็อก 12 + 12+24+36+48 ของก้อนอื่น = 120 ช่องล็อกทั้งหมด
        $this->assertSame(12 + 24 + 36 + 48, substr_count($response->getContent(), '<i class="lock"></i>'));
        $this->assertSame(48, substr_count($response->getContent(), '<i class="due"></i>'));
    }

    public function test_transparency_shows_live_hooks_and_contract_table_when_deployed(): void
    {
        $this->deployAll();

        $this->get('/transparency')
            ->assertOk()
            ->assertSee('data-vesting-state="live"', false)
            ->assertDontSee('สัญญาล็อกยังไม่ขึ้นเชน')
            ->assertSee('ปลดงวดที่ถึงเวลา')
            ->assertSee(self::VESTING)
            ->assertSee("verifyBadge('".self::VESTING."')", false)
            ->assertSee('x-for="(t, i) in tranches"', false);
    }

    public function test_presale_shows_the_schedule_two_ways_to_buy_and_release_conditions(): void
    {
        $this->get('/presale')
            ->assertOk()
            // กำหนดการที่เจ้าของตัดสิน 2026-11-06 (เวลาไทย)
            ->assertSee('1 ธ.ค. 2569 เวลา 10:00 น.')
            ->assertSee('1 มี.ค. 2570 เวลา 10:00 น.')
            ->assertSee('90 วัน')
            ->assertSee('29 ก.พ. 2571 เวลา 10:00 น.')
            ->assertSee('datetime="2026-12-01T10:00:00+07:00"', false)
            // ซื้อด้วย USDT บน BNB Chain ผ่าน launchpad
            ->assertSee('data-testid="launchpad-link"', false)
            ->assertSee('https://tpix.online/launchpad/IDTC', false)
            // เงินขายเข้าพูล 100% และ LP ล็อก
            ->assertSee('เติมเข้าพูล IDTC/USDT 100%')
            ->assertSee('ห่างจากราคาขายไม่เกิน 5%')
            ->assertDontSee('เท่ากับราคาขาย')
            ->assertDontSee('USDT เข้ากระเป๋าโครงการทันที')
            // 4 ส่วนรวมกันเท่าจำนวนเหรียญทั้งหมด: presale 100B + พูล 100B + ล็อก 770B + กระเป๋าโครงการ 130B
            ->assertSee('Presale 100,000 ล้าน')
            ->assertSee('จับคู่เข้าพูล 100,000 ล้าน')
            ->assertSee('ล็อก 70% 770,000 ล้าน')
            ->assertSee('กระเป๋าโครงการ 130,000 ล้าน')
            // ตารางปลด: ก้อน 1 ปีปลดงวดแรก 13 งวดหลังวันเปิดขาย · ก้อน 4 ปีปลดครบ 60 งวด
            ->assertSee('1 ม.ค. 2571')
            ->assertSee('1 ธ.ค. 2574')
            ->assertSee('18,333,333,333')
            ->assertSee('770,000,000,000')
            // เอกสาร
            ->assertSee('https://github.com/xjanova/TPIX-Coin/tree/main/contracts/src/launch', false)
            ->assertSee('data-contract="feeder"', false);
    }

    public function test_release_schedule_follows_the_vesting_formula(): void
    {
        $rows = Launch::releaseSchedule();
        $this->assertCount(4, $rows);
        // 24 งวด = 730.5 วัน และปี 2571 มี 29 ก.พ. → งวดสุดท้ายของก้อน 1 ปีตก 30 พ.ย. 2571
        $this->assertSame(['1 ม.ค. 2571', '30 พ.ย. 2571'], [$rows[0]['first'], $rows[0]['last']]);
        $this->assertSame('1 ธ.ค. 2574', $rows[3]['last']);

        // กรอบราคาของสัญญาเติมพูล: 500 = ±5% · 0 ในสัญญา = ค่าปริยายของ LaunchpadFactory ±3%
        $this->assertSame('5', Launch::bandPercent());
        config(['idtc.launchpad.band_bps' => 0]);
        $this->assertSame('3', Launch::bandPercent());
        $this->assertEqualsWithDelta(220e9 / 12, $rows[0]['per_period'], 1.0);

        // ส่วนที่ระบุในการจัดสรรรวมกันได้ 1.1 ล้านล้านพอดี
        $sum = array_sum(array_map(fn ($a) => (float) $a['amount'], config('idtc.allocation')));
        $this->assertSame((float) config('idtc.token.total_supply'), $sum);
    }

    public function test_home_states_where_the_proceeds_go_and_when_the_sale_opens(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('1 ธ.ค. 2569 เวลา 10:00 น.')
            ->assertSee('29 ก.พ. 2571')
            ->assertDontSee('เข้ากระเป๋าโครงการโดยตรง')
            ->assertDontSee(':lp_unlock')
            ->assertDontSee(':start');
    }

    public function test_contract_table_lists_core_and_dex_contracts_and_hides_unset_pairs(): void
    {
        $keys = array_column(ContractList::rows(), 'key');
        $this->assertSame(['token', 'vesting', 'presale', 'feeder', 'usdt', 'wtpix', 'dex_factory', 'dex_router'], $keys);

        config(['idtc.contracts.pair_usdt' => '0x4444444444444444444444444444444444444444']);
        $this->assertContains('pair_usdt', array_column(ContractList::rows(), 'key'));
        $this->get('/transparency')->assertSee('พูล IDTC/USDT');
    }

    public function test_whitepaper_renders_headings_toc_and_contracts_table(): void
    {
        $html = $this->get('/whitepaper')
            ->assertOk()
            ->assertSee('IDTC Whitepaper ฉบับที่ 2')
            ->assertSee('<h2 id="section-1">1. บทสรุปสำหรับผู้บริหาร</h2>', false)
            ->assertSee('href="#section-10"', false)
            ->assertSee('ที่อยู่สัญญาทั้งหมด')
            ->assertSee('class="wp-table"', false)
            ->assertDontSee('{{contracts_table}}', false)
            ->assertSee(config('idtc.contracts.usdt'))
            ->assertSee('รอ deploy')
            ->getContent();

        // หัวเรื่อง # ไปอยู่บนปก ไม่ซ้ำเป็น h1 ในเนื้อหา
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame(11, substr_count($html, '<h2 id="section-'));
    }

    public function test_whitepaper_contracts_table_uses_live_addresses(): void
    {
        $this->deployAll();

        $this->get('/whitepaper')
            ->assertOk()
            ->assertSee(self::TOKEN)
            ->assertSee(Idtc::explorerAddress(self::VESTING), false)
            ->assertDontSee('{{contracts_table}}', false);
    }

    public function test_whitepaper_shows_placeholder_when_markdown_is_missing(): void
    {
        config(['idtc.whitepaper_path.th' => storage_path('framework/testing/missing-whitepaper.md')]);

        $this->get('/whitepaper')
            ->assertOk()
            ->assertSee('data-testid="whitepaper-missing"', false)
            ->assertSee('ยังไม่มีเนื้อหาไวท์เปเปอร์ในระบบ');
    }

    public function test_whitepaper_renderer_strips_raw_html(): void
    {
        $path = storage_path('framework/testing/wp-test.md');
        file_put_contents($path, "# T\n\n## 1. A\n\n<script>alert(1)</script>\n\n{{contracts_table}}\n");

        try {
            $doc = Whitepaper::render($path);
            $this->assertNotNull($doc);
            $this->assertSame('T', $doc['title']);
            $this->assertStringNotContainsString('<script>', $doc['html']);
            $this->assertStringNotContainsString('{{contracts_table}}', $doc['html']);
            $this->assertSame([['id' => 'section-1', 'num' => '1', 'text' => 'A']], $doc['toc']);
        } finally {
            @unlink($path);
        }
    }

    public function test_add_network_page_auto_adds_and_has_wallet_deep_links(): void
    {
        $site = preg_replace('#^https?://#', '', url('/add-network'));
        $enc = rawurlencode('https://'.$site);

        $this->get('/add-network')
            ->assertOk()
            ->assertSee('data-auto-add="1"', false)
            ->assertSee('4289 (0x10C1)')
            ->assertSee('https://metamask.app.link/dapp/'.$site, false)
            ->assertSee(e('https://link.trustwallet.com/open_url?coin_id=60&url='.$enc), false)
            ->assertSee(e('https://go.cb-w.com/dapp?cb_url='.$enc), false);

        // หน้าอื่นไม่เด้งเพิ่มเชนเอง
        $this->get('/presale')->assertDontSee('data-auto-add', false);
    }

    public function test_legacy_static_urls_redirect_permanently(): void
    {
        $this->get('/sale.html')->assertRedirect('/presale')->assertStatus(301);
        $this->get('/dashboard.html')->assertRedirect('/transparency')->assertStatus(301);
        $this->get('/whitepaper.html')->assertRedirect('/whitepaper')->assertStatus(301);
        $this->get('/index.html')->assertRedirect('/')->assertStatus(301);
    }

    public function test_public_config_never_contains_secrets(): void
    {
        $json = json_encode(Idtc::publicConfig());
        $this->assertStringNotContainsStringIgnoringCase('private', $json);
        $this->assertStringNotContainsString((string) config('app.key'), $json);
    }
}

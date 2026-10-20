<?php

namespace Tests\Feature;

use App\Support\Idtc;
use App\Support\Settings;
use App\Support\SiteLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * เว็บสาธารณะสองภาษา: ไทยที่ URL เดิม อังกฤษใต้ /en
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    /** หน้า => [URL ไทย, URL อังกฤษ] */
    private const PAGES = [
        'home' => ['/', '/en'],
        'presale' => ['/presale', '/en/presale'],
        'transparency' => ['/transparency', '/en/transparency'],
        'whitepaper' => ['/whitepaper', '/en/whitepaper'],
        'add-network' => ['/add-network', '/en/add-network'],
    ];

    private const THAI = '/[\x{0E00}-\x{0E7F}]/u';

    /** ข้อความรอบ ๆ อักษรไทยตัวแรกที่เจอ — ให้ข้อความ error บอกได้ทันทีว่าหลุดตรงไหน */
    private function thaiContext(string $html): string
    {
        if (! preg_match(self::THAI, $html, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        return substr($html, max(0, $m[0][1] - 120), 240);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'idtc.contracts.token' => null,
            'idtc.contracts.vesting' => null,
            'idtc.contracts.presale' => null,
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
            'idtc.contracts.token' => '0x1111111111111111111111111111111111111111',
            'idtc.contracts.vesting' => '0x2222222222222222222222222222222222222222',
            'idtc.contracts.presale' => '0x3333333333333333333333333333333333333333',
            'idtc.contracts.pair_usdt' => '0x4444444444444444444444444444444444444444',
        ]);
    }

    public function test_every_page_in_both_locales_has_lang_hreflang_canonical_and_og_locale(): void
    {
        foreach (self::PAGES as $page => [$th, $en]) {
            foreach (['th' => $th, 'en' => $en] as $locale => $uri) {
                $html = $this->get($uri)->assertOk()->getContent();

                $this->assertStringContainsString('<html lang="'.$locale.'">', $html, "$uri lang");
                $this->assertStringContainsString('<link rel="canonical" href="'.url($uri).'">', $html, "$uri canonical");
                $this->assertStringContainsString('<link rel="alternate" hreflang="th" href="'.url($th).'">', $html, "$uri hreflang th");
                $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.url($en).'">', $html, "$uri hreflang en");
                $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.url($th).'">', $html, "$uri x-default");
                $this->assertStringContainsString('<meta property="og:url" content="'.url($uri).'">', $html, "$uri og:url");
                $this->assertStringContainsString('<meta property="og:locale" content="'.($locale === 'th' ? 'th_TH' : 'en_US').'">', $html, "$uri og:locale");
                $this->assertStringContainsString('<meta property="og:locale:alternate" content="'.($locale === 'th' ? 'en_US' : 'th_TH').'">', $html, "$uri og:locale:alternate");
            }
        }
    }

    public function test_canonical_ignores_query_strings(): void
    {
        $this->get('/en/add-network?auto=1')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/en/add-network').'">', false);
    }

    public function test_switcher_links_point_to_the_same_page_in_the_other_locale(): void
    {
        foreach (self::PAGES as $page => [$th, $en]) {
            foreach ([$th, $en] as $uri) {
                $html = $this->get($uri)->getContent();
                $this->assertSame(1, preg_match('/<div class="lang-switch"[^>]*data-testid="lang-switch">(.*?)<\/div>/s', $html, $m), "$uri switcher");
                $this->assertStringContainsString('href="'.url($th).'" hreflang="th"', $m[1], "$uri → TH");
                $this->assertStringContainsString('href="'.url($en).'" hreflang="en"', $m[1], "$uri → EN");
                // ภาษาปัจจุบันถูกทำเครื่องหมาย
                $current = str_starts_with($uri, '/en') ? 'en' : 'th';
                $this->assertMatchesRegularExpression('/hreflang="'.$current.'"[^>]*class="is-on"[^>]*aria-current="true"/', $m[1]);
            }
        }
    }

    public function test_navigation_links_stay_in_the_current_locale(): void
    {
        $html = $this->get('/en/presale')->getContent();
        foreach (['/en', '/en/transparency', '/en/whitepaper', '/en/add-network'] as $uri) {
            $this->assertStringContainsString('href="'.url($uri).'"', $html);
        }
        $this->assertStringContainsString('href="'.url('/en').'#tokenomics"', $html);
        $this->assertStringNotContainsString('href="'.url('/transparency').'">Transparency', $html);

        $this->get('/presale')->assertSee('href="'.url('/transparency').'"', false);
    }

    public function test_english_pages_render_english_and_contain_no_thai(): void
    {
        foreach (self::PAGES as $page => [, $en]) {
            $html = $this->get($en)->assertOk()->getContent();
            $this->assertSame(0, preg_match(self::THAI, $html, $hit), "$en contains Thai: ".$this->thaiContext($html));
            // รวมข้อความที่ส่งให้ JS ด้วย (@json เขียนอักษรไทยเป็น \u0e..)
            $this->assertStringNotContainsStringIgnoringCase('\u0e', $html, "$en has Thai inside JSON");
        }
    }

    public function test_english_pages_contain_no_thai_when_contracts_are_deployed(): void
    {
        $this->deployAll();
        foreach (self::PAGES as $page => [, $en]) {
            $html = $this->get($en)->assertOk()->getContent();
            $this->assertSame(0, preg_match(self::THAI, $html, $hit), "$en (deployed) contains Thai: ".$this->thaiContext($html));
        }
    }

    public function test_thai_pages_keep_thai_copy(): void
    {
        $this->get('/')->assertSee('ลงทุนในการพัฒนาพลังงาน')->assertSee('พากย์ภาษาอังกฤษ ซับไทย')->assertSee('© '.(now()->year + 543), false);
        $this->get('/en')->assertSee('Invest in energy development')->assertSee('© '.now()->year, false);
    }

    public function test_english_video_section_states_narration_and_subtitles(): void
    {
        $this->get('/en')
            ->assertOk()
            ->assertSee('English narration · Thai subtitles')
            ->assertSee('The presenter in this video is AI-generated');
    }

    public function test_english_risk_warning_keeps_the_thai_legal_reference(): void
    {
        $this->get('/en')
            ->assertSee('no return is guaranteed')
            ->assertSee('Emergency Decree on Digital Asset Businesses B.E. 2561 (2018)');
    }

    public function test_presale_shows_awaiting_deployment_in_both_languages(): void
    {
        $this->get('/presale')
            ->assertSee('data-presale-state="pending-deploy"', false)
            ->assertSee('data-testid="presale-pending"', false)
            ->assertSee('รอ deploy')
            ->assertSee('100,000 ล้าน');

        $this->get('/en/presale')
            ->assertOk()
            ->assertSee('data-presale-state="pending-deploy"', false)
            ->assertSee('data-testid="presale-pending"', false)
            ->assertSee('Awaiting deployment')
            ->assertSee('The presale contract is not on-chain yet')
            ->assertSee('666,666.67')
            ->assertSee('66,666,666.66')
            ->assertSee('100 billion')
            ->assertSee('Total sales capped at 100 billion IDTC')
            ->assertSee(config('idtc.contracts.usdt'));
    }

    public function test_presale_live_state_in_english(): void
    {
        $this->deployAll();

        $this->get('/en/presale')
            ->assertSee('data-presale-state="live"', false)
            ->assertDontSee('data-testid="presale-pending"', false)
            ->assertSee('Reading contract…');
    }

    public function test_transparency_shows_the_plan_in_both_languages(): void
    {
        $this->get('/transparency')
            ->assertSee('data-vesting-state="pending-deploy"', false)
            ->assertSee('สัญญาล็อกยังไม่ขึ้นเชน — ตัวเลขด้านล่างคือแผน')
            ->assertSee('ล็อก 1 ปี');

        $html = $this->get('/en/transparency')
            ->assertOk()
            ->assertSee('data-vesting-state="pending-deploy"', false)
            ->assertSee('The lock contract is not on-chain yet — the figures below are the plan')
            ->assertSee('1-year lock')
            ->assertSee('4-year lock')
            ->assertSee('770')
            ->assertSee('billion IDTC')
            ->assertSee('18,333,333,333.33 IDTC per unlock')
            ->assertSee('Awaiting deployment')
            ->assertSee('Locks 770 billion IDTC in 4 tranches · ownerless')
            ->getContent();

        $this->assertSame(4, substr_count($html, 'class="timeline"'));
        $this->assertSame(12 + 24 + 36 + 48, substr_count($html, '<i class="lock"></i>'));
    }

    public function test_transparency_live_state_in_english(): void
    {
        $this->deployAll();

        $this->get('/en/transparency')
            ->assertSee('data-vesting-state="live"', false)
            ->assertSee('Release due unlocks')
            ->assertSee('IDTC/USDT pool')
            ->assertDontSee('The lock contract is not on-chain yet');
    }

    public function test_english_whitepaper_renders_and_replaces_the_placeholder(): void
    {
        $html = $this->get('/en/whitepaper')
            ->assertOk()
            ->assertSee('IDTC Whitepaper, Version 2')
            ->assertSee('<h2 id="section-1">1. Executive summary</h2>', false)
            ->assertSee('<h2 id="section-11">11. All contract addresses</h2>', false)
            ->assertSee('href="#section-11"', false)
            ->assertSee('World Energy Investment 2025')
            ->assertSee('Emergency Decree on Digital Asset Businesses B.E. 2561 (2018)')
            ->assertSee('Address on TPIX Chain')
            ->assertSee('Awaiting deployment')
            ->assertSee('<article class="wp-body" lang="en">', false)
            ->assertDontSee('{{contracts_table}}', false)
            ->assertSee(config('idtc.contracts.usdt'))
            ->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame(12, substr_count($html, '<h2 id="section-'));

        $this->get('/whitepaper')->assertSee('<article class="wp-body" lang="th">', false)->assertSee('1. บทสรุปสำหรับผู้บริหาร');
    }

    public function test_english_whitepaper_shows_placeholder_when_its_file_is_missing(): void
    {
        config(['idtc.whitepaper_path.en' => storage_path('framework/testing/missing-whitepaper.en.md')]);

        $this->get('/en/whitepaper')
            ->assertOk()
            ->assertSee('data-testid="whitepaper-missing"', false)
            ->assertSee('No whitepaper content is available yet');
        // หน้าไทยไม่กระทบ
        $this->get('/whitepaper')->assertDontSee('data-testid="whitepaper-missing"', false);
    }

    public function test_english_whitepaper_uses_live_contract_addresses(): void
    {
        $this->deployAll();

        $this->get('/en/whitepaper')
            ->assertSee('0x1111111111111111111111111111111111111111')
            ->assertSee(Idtc::explorerAddress('0x2222222222222222222222222222222222222222'), false)
            ->assertDontSee('{{contracts_table}}', false);
    }

    public function test_english_and_thai_whitepapers_have_matching_sections_and_figures(): void
    {
        $th = (string) file_get_contents(resource_path('content/whitepaper.th.md'));
        $en = (string) file_get_contents(resource_path('content/whitepaper.en.md'));

        $this->assertSame(substr_count($th, "\n## "), substr_count($en, "\n## "));
        $this->assertSame(substr_count($th, "\n|"), substr_count($en, "\n|"), 'table rows');
        $this->assertSame(1, substr_count($en, '{{contracts_table}}'));
        // ตัวเลขเต็มทุกตัว (คั่นหลัก/ทศนิยม) ในฉบับไทยต้องมีในฉบับอังกฤษ
        preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+\.\d+%?|\d+%/', $th, $nums);
        foreach (array_unique($nums[0]) as $n) {
            if (in_array($n, ['330,000', '550,000', '770,000', '935,000', '1,100,000', '18,333', '13,750', '450,000', '65,000'], true)) {
                continue; // ไทยเขียนเป็น "ล้าน" — อังกฤษแปลงเป็น billion (330 billion ฯลฯ) ตรวจแยกด้านล่าง
            }
            $this->assertStringContainsString($n, $en, "figure $n missing in English whitepaper");
        }
        foreach (['330 billion', '550 billion', '770 billion', '935 billion', '1.1 trillion', '18.33 billion', '13.75 billion', 'USD 450 billion', 'USD 65 billion'] as $n) {
            $this->assertStringContainsString($n, $en);
        }
    }

    public function test_og_image_is_localized(): void
    {
        $this->get('/presale')->assertSee('<meta property="og:image" content="'.asset('images/brand/og.jpg').'">', false);
        $this->get('/en/presale')->assertSee('<meta property="og:image" content="'.asset('images/brand/og-en.jpg').'">', false);
        $this->assertFileExists(public_path('images/brand/og-en.jpg'));
        [$w, $h] = getimagesize(public_path('images/brand/og-en.jpg'));
        $this->assertSame([1200, 630], [$w, $h]);
    }

    public function test_js_dictionary_is_injected_per_locale(): void
    {
        $this->get('/en/presale')
            ->assertSee('"locale":"en"', false)
            ->assertSee('"date_locale":"en-GB"', false)
            ->assertSee('"addNetwork":"'.str_replace('/', '\/', url('/en/add-network')).'"', false)
            ->assertSee('Awaiting deployment', false);

        $this->get('/presale')
            ->assertSee('"locale":"th"', false)
            ->assertSee('"date_locale":"th-TH"', false);
    }

    public function test_language_files_have_the_same_keys_in_both_locales(): void
    {
        $groups = array_map(fn ($f) => basename($f, '.php'), glob(lang_path('th/*.php')));
        $this->assertEqualsCanonicalizing($groups, array_map(fn ($f) => basename($f, '.php'), glob(lang_path('en/*.php'))));

        foreach ($groups as $group) {
            $th = array_keys(Arr::dot(require lang_path("th/$group.php")));
            $en = Arr::dot(require lang_path("en/$group.php"));
            $this->assertEqualsCanonicalizing($th, array_keys($en), "lang/*/$group.php keys differ");
            foreach ($en as $key => $value) {
                $this->assertSame(0, preg_match(self::THAI, (string) $value), "lang/en/$group.php $key has Thai");
            }
        }
    }

    public function test_hint_is_only_rendered_on_thai_pages(): void
    {
        $this->get('/')->assertSee('data-testid="lang-hint"', false)->assertSee('Switch to English?');
        $this->get('/en')->assertDontSee('data-testid="lang-hint"', false);
    }

    public function test_accept_language_never_redirects(): void
    {
        $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])->get('/')->assertOk()->assertSee('<html lang="th">', false);
        $this->withHeaders(['Accept-Language' => 'th-TH'])->get('/en')->assertOk()->assertSee('<html lang="en">', false);
    }

    public function test_explicit_english_choice_redirects_entry_to_english(): void
    {
        $this->withUnencryptedCookie(SiteLocale::COOKIE, 'en')->get('/presale?auto=1')
            ->assertStatus(302)
            ->assertRedirect(url('/en/presale').'?auto=1');
        $cache = (string) $this->withUnencryptedCookie(SiteLocale::COOKIE, 'en')->get('/presale')->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);

        $this->withUnencryptedCookie(SiteLocale::COOKIE, 'en')->get('/')->assertRedirect(url('/en'));
    }

    public function test_choice_cookie_does_not_redirect_internal_navigation_or_english_urls(): void
    {
        // กดลิงก์หน้าไทยจากในเว็บเอง = ตั้งใจดูภาษาไทย
        $this->withUnencryptedCookie(SiteLocale::COOKIE, 'en')
            ->withHeaders(['Referer' => url('/en/presale')])
            ->get('/presale')->assertOk()->assertSee('<html lang="th">', false);

        // URL ภาษาอังกฤษไม่ถูกพากลับไปหน้าไทย
        $this->withUnencryptedCookie(SiteLocale::COOKIE, 'th')->get('/en/presale')->assertOk();

        // ค่าคุกกี้แปลก ๆ ไม่มีผล
        $this->withUnencryptedCookie(SiteLocale::COOKIE, 'xx')->get('/')->assertOk();
    }

    public function test_thai_pages_vary_on_cookie(): void
    {
        $this->assertStringContainsString('Cookie', (string) $this->get('/')->headers->get('Vary'));
    }

    public function test_legacy_urls_still_redirect_and_english_pages_are_not_affected(): void
    {
        $this->get('/sale.html')->assertRedirect('/presale')->assertStatus(301);
        $this->get('/dashboard.html')->assertRedirect('/transparency')->assertStatus(301);
        $this->get('/whitepaper.html')->assertRedirect('/whitepaper')->assertStatus(301);
        $this->get('/index.html')->assertRedirect('/')->assertStatus(301);
        $this->get('/en/index.html')->assertNotFound();
    }

    public function test_english_add_network_deep_links_open_the_english_page(): void
    {
        $site = preg_replace('#^https?://#', '', url('/en/add-network'));

        $this->get('/en/add-network')
            ->assertOk()
            ->assertSee('data-auto-add="1"', false)
            ->assertSee('https://metamask.app.link/dapp/'.$site, false)
            ->assertSee('Network name');
    }

    public function test_announcement_is_shown_per_locale(): void
    {
        $settings = app(Settings::class);
        $settings->put('announcement', 'ประกาศภาษาไทย');
        $this->get('/')->assertSee('ประกาศภาษาไทย');
        // ไม่มีประกาศภาษาอังกฤษ = หน้าอังกฤษไม่แสดงแถบ (ไม่เอาข้อความไทยไปแสดง)
        $this->get('/en')->assertDontSee('ประกาศภาษาไทย')->assertDontSee('class="announce"', false);

        $settings->put('announcement_en', 'Round 1 opens soon');
        $this->get('/en')->assertSee('Round 1 opens soon')->assertDontSee('ประกาศภาษาไทย');
        $this->get('/')->assertDontSee('Round 1 opens soon');
    }

    public function test_large_amount_formatting_follows_the_locale(): void
    {
        app()->setLocale('th');
        $this->assertSame('100,000 ล้าน', SiteLocale::big('100000000000'));
        $this->assertSame('18,333', SiteLocale::bigValue(220000000000 / 12));

        app()->setLocale('en');
        $this->assertSame('100 billion', SiteLocale::big('100000000000'));
        $this->assertSame('18.33', SiteLocale::bigValue(220000000000 / 12));
        $this->assertSame('1,100', SiteLocale::bigValue('1100000000000'));
        $this->assertSame('13.75', SiteLocale::bigValue(165000000000 / 12));
    }
}

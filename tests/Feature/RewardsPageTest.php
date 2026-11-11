<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้า /rewards — แผนรางวัล IDTC Grid ที่อ่านได้โดยไม่ต้องเชื่อมกระเป๋า
 */
class RewardsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_page_is_public_and_shows_the_plan_numbers_from_config(): void
    {
        $html = $this->get('/en/rewards')->assertOk()->getContent();

        // วง 1..3 · ผู้ซื้อ · ระยะถือ · งบ — จาก config/idtc.php grid.plan
        $this->assertStringContainsString('5 · 3 · 1', $html);
        $this->assertStringContainsString('+1<small>%</small>', $html);
        $this->assertStringContainsString('14 <small>days</small>', $html);
        $this->assertStringContainsString('15 billion', $html);
        // โบนัสวง 1: สัปดาห์ 1–3 = 7% · 4–6 = 6% · หลังจากนั้น 5%
        $this->assertStringContainsString('Sale weeks 1–3', $html);
        $this->assertStringContainsString('From sale week 7', $html);
        $this->assertMatchesRegularExpression('/rw-weeks__rate">7%/', $html);
        $this->assertMatchesRegularExpression('/rw-weeks__rate">6%/', $html);
        // วันของสัปดาห์โบนัสคิดจากวันเริ่มขาย 1 ธ.ค. 2026
        $this->assertStringContainsString('1 Dec 2026 – 21 Dec 2026', $html);
        // ราคาขาย 1 USDT = 666,666.67 IDTC · ตัวอย่าง 100 USDT
        $this->assertStringContainsString('666,666.67', $html);
        $this->assertStringContainsString('66,666,667', $html);
        // ส่วนแบ่ง 15% ของก้อนดำเนินงาน 100B · เงินขายเข้าพูล 100% · ปลดสภาพคล่อง
        $this->assertStringContainsString('(15% of 100 billion)', $html);
        $this->assertStringContainsString('29 Feb 2028', $html);
        $this->assertStringContainsString('data-testid="rewards-risk"', $html);
        $this->assertStringContainsString('media/idtc-grid-plan-1080p.mp4', $html);
    }

    public function test_cta_follows_whether_grid_is_open(): void
    {
        app(Settings::class)->put('grid.enabled', false);
        $this->get('/rewards')->assertOk()
            ->assertSee('data-testid="rewards-soon"', false)
            ->assertDontSee('data-testid="rewards-open"', false);

        app(Settings::class)->put('grid.enabled', true);
        $this->get('/rewards')->assertOk()
            ->assertSee('data-testid="rewards-open"', false)
            ->assertDontSee('data-testid="rewards-soon"', false);
    }

    public function test_page_and_nav_link_exist_in_both_languages(): void
    {
        $this->get('/')->assertSee('href="'.url('/rewards').'"', false);
        $this->get('/en')->assertSee('href="'.url('/en/rewards').'"', false);
    }
}

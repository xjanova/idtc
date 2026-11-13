<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Public-site nav shows a link to the admin area only to a fully signed-in admin
 * whose wallet still has a role.
 */
class AdminNavLinkTest extends TestCase
{
    private const ADMIN = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['idtc.admin_wallets' => [self::ADMIN]]);
    }

    /** Only the site header (top bar + drawer): page bodies have their own add-network buttons. */
    private function header(string $html): string
    {
        $this->assertSame(1, preg_match('~<header class="nav">.*?</header>~s', $html, $m));

        return $m[0];
    }

    public function test_guest_does_not_see_admin_link(): void
    {
        $res = $this->get('/')->assertOk()
            ->assertDontSee('data-testid="nav-admin"', false)
            ->assertDontSee('data-testid="nav-admin-drawer"', false);

        $this->assertStringContainsString('@click="addNetwork()"', $this->header($res->getContent()));
    }

    public function test_logged_in_admin_sees_admin_link_in_bar_and_drawer(): void
    {
        $res = $this->withSession(['admin.address' => self::ADMIN])
            ->get('/')->assertOk()
            ->assertSee('data-testid="nav-admin"', false)
            ->assertSee('data-testid="nav-admin-drawer"', false)
            ->assertSee(route('admin.dashboard'), false)
            ->assertSee('หลังบ้าน');

        // the bar's "add chain" button gives way to the admin button; the drawer and the menu keep add-network
        $header = $this->header($res->getContent());
        $this->assertStringNotContainsString('@click="addNetwork()"', $header);
        $this->assertStringContainsString('addNetwork(); menu = false', $header);

        $this->withSession(['admin.address' => self::ADMIN])
            ->get('/en')->assertOk()->assertSee('>Admin<', false);
    }

    public function test_revoked_wallet_does_not_see_admin_link(): void
    {
        $this->withSession(['admin.address' => '0x2222222222222222222222222222222222222222'])
            ->get('/')->assertOk()->assertDontSee('data-testid="nav-admin"', false);
    }

    public function test_wallet_still_on_the_password_step_does_not_see_admin_link(): void
    {
        $this->withSession(['admin_pending' => ['address' => strtolower(self::ADMIN), 'step' => 'enter', 'at' => now()->timestamp]])
            ->get('/')->assertOk()->assertDontSee('data-testid="nav-admin"', false);
    }
}

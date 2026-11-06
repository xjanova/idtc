<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Services\Admin\ChainActions;
use App\Services\Chain\Abi;
use App\Services\Chain\FeederReader;
use App\Services\Chain\RpcClient;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * ขายผ่าน TPIX Launchpad: สัญญาเติมพูล (PoolFeeder) เป็นเจ้าของถาวรของ presale
 * คำสั่งเจ้าของของ presale ต้องสั่งผ่านสัญญาเติมพูล — สั่งที่ presale ตรงจะ revert
 */
class FeederActionsTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private string $presale = '0x9999999999999999999999999999999999999999';

    private string $feeder = '0x8888888888888888888888888888888888888888';

    private string $token = '0x2222222222222222222222222222222222222222';

    private string $pair = '0x6666666666666666666666666666666666666666';

    private string $feederOwner = '0x3333333333333333333333333333333333333333';

    /** เจ้าของ presale บนเชน (ปกติ = สัญญาเติมพูล) */
    private string $saleOwner;

    private int $lpUnlock = 4_100_000_000;

    /** @var array<string, array<string, mixed>|null> */
    private array $txs = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        $this->saleOwner = $this->feeder;
        config(['idtc.contracts.presale' => $this->presale, 'idtc.contracts.token' => $this->token, 'idtc.contracts.feeder' => $this->feeder]);
        $this->fakeRpc(fn (string $m, array $p) => $this->rpc($m, $p));
    }

    private function word(string|int $v): string
    {
        return '0x'.Abi::encodeUint($v);
    }

    private function addr(string $a): string
    {
        return '0x'.Abi::encodeAddress($a);
    }

    private function rpc(string $method, array $params): mixed
    {
        if ($method === 'eth_blockNumber') {
            return '0x64';
        }
        if ($method === 'eth_getTransactionByHash') {
            return $this->txs[$params[0]] ?? null;
        }
        if ($method === 'eth_getTransactionReceipt') {
            return $this->receipts[$params[0]] ?? null;
        }
        if ($method !== 'eth_call') {
            return ['__error' => 'not faked'];
        }
        $to = strtolower($params[0]['to']);
        $sel = substr($params[0]['data'], 0, 10);
        $zero = '0x0000000000000000000000000000000000000000';
        $maps = [
            $this->presale => [
                'saleToken()' => $this->addr($this->token),
                'payToken()' => $this->addr((string) config('idtc.contracts.usdt')),
                'rateNumerator()' => $this->word('2000000000000000000'),
                'rateDenominator()' => $this->word(3),
                'saleCap()' => $this->word('100000000000000000000000000000'),
                'sold()' => $this->word('25000000000000000000000000000'),
                'raised()' => $this->word('37500000000'),
                'startTime()' => $this->word(1_790_000_000),
                'endTime()' => $this->word(4_000_000_000),
                'minPurchase()' => $this->word(1_000_000),
                'maxPerWallet()' => $this->word(0),
                'paused()' => $this->word(0),
                'treasury()' => $this->addr($this->saleOwner),
                'owner()' => $this->addr($this->saleOwner),
                'pendingOwner()' => $this->addr($zero),
            ],
            $this->feeder => [
                'owner()' => $this->addr($this->feederOwner),
                'pendingOwner()' => $this->addr($zero),
                'sale()' => $this->addr($this->presale),
                'projectTreasury()' => $this->addr('0x4444444444444444444444444444444444444444'),
                'pair()' => $this->addr($this->pair),
                'lpUnlockTime()' => $this->word($this->lpUnlock),
                'saleEndLimit()' => $this->word(4_000_000_000),
                'poolShareBps()' => $this->word(10000),
                'bandBps()' => $this->word(500),
                'minFeed()' => $this->word(1_000_000),
                'pendingPool()' => $this->word(0),
                'pendingQuote()' => $this->word(2_500_000),
                'tokenReserve()' => $this->word('99000000000000000000000000000'),
                'poolVsSaleBps()' => $this->word(10100),
                'totalQuoteToPool()' => $this->word(10_000_000),
                'totalTokenToPool()' => $this->word('6666666666666666666666666'),
                'totalQuoteToProject()' => $this->word(0),
            ],
        ];
        if ($sel === Abi::selector('balanceOf(address)')) {
            return match ($to) {
                strtolower($this->token) => $this->word('75000000000000000000000000000'),
                strtolower($this->pair) => $this->word('2581988897471611'),
                default => ['__error' => 'execution reverted'],
            };
        }
        foreach ($maps[$to] ?? [] as $sig => $value) {
            if ($sel === Abi::selector($sig)) {
                return $value;
            }
        }

        return ['__error' => 'execution reverted'];
    }

    public function test_feeder_reader_decodes_contract_state(): void
    {
        $fd = app(FeederReader::class)->read();

        $this->assertTrue($fd['ok']);
        $this->assertTrue($fd['bound']);
        $this->assertFalse($fd['unlocked']);
        $this->assertSame($this->lpUnlock, $fd['lp_unlock']);
        $this->assertSame(4_000_000_000, $fd['sale_end_limit']);
        $this->assertSame(['state' => 'in', 'percent' => 101.0], $fd['band']);
        $this->assertSame('2581988897471611', $fd['lp_balance']);
        $this->assertSame(strtolower($this->feederOwner), $fd['v']['owner']);
    }

    public function test_band_states(): void
    {
        $reader = new class(app(RpcClient::class)) extends FeederReader
        {
            public function bandOf(?string $vs, ?string $band): array
            {
                return $this->band($vs, $band);
            }
        };
        $this->assertSame('empty', $reader->bandOf('0', '500')['state']);
        $this->assertSame('in', $reader->bandOf('9500', '500')['state']);
        $this->assertSame('in', $reader->bandOf('10500', '500')['state']);
        $this->assertSame('out', $reader->bandOf('9499', '500')['state']);
        $this->assertSame('out', $reader->bandOf('10501', '500')['state']);
        $this->assertSame('unknown', $reader->bandOf(null, '500')['state']);
    }

    public function test_presale_page_routes_owner_actions_through_feeder(): void
    {
        $this->loginAsOwner()->get('/admin/presale')->assertOk()
            ->assertSee('สั่งผ่านสัญญาเติมพูล')
            ->assertSee("chainAction('feeder.pauseSale'", false)
            ->assertSee("chainAction('feeder.setSaleLimits'", false)
            ->assertSee("chainAction('feeder.setSaleWindow'", false)
            ->assertSee("chainAction('feeder.withdrawUnsold'", false)
            ->assertSee("chainAction('feeder.feed'", false)
            ->assertSee("chainAction('feeder.transferOwnership'", false)
            // presale ใต้ launchpad: เปลี่ยนกระเป๋ารับเงินและโอนเจ้าของไม่ได้ — ไม่มีปุ่มที่จะ revert
            ->assertDontSee("chainAction('presale.", false)
            ->assertSee('ล็อกถาวร')
            // ก่อนวันปลดล็อก LP: ไม่มีฟอร์มถอน
            ->assertDontSee('feeder.withdrawLiquidity', false)
            ->assertSee('ถอนได้หลังปลดล็อก LP')
            ->assertSee('อยู่ในกรอบ')
            ->assertSee('±5%');
    }

    public function test_withdraw_forms_appear_after_lp_unlock(): void
    {
        $this->lpUnlock = 1_700_000_000;
        $this->loginAsOwner()->get('/admin/presale')->assertOk()
            ->assertSee("chainAction('feeder.withdrawLiquidity'", false)
            ->assertSee("chainAction('feeder.withdrawLeftoverQuote'", false)
            ->assertSee("chainAction('feeder.withdrawReserve'", false)
            ->assertSee('ปลดแล้ว')
            // ตัวแปรในลูปฟอร์มถอนต้องไม่ทับ $title ของ layout
            ->assertSee('<title>Presale', false)
            ->assertDontSee('ถอนได้หลังปลดล็อก LP');
    }

    public function test_feeder_that_does_not_own_the_presale_is_flagged(): void
    {
        $this->saleOwner = $this->feederOwner;
        $this->loginAsOwner()->get('/admin/presale')->assertOk()
            ->assertSee('ไม่ใช่เจ้าของ presale ตัวนี้')
            ->assertSee("chainAction('presale.setTreasury'", false)
            ->assertDontSee("chainAction('feeder.pauseSale'", false);
    }

    public function test_launchpad_sale_without_feeder_setting_shows_a_hint(): void
    {
        config(['idtc.contracts.feeder' => null]);
        $this->loginAsOwner()->get('/admin/presale')->assertOk()
            ->assertSee('ถ้าเปิดขายผ่าน TPIX Launchpad')
            ->assertSee("chainAction('presale.pause'", false)
            ->assertDontSee('id="feeder"', false);
    }

    public function test_viewer_sees_feeder_state_without_actions(): void
    {
        $this->loginAs('0x5555555555555555555555555555555555555555', 'viewer')->get('/admin/presale')->assertOk()
            ->assertSee('สัญญาเติมพูล')
            ->assertSee('ดูได้อย่างเดียว')
            ->assertDontSee("chainAction('feeder.", false);
    }

    public function test_feeder_tx_is_recorded_against_the_feeder_and_confirmed(): void
    {
        $hash = '0x'.str_repeat('ab', 32);
        $id = $this->loginAsOwner()->postJson('/admin/tx', [
            'action' => 'feeder.setSaleWindow',
            'tx_hash' => $hash,
            'params' => ['startTime' => '1790000000', 'endTime' => '3999999999'],
            'from' => $this->feederOwner,
        ])->assertCreated()->json('id');

        $this->txs[$hash] = ['to' => $this->feeder, 'from' => $this->feederOwner, 'input' => Abi::encodeCall('setSaleWindow(uint64,uint64)', ['1790000000', '3999999999'])];
        $this->receipts[$hash] = ['status' => '0x1', 'from' => $this->feederOwner, 'blockNumber' => '0x63'];
        $this->loginAsOwner()->postJson("/admin/tx/{$id}/check")->assertOk()->assertJson(['status' => 'confirmed']);

        $log = AuditLog::query()->findOrFail($id);
        $this->assertSame(strtolower($this->feeder), $log->details['contract']);
        $this->assertSame('ตั้งช่วงเวลาขาย (ผ่านสัญญาเติมพูล)', $log->label());
    }

    public function test_feeder_action_sent_to_the_presale_is_failed(): void
    {
        $hash = '0x'.str_repeat('cd', 32);
        $id = $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'feeder.pauseSale', 'tx_hash' => $hash])->json('id');
        $this->txs[$hash] = ['to' => $this->presale, 'input' => Abi::selector('pauseSale()')];
        $this->receipts[$hash] = ['status' => '0x1'];
        $this->loginAsOwner()->postJson("/admin/tx/{$id}/check")->assertJson(['status' => 'failed']);
    }

    public function test_feeder_action_without_feeder_address_is_rejected(): void
    {
        config(['idtc.contracts.feeder' => null]);
        $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'feeder.feed', 'tx_hash' => '0x'.str_repeat('a', 64)])->assertStatus(422);
        $this->assertSame(0, AuditLog::query()->whereNotNull('tx_hash')->count());
    }

    /** รายการคำสั่งฝั่ง PHP กับ ACTIONS ใน resources/js/admin-chain.js ต้องตรงกันทั้งสองทาง */
    public function test_php_and_js_action_lists_match(): void
    {
        $js = (string) file_get_contents(resource_path('js/admin-chain.js'));
        preg_match_all("/^ {4}'([a-z]+\.[A-Za-z]+)': \{/m", $js, $m);
        // คำสั่งถอนหลังปลดล็อกสร้างจากตาราง ['withdrawLiquidity', ...] → feeder.<fn>
        preg_match_all("/^ {8}\['(withdraw[A-Za-z]+)', /m", $js, $gen);
        $jsKeys = array_merge($m[1], array_map(fn ($fn) => "feeder.$fn", $gen[1]));
        sort($jsKeys);

        $phpKeys = array_keys(ChainActions::ALL);
        sort($phpKeys);
        $this->assertSame($phpKeys, $jsKeys);

        foreach (ChainActions::ALL as $key => $spec) {
            $this->assertContains($spec['contract'], Settings::CONTRACT_KEYS, $key);
            $this->assertStringStartsWith($spec['contract'].'.', $key);
            $this->assertMatchesRegularExpression('/^0x[0-9a-f]{8}$/', Abi::selector($spec['method']), $key);
        }
    }
}

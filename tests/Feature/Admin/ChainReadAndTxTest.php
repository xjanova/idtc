<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Services\Chain\Abi;
use App\Services\Chain\PresaleReader;
use App\Services\Chain\VestingReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class ChainReadAndTxTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private string $presale = '0x9999999999999999999999999999999999999999';

    private string $token = '0x2222222222222222222222222222222222222222';

    private string $owner = '0x3333333333333333333333333333333333333333';

    private bool $paused = false;

    /** @var array<string, array<string, mixed>|null> */
    private array $txs = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        config(['idtc.contracts.presale' => $this->presale, 'idtc.contracts.token' => $this->token]);
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
        $cap = '100000000000000000000000000000'; // 100B × 1e18
        $sold = '25000000000000000000000000000';  // 25B
        $map = [
            'saleToken()' => $this->addr($this->token),
            'payToken()' => $this->addr((string) config('idtc.contracts.usdt')),
            'rateNumerator()' => $this->word('2000000000000000000'),
            'rateDenominator()' => $this->word(3),
            'saleCap()' => $this->word($cap),
            'sold()' => $this->word($sold),
            'raised()' => $this->word('37500000000'), // 37,500 USDT
            'startTime()' => $this->word(1_790_000_000),
            'endTime()' => $this->word(4_000_000_000),
            'minPurchase()' => $this->word(1_000_000),
            'maxPerWallet()' => $this->word(0),
            'paused()' => $this->word($this->paused ? 1 : 0),
            'treasury()' => $this->addr('0x4444444444444444444444444444444444444444'),
            'owner()' => $this->addr($this->owner),
            'pendingOwner()' => $this->addr('0x0000000000000000000000000000000000000000'),
        ];
        if ($to === strtolower($this->token) && $sel === Abi::selector('balanceOf(address)')) {
            return $this->word('75000000000000000000000000000'); // 75B = ที่เหลือพอดี
        }
        foreach ($map as $sig => $value) {
            if ($to === strtolower($this->presale) && $sel === Abi::selector($sig)) {
                return $value;
            }
        }

        return ['__error' => 'execution reverted'];
    }

    public function test_presale_reader_decodes_contract_state(): void
    {
        $p = app(PresaleReader::class)->read();

        $this->assertTrue($p['ok']);
        $this->assertSame('live', $p['status']);
        $this->assertSame('75000000000000000000000000000', $p['remaining']);
        $this->assertTrue($p['funded']);
        $this->assertFalse($p['can_withdraw']);
        $this->assertSame(['rate' => true, 'cap' => true, 'pay_token' => true], $p['plan']);
        $this->assertSame(strtolower($this->owner), $p['v']['owner']);
        $this->assertSame('1000000', $p['v']['minPurchase']);
    }

    public function test_presale_page_shows_on_chain_values_and_actions(): void
    {
        $this->loginAsOwner()->get('/admin/presale')->assertOk()
            ->assertSee('เปิดขาย')
            ->assertSee('37,500')
            ->assertSee('presale.setLimits', false)
            ->assertSee('ตรงแผน');
    }

    public function test_viewer_sees_presale_without_action_forms(): void
    {
        $this->loginAs('0x5555555555555555555555555555555555555555', 'viewer')->get('/admin/presale')->assertOk()
            ->assertSee('ดูได้อย่างเดียว')
            ->assertDontSee('presale.setLimits', false);
    }

    public function test_pages_survive_rpc_timeout(): void
    {
        $this->failRpcConnection();
        foreach (['/admin', '/admin/presale', '/admin/vesting', '/admin/pools', '/admin/wallets', '/admin/settings'] as $page) {
            $this->loginAsOwner()->get($page)->assertOk();
        }
        $this->loginAsOwner()->get('/admin/presale')->assertSee('ไม่ทันเวลา');
    }

    public function test_rpc_outage_fails_fast_after_first_timeout(): void
    {
        $this->failRpcConnection();
        $first = app(PresaleReader::class)->read();
        $sent = count(Http::recorded());
        $this->assertFalse($first['ok']);
        $this->assertStringContainsString('ไม่ทันเวลา', $first['error']);

        // คำขอถัดไปภายใน 20 วินาทีไม่ยิง HTTP ซ้ำ — ตอบข้อความเดิมทันที
        app(VestingReader::class)->read();
        config(['idtc.contracts.vesting' => '0x7777777777777777777777777777777777777777']);
        $v = app(VestingReader::class)->read();
        $this->assertSame($sent, count(Http::recorded()));
        $this->assertStringContainsString('ไม่ทันเวลา', $v['error']);
    }

    public function test_tx_is_recorded_pending_then_confirmed_from_chain(): void
    {
        $hash = '0x'.str_repeat('ab', 32);
        $res = $this->loginAsOwner()->postJson('/admin/tx', [
            'action' => 'presale.setLimits',
            'tx_hash' => $hash,
            'params' => ['minPurchase' => '1000000', 'maxPerWallet' => '0'],
            'from' => $this->owner,
        ])->assertCreated()->assertJson(['status' => 'pending']);
        $id = $res->json('id');

        // ส่งซ้ำ = คืนรายการเดิม
        $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'presale.setLimits', 'tx_hash' => $hash, 'params' => ['minPurchase' => '1000000', 'maxPerWallet' => '0']])
            ->assertOk()->assertJson(['id' => $id]);
        $this->assertSame(1, AuditLog::query()->where('tx_hash', $hash)->count());

        // ยังไม่ลงบล็อก
        $this->txs[$hash] = ['to' => $this->presale, 'from' => $this->owner, 'input' => Abi::encodeCall('setLimits(uint256,uint256)', ['1000000', '0'])];
        $this->loginAsOwner()->postJson("/admin/tx/{$id}/check")->assertOk()->assertJson(['status' => 'pending']);

        $this->receipts[$hash] = ['status' => '0x1', 'from' => $this->owner, 'blockNumber' => '0x63'];
        $this->loginAsOwner()->postJson("/admin/tx/{$id}/check")->assertOk()->assertJson(['status' => 'confirmed']);
        $log = AuditLog::query()->findOrFail($id);
        $this->assertSame(['minPurchase' => '1000000', 'maxPerWallet' => '0'], $log->details['params']);
        $this->assertSame(strtolower($this->presale), $log->details['contract']);
    }

    public function test_reverted_tx_is_marked_failed(): void
    {
        $hash = '0x'.str_repeat('cd', 32);
        $id = $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'presale.pause', 'tx_hash' => $hash])->json('id');
        $this->txs[$hash] = ['to' => $this->presale, 'input' => Abi::selector('pause()')];
        $this->receipts[$hash] = ['status' => '0x0'];
        $this->loginAsOwner()->postJson("/admin/tx/{$id}/check")->assertJson(['status' => 'failed']);
    }

    public function test_tx_to_wrong_contract_or_function_is_marked_failed(): void
    {
        $hash = '0x'.str_repeat('ef', 32);
        $id = $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'presale.pause', 'tx_hash' => $hash])->json('id');
        $this->txs[$hash] = ['to' => $this->presale, 'input' => Abi::selector('unpause()')];
        $this->receipts[$hash] = ['status' => '0x1'];
        $this->loginAsOwner()->postJson("/admin/tx/{$id}/check")->assertJson(['status' => 'failed']);
    }

    public function test_tx_never_seen_on_chain_is_failed_after_two_hours(): void
    {
        $hash = '0x'.str_repeat('12', 32);
        $id = $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'presale.unpause', 'tx_hash' => $hash])->json('id');
        $this->loginAsOwner()->postJson("/admin/tx/{$id}/check")->assertJson(['status' => 'pending']);

        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('idtc:index-sales');
        $this->assertSame(AuditLog::FAILED, AuditLog::query()->findOrFail($id)->tx_status);
        Carbon::setTestNow();
    }

    public function test_tx_endpoint_rejects_bad_input(): void
    {
        $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'presale.mint', 'tx_hash' => '0x'.str_repeat('a', 64)])->assertStatus(422);
        $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'presale.pause', 'tx_hash' => '0x1234'])->assertStatus(422);
        $this->loginAsOwner()->postJson('/admin/tx', ['action' => 'presale.setTreasury', 'tx_hash' => '0x'.str_repeat('a', 64), 'params' => ['treasury' => 'nope']])->assertStatus(422);
        $this->assertSame(0, AuditLog::query()->whereNotNull('tx_hash')->count());
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\IndexerState;
use App\Models\Purchase;
use App\Services\Chain\Abi;
use App\Services\Chain\SalesIndexer;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

class SalesIndexerTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private string $presale = '0x9999999999999999999999999999999999999999';

    private string $buyerA = '0x399679922b673FEE28c507B07FF12e17aBFD78a8';

    private string $buyerB = '0xAbCdEf0123456789abcdef0123456789ABCDEF01';

    private int $head = 10000;

    /** @var list<array{0: int, 1: int}> ช่วงบล็อกที่ถูกขอ eth_getLogs */
    private array $ranges = [];

    private bool $failLogs = false;

    private ?int $maxRange = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['idtc.contracts.presale' => $this->presale]);
        app(Settings::class)->put('indexer.start_block', 8000);
        $this->fakeRpc(fn (string $m, array $p) => $this->rpc($m, $p));
    }

    private function log(string $buyer, string $pay, string $out, int $block, int $index, string $txByte, ?string $address = null, bool $removed = false): array
    {
        return [
            'address' => $address ?? $this->presale,
            'topics' => [SalesIndexer::topic(), '0x'.str_pad(strtolower(substr($buyer, 2)), 64, '0', STR_PAD_LEFT)],
            'data' => '0x'.Abi::encodeUint($pay).Abi::encodeUint($out),
            'blockNumber' => '0x'.dechex($block),
            'transactionHash' => '0x'.str_repeat($txByte, 64),
            'logIndex' => '0x'.dechex($index),
            'removed' => $removed,
        ];
    }

    private function rpc(string $method, array $params): mixed
    {
        switch ($method) {
            case 'eth_blockNumber':
                return '0x'.dechex($this->head);
            case 'eth_getBlockByNumber':
                return ['number' => $params[0], 'timestamp' => '0x'.dechex(1_791_680_000 + hexdec($params[0]))];
            case 'eth_getLogs':
                $from = hexdec($params[0]['fromBlock']);
                $to = hexdec($params[0]['toBlock']);
                $this->ranges[] = [$from, $to];
                if ($this->failLogs) {
                    return ['__error' => 'upstream unavailable'];
                }
                if ($this->maxRange !== null && $to - $from + 1 > $this->maxRange) {
                    return ['__error' => 'block range too large, max '.$this->maxRange];
                }
                $all = [
                    $this->log($this->buyerA, '2500000', '1666666666666666666666666', 9000, 3, 'a'),
                    $this->log($this->buyerB, '1000000000', '666666666666666666666666666', 9500, 0, 'b'),
                    $this->log($this->buyerB, '5', '5', 9600, 1, 'c', '0x1212121212121212121212121212121212121212'), // สัญญาอื่น
                    $this->log($this->buyerB, '7', '7', 9700, 2, 'd', null, true),                                     // ถูก reorg ทิ้ง
                ];

                return array_values(array_filter($all, fn ($l) => hexdec($l['blockNumber']) >= $from && hexdec($l['blockNumber']) <= $to));
            default:
                return ['__error' => 'not faked: '.$method];
        }
    }

    public function test_indexes_purchases_and_decodes_values(): void
    {
        $this->artisan('idtc:index-sales')->assertSuccessful();

        $this->assertSame(2, Purchase::query()->count());
        $a = Purchase::query()->where('tx_hash', '0x'.str_repeat('a', 64))->firstOrFail();
        $this->assertSame(strtolower($this->buyerA), $a->buyer);
        $this->assertSame('2500000', $a->pay_amount);
        $this->assertSame('1666666666666666666666666', $a->tokens_out);
        $this->assertSame(2.5, $a->pay_usdt);
        $this->assertEqualsWithDelta(1666666.6667, $a->tokens_idtc, 0.001);
        $this->assertSame(9000, $a->block_number);
        $this->assertSame(3, $a->log_index);
        $this->assertSame(1_791_680_000 + 9000, $a->block_time->timestamp);
        $this->assertSame(strtolower($this->presale), $a->contract);

        $state = IndexerState::query()->findOrFail('sales');
        $this->assertSame($this->head, $state->last_block);
        $this->assertNull($state->last_error);
        $this->assertSame([[8000, 10000]], $this->ranges);
    }

    public function test_is_idempotent_across_runs_and_restarts(): void
    {
        $indexer = app(SalesIndexer::class);
        $this->assertSame(2, $indexer->run()['inserted']);
        $this->assertSame(0, $indexer->run()['inserted']); // ไม่มีบล็อกใหม่
        $indexer->restartFrom(8000);                         // ดึงช่วงเดิมซ้ำ
        $this->assertSame(0, $indexer->run()['inserted']);
        $this->assertSame(2, Purchase::query()->count());
    }

    public function test_walks_large_ranges_in_5000_block_chunks(): void
    {
        $this->head = 20000;
        app(SalesIndexer::class)->run();
        $this->assertSame([[8000, 12999], [13000, 17999], [18000, 20000]], $this->ranges);
    }

    public function test_halves_the_range_when_node_says_too_large(): void
    {
        $this->maxRange = 1500;
        $result = app(SalesIndexer::class)->run();
        $this->assertTrue($result['ok']);
        $this->assertSame(2, Purchase::query()->count());
        $this->assertSame(10000, IndexerState::query()->findOrFail('sales')->last_block);
    }

    public function test_rpc_error_is_recorded_and_next_run_resumes(): void
    {
        $this->failLogs = true;
        $result = app(SalesIndexer::class)->run();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('upstream unavailable', $result['message']);
        $state = IndexerState::query()->findOrFail('sales');
        $this->assertNull($state->last_block);
        $this->assertNotNull($state->last_error);
        $this->assertSame(0, Purchase::query()->count());

        $this->failLogs = false;
        $this->assertTrue(app(SalesIndexer::class)->run()['ok']);
        $this->assertSame(2, Purchase::query()->count());
        $this->assertNull(IndexerState::query()->findOrFail('sales')->last_error);
    }

    public function test_connection_timeout_does_not_crash_the_command(): void
    {
        $this->failRpcConnection();
        $this->artisan('idtc:index-sales')->assertFailed();
        $this->assertStringContainsString('ไม่ทันเวลา', IndexerState::query()->findOrFail('sales')->last_error);
    }

    public function test_idle_when_presale_not_deployed(): void
    {
        config(['idtc.contracts.presale' => null]);
        $result = app(SalesIndexer::class)->run();
        $this->assertSame('idle', $result['status']);
        $this->assertSame([], $this->ranges);
        $this->artisan('idtc:index-sales')->assertSuccessful();
    }

    public function test_changing_presale_address_restarts_for_the_new_contract(): void
    {
        app(SalesIndexer::class)->run();
        config(['idtc.contracts.presale' => '0x1212121212121212121212121212121212121212']);
        app(Settings::class)->put('indexer.start_block', 9500);
        $this->ranges = [];
        app(SalesIndexer::class)->run();
        $this->assertSame([[9500, 10000]], $this->ranges);
        $this->assertSame('0x1212121212121212121212121212121212121212', IndexerState::query()->findOrFail('sales')->contract);
        $this->assertSame(1, Purchase::query()->where('contract', '0x1212121212121212121212121212121212121212')->count());
    }

    public function test_admin_sync_button_runs_indexer(): void
    {
        $this->setUpAdmins();
        $this->loginAsOwner()->post('/admin/sales/sync')->assertRedirect()->assertSessionHas('status');
        $this->assertSame(2, Purchase::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'sales.sync']);
    }

    public function test_sales_page_filters_and_rejects_bad_input(): void
    {
        app(SalesIndexer::class)->run();
        $this->setUpAdmins();
        $this->withoutVite();

        // ตัวกรองผิดรูปแบบ = แจ้งเป็นภาษาไทย และไม่กรอง (ไม่ใช่แสดงว่างเปล่า)
        $this->loginAsOwner()->get('/admin/sales?from=2026-13-40&wallet=hello')->assertOk()
            ->assertSee('รูปแบบวันที่ไม่ถูกต้อง')
            ->assertSee('1,666,666.66')
            ->assertSee('666,666,666.66');
        // ค้นด้วยต้นที่อยู่ของผู้ซื้อ
        $this->loginAsOwner()->get('/admin/sales?wallet='.substr(strtolower($this->buyerB), 0, 10))->assertOk()
            ->assertSee('666,666,666.66')
            ->assertDontSee('1,666,666.66');
    }

    public function test_csv_export_streams_filtered_rows(): void
    {
        app(SalesIndexer::class)->run();
        $this->setUpAdmins();
        $res = $this->loginAsOwner()->get('/admin/sales/export?wallet='.strtolower($this->buyerA));
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('2.5,', $csv);
        $this->assertStringContainsString(strtolower($this->buyerA), $csv);
        $this->assertStringNotContainsString(strtolower($this->buyerB), $csv);
    }
}

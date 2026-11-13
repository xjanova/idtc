<?php

namespace Tests\Feature\Binary;

use App\Models\AuditLog;
use App\Models\BinaryDeposit;
use App\Models\BinaryPlanVersion;
use App\Models\GridMember;
use App\Models\IndexerState;
use App\Services\Binary\BinaryLedger;
use App\Services\Binary\BinaryPlan;
use App\Services\Binary\DepositIndexer;
use App\Services\Chain\Abi;
use App\Services\Chain\TransferIndexer;
use App\Services\Grid\GridBinder;
use App\Services\Grid\GridPlan;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * แผน Binary โปรโมชั่น (เฟส 1): อ่านยอดฝาก USDT จากเชน · คำนวณรางวัลจับคู่เป็น USDT ไม่เกินกอง · หลังบ้าน
 */
class BinaryPromoTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const USDT = '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1';

    /** กระเป๋า Binary ของโครงการ (checksum ถูก) */
    private const WALLET = '0x399679922b673FEE28c507B07FF12e17aBFD78a8';

    private const STAFF = '0x6666666666666666666666666666666666666666';

    /** เวลา (unix) ของบล็อก 0 ในเชนจำลอง: 2026-12-02 00:00:00 UTC + เลขบล็อก วินาที */
    private const T0 = 1796169600;

    private int $head = 5000;

    /** @var list<array<string, mixed>> */
    private array $logs = [];

    /** @var list<array{0: int, 1: int}> */
    private array $ranges = [];

    private ?int $maxRange = null;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        Carbon::setTestNow('2026-12-03 00:00:00');
        $this->fakeRpc(fn (string $m, array $p) => $this->rpc($m, $p));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function w(string $name): string
    {
        return '0x'.str_pad(bin2hex($name), 40, '0', STR_PAD_LEFT);
    }

    private function topicOf(string $address): string
    {
        return '0x'.str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    }

    /** event Transfer ของ USDT (6 ทศนิยม) */
    private function transfer(string $from, float $usdt, int $block, ?string $to = null, ?string $token = null, bool $removed = false): array
    {
        $this->seq++;

        return [
            'address' => $token ?? self::USDT,
            'topics' => [TransferIndexer::topic(), $this->topicOf($from), $this->topicOf($to ?? self::WALLET)],
            'data' => '0x'.Abi::encodeUint((string) (int) round($usdt * 1e6)),
            'blockNumber' => '0x'.dechex($block),
            'transactionHash' => '0x'.str_pad(dechex($this->seq), 64, '0', STR_PAD_LEFT),
            'logIndex' => '0x0',
            'removed' => $removed,
        ];
    }

    private function rpc(string $method, array $params): mixed
    {
        switch ($method) {
            case 'eth_blockNumber':
                return '0x'.dechex($this->head);
            case 'eth_chainId':
                return '0x10c1';
            case 'eth_getBlockByNumber':
                return ['number' => $params[0], 'timestamp' => '0x'.dechex(self::T0 + hexdec($params[0]))];
            case 'eth_getLogs':
                $from = hexdec($params[0]['fromBlock']);
                $to = hexdec($params[0]['toBlock']);
                $this->ranges[] = [$from, $to];
                if ($this->maxRange !== null && $to - $from + 1 > $this->maxRange) {
                    return ['__error' => 'block range too high'];
                }
                $want = strtolower((string) ($params[0]['topics'][2] ?? ''));

                // โหนดกรองตามผู้รับเหมือนของจริง
                return array_values(array_filter($this->logs, fn ($l) => hexdec($l['blockNumber']) >= $from && hexdec($l['blockNumber']) <= $to
                    && strtolower($l['topics'][2]) === $want));
            default:
                return ['__error' => 'not faked: '.$method];
        }
    }

    /** ตั้งแผนให้พร้อมรับฝาก: กระเป๋า + โควตา + เปิด · อ่านเชนตั้งแต่บล็อก 1000 */
    private function open(float $quota = 1e12, array $plan = []): void
    {
        $s = app(Settings::class);
        $s->put('binary.wallet', strtolower(self::WALLET));
        $s->put('binary.start_block', 1000);
        $s->put('binary.quota', (string) $quota);
        $s->put('binary.source', 'operations');
        $s->put('binary.enabled', true);
        if ($plan) {
            BinaryPlanVersion::query()->create(['from_week' => 0, 'plan' => $plan + BinaryPlan::defaults(), 'note' => 'test']);
            app(BinaryPlan::class)->forget();
        }
    }

    /** ผูกผู้แนะนำ (ได้ตำแหน่งซ้าย/ขวา) เมื่อ 1 ธ.ค. — ก่อนยอดฝากในเทสต์ทุกรายการ */
    private function bind(string $who, string $ref, ?string $side = null): void
    {
        $now = Carbon::now();
        Carbon::setTestNow('2026-12-01 12:00:00');
        $r = app(GridBinder::class)->bind($this->w($who), $this->w($ref), 'msg', 'sig', null, $side);
        Carbon::setTestNow($now);
        $this->assertInstanceOf(GridMember::class, $r, is_string($r) ? $r : '');
    }

    /** ยอดฝากที่บันทึกแล้ว (ข้ามตัวอ่านเชน) — ใช้เทสต์ตัวคำนวณ */
    private function deposit(string $who, float $usdt, string $at = '2026-12-02 05:00:00', float $pool = 0.30): void
    {
        $this->seq++;
        $ts = CarbonImmutable::parse($at);
        BinaryDeposit::query()->create([
            'tx_hash' => '0x'.str_pad(dechex($this->seq), 64, 'f', STR_PAD_LEFT), 'log_index' => 0, 'wallet' => $this->w($who), 'to' => strtolower(self::WALLET),
            'amount' => (string) (int) round($usdt * 1e6), 'usdt' => $usdt, 'block_number' => $this->seq, 'block_time' => $ts,
            'week' => GridPlan::purchaseWeek($ts->getTimestamp()), 'status' => 'ok', 'price' => '0.0000015',
            'idtc' => $usdt / 0.0000015, 'pool_usdt' => $usdt * $pool,
        ]);
    }

    private function book(string $now): array
    {
        return app(BinaryLedger::class)->compute(CarbonImmutable::parse($now));
    }

    // ───────────── ตัวอ่านยอดฝาก ─────────────

    public function test_indexer_is_idle_until_a_wallet_is_set(): void
    {
        $r = app(DepositIndexer::class)->run();
        $this->assertSame('idle', $r['status']);
        $this->assertSame([], $this->ranges);
        $this->assertFalse(app(BinaryPlan::class)->ready());
    }

    public function test_a_usdt_transfer_to_the_binary_wallet_is_a_purchase(): void
    {
        $this->open();
        $this->logs = [
            $this->transfer($this->w('A'), 150, 1200),
            $this->transfer($this->w('B'), 20, 1300, '0x2222222222222222222222222222222222222222'),      // โอนไปกระเป๋าอื่น
            $this->transfer($this->w('C'), 99, 1400, null, '0x1212121212121212121212121212121212121212'),   // เหรียญอื่น
            $this->transfer($this->w('D'), 77, 1500, null, null, true),                                    // ถูก reorg ทิ้ง
            $this->transfer(self::WALLET, 50, 1600),                                                       // โอนหาตัวเอง
        ];

        $r = app(DepositIndexer::class)->run();
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['inserted']);
        $d = BinaryDeposit::query()->sole();
        $this->assertSame($this->w('A'), $d->wallet);
        $this->assertSame(strtolower(self::WALLET), $d->to);
        $this->assertSame('150000000', $d->amount);
        $this->assertSame(150.0, $d->usdt);
        $this->assertSame('ok', $d->status);
        $this->assertSame(0, $d->week);
        $this->assertEqualsWithDelta(150 / 0.0000015, $d->idtc, 1e-3);          // ราคาตั้งต้น = ราคา presale
        $this->assertEqualsWithDelta(45.0, $d->pool_usdt, 1e-9);                 // 30% เข้ากองรางวัล
        $this->assertSame(self::T0 + 1200, $d->block_time->getTimestamp());

        // รันซ้ำ: ไม่ซ้ำ และอ่านต่อจากบล็อกสุดท้าย
        $before = count($this->ranges);
        $this->assertSame(0, app(DepositIndexer::class)->run()['inserted']);
        $this->assertSame($before, count($this->ranges));
        $this->assertSame(5000, IndexerState::query()->find('binary')->last_block);
    }

    public function test_reads_at_most_1000_blocks_per_call_and_halves_on_range_errors(): void
    {
        $this->open();
        app(DepositIndexer::class)->run();
        $this->assertSame([1000, 1999], $this->ranges[0]);
        foreach ($this->ranges as [$from, $to]) {
            $this->assertLessThanOrEqual(1000, $to - $from + 1);
        }

        IndexerState::query()->delete();
        $this->ranges = [];
        $this->maxRange = 300;
        $this->assertTrue(app(DepositIndexer::class)->run()['ok']);
        $ok = array_filter($this->ranges, fn ($r) => $r[1] - $r[0] + 1 <= 300);
        $this->assertNotEmpty($ok);
        $this->assertSame(5000, IndexerState::query()->find('binary')->last_block);
    }

    public function test_deposits_that_do_not_count_are_kept_for_refund(): void
    {
        $this->open(quota: 100_000_000);                                     // โควตา 100 ล้าน IDTC = 150 USDT ที่ราคาตั้งต้น
        app(Settings::class)->put('binary.ends', '2026-12-02T01:00:00+00:00');   // สิ้นสุดที่บล็อก 3600
        $this->logs = [
            $this->transfer($this->w('A'), 5, 1100),        // ต่ำกว่าขั้นต่ำ 10
            $this->transfer($this->w('B'), 120, 1200),      // ok (80 ล้าน IDTC)
            $this->transfer($this->w('C'), 60, 1300),       // เกินโควตาที่เหลือ (40 ล้าน > 20 ล้าน)
            $this->transfer($this->w('D'), 30, 1400),       // ok พอดีโควตา (20 ล้าน)
            $this->transfer($this->w('E'), 50, 4000),       // หลังวันสิ้นสุด
        ];
        app(DepositIndexer::class)->run();

        $by = BinaryDeposit::query()->get()->keyBy('wallet');
        $this->assertSame('min', $by[$this->w('A')]->status);
        $this->assertSame('ok', $by[$this->w('B')]->status);
        $this->assertSame('quota', $by[$this->w('C')]->status);
        $this->assertSame('ok', $by[$this->w('D')]->status);
        $this->assertSame('ended', $by[$this->w('E')]->status);
        $this->assertSame(0.0, $by[$this->w('C')]->idtc);
        $this->assertSame(0.0, $by[$this->w('C')]->pool_usdt);

        $book = $this->book('2026-12-03 00:00:00');
        $this->assertEqualsWithDelta(150.0, $book['totals']['usdt'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $book['totals']['quota_left'], 1e-3);
        $this->assertSame(2, $book['totals']['deposits']);
    }

    public function test_transfers_while_the_plan_is_off_do_not_count(): void
    {
        $this->open();
        app(Settings::class)->put('binary.enabled', false);
        $this->logs = [$this->transfer($this->w('A'), 100, 1200)];
        app(DepositIndexer::class)->run();
        $this->assertSame('off', BinaryDeposit::query()->sole()->status);
    }

    public function test_changing_the_wallet_starts_a_fresh_read(): void
    {
        $this->open();
        app(DepositIndexer::class)->run();
        $new = '0x4444444444444444444444444444444444444444';
        app(Settings::class)->put('binary.wallet', $new);
        app(Settings::class)->put('binary.start_block', 4500);
        $this->logs = [$this->transfer($this->w('A'), 100, 4000, $new), $this->transfer($this->w('B'), 100, 4600, $new)];
        $this->ranges = [];
        app(DepositIndexer::class)->run();

        $this->assertSame(4500, $this->ranges[0][0]);
        $this->assertSame([$this->w('B')], BinaryDeposit::query()->pluck('wallet')->all());   // ก่อนบล็อกเริ่มไม่นับ
        $this->assertSame($new, BinaryDeposit::query()->sole()->to);
    }

    public function test_scheduled_command_reads_binary_deposits(): void
    {
        $this->open();
        $this->logs = [$this->transfer($this->w('A'), 100, 1200)];
        $this->artisan('idtc:index-sales')->assertSuccessful();
        $this->assertSame(1, BinaryDeposit::query()->count());
    }

    // ───────────── รางวัลจับคู่ ─────────────

    /** A บนสุด · B ขาซ้าย · C ขาขวา (A ชวนเองทั้งคู่) */
    private function trio(): void
    {
        $this->bind('B', 'A');
        $this->bind('C', 'A');
    }

    public function test_pairing_pays_usdt_from_the_weeks_pool_and_carries_the_rest(): void
    {
        $this->trio();
        $this->deposit('A', 100);
        $this->deposit('B', 300);
        $this->deposit('C', 100);

        $live = $this->book('2026-12-03 00:00:00');
        $a = $live['nodes'][$this->w('A')];
        $this->assertTrue($a['q']);
        $this->assertSame([1, 1], [$a['dl'], $a['dr']]);
        $this->assertEqualsWithDelta(150.0, $live['weeks'][0]['pool'], 1e-9);          // 30% × 500
        $row = $live['weeks'][0]['rows'][0];
        $this->assertSame($this->w('A'), $row['wallet']);
        $this->assertEqualsWithDelta(100.0, $row['matched'], 1e-9);                     // ขาขวา (น้อยกว่า)
        $this->assertEqualsWithDelta(10.0, $row['share'], 1e-9);                        // 10% ของยอดจับคู่
        $this->assertSame(BinaryLedger::WAIT, $row['status']);                          // สัปดาห์ยังไม่ปิด
        $this->assertEqualsWithDelta(200.0, $a['cl'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $a['wait'], 1e-9);
        $this->assertSame(0.0, $a['ready']);
        $this->assertSame(0.0, $live['totals']['unpaid']);

        // ปิดสัปดาห์ → พร้อมจ่าย · กองที่ไม่ถูกแจกอยู่กับโครงการ
        $done = $this->book('2026-12-09 00:00:00');
        $this->assertEqualsWithDelta(10.0, $done['nodes'][$this->w('A')]['ready'], 1e-9);
        $this->assertSame('closed', $done['weeks'][0]['state']);
        $this->assertEqualsWithDelta(140.0, $done['totals']['unpaid'], 1e-9);

        // สัปดาห์ถัดไป: C ฝาก 150 → จับคู่กับยอดยกมาของขาซ้าย
        $this->deposit('C', 150, '2026-12-09 05:00:00');
        $next = $this->book('2026-12-10 00:00:00');
        $row = $next['weeks'][1]['rows'][0];
        $this->assertEqualsWithDelta(150.0, $row['matched'], 1e-9);
        $this->assertEqualsWithDelta(9.0, $row['share'], 1e-9);                         // 15 ตั้งต้น → เพดานต่อคน 20% ของกอง 45
        $this->assertTrue($row['capped']);
        $this->assertEqualsWithDelta(50.0, $next['nodes'][$this->w('A')]['cl'], 1e-9);
    }

    public function test_total_pairing_never_exceeds_the_money_set_aside(): void
    {
        BinaryPlanVersion::query()->create(['from_week' => 0, 'plan' => ['cap' => 1.0] + BinaryPlan::defaults(), 'note' => 'test']);
        $this->trio();
        $this->bind('D', 'B');
        $this->bind('E', 'B');
        foreach (['A', 'B', 'C', 'D', 'E'] as $who) {
            $this->deposit($who, 100, pool: 0.02);                                      // กอง 2% × 500 = 10 USDT
        }
        $week = $this->book('2026-12-03 00:00:00')['weeks'][0];

        $this->assertEqualsWithDelta(10.0, $week['pool'], 1e-9);
        $this->assertEqualsWithDelta(20.0, $week['nominal'], 1e-9);                     // A และ B จับคู่ได้คนละ 100 → 10 + 10
        $this->assertEqualsWithDelta(0.5, $week['factor'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $week['given'], 1e-9);
        $this->assertLessThanOrEqual($week['pool'] + 1e-9, array_sum(array_column($week['rows'], 'share')));
    }

    public function test_qualification_is_per_week_and_needs_an_active_direct_on_each_leg(): void
    {
        $this->trio();
        $this->deposit('B', 300);
        $this->deposit('C', 100);                               // A ยังไม่ฝาก
        $before = $this->book('2026-12-03 00:00:00');
        $this->assertFalse($before['nodes'][$this->w('A')]['q']);
        $this->assertSame([], $before['weeks'][0]['rows']);

        $this->deposit('A', 100, '2026-12-09 05:00:00');
        $this->deposit('B', 50, '2026-12-09 06:00:00');
        $this->deposit('C', 20, '2026-12-09 07:00:00');
        $after = $this->book('2026-12-10 00:00:00');
        $this->assertSame([], $after['weeks'][0]['rows']);                              // สัปดาห์ที่ปิดแล้วไม่ถูกแบ่งใหม่
        $this->assertSame(1, $after['nodes'][$this->w('A')]['since']);
        $this->assertEqualsWithDelta(20.0, $after['weeks'][1]['rows'][0]['matched'], 1e-9);
    }

    public function test_held_wallets_and_unbound_deposits(): void
    {
        $this->trio();
        $this->deposit('A', 100);
        $this->deposit('B', 300);
        $this->deposit('C', 100);
        $this->deposit('Z', 500);                               // ไม่มีผู้แนะนำ: ซื้อได้ แต่ไม่เป็นยอดขาของใคร
        GridMember::query()->updateOrCreate(['wallet' => $this->w('A')], ['held_at' => now(), 'held_reason' => 'ตรวจสอบ']);

        $book = $this->book('2026-12-09 00:00:00');
        $this->assertEqualsWithDelta(1000.0, $book['totals']['usdt'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $book['nodes'][$this->w('A')]['held_amt'], 1e-9);
        $this->assertSame(0.0, $book['nodes'][$this->w('A')]['ready']);
        $this->assertEqualsWithDelta(10.0, $book['totals']['held'], 1e-9);
        $this->assertEqualsWithDelta(500 / 0.0000015, $book['nodes'][$this->w('Z')]['idtc'], 1e-3);

        // พักผู้ฝากในขา: ยอดของกระเป๋านั้นไม่นับเป็นยอดขา
        GridMember::query()->where('wallet', $this->w('A'))->update(['held_at' => null]);
        GridMember::query()->where('wallet', $this->w('C'))->update(['held_at' => now()]);
        $this->assertSame([], $this->book('2026-12-09 00:00:00')['weeks'][0]['rows']);
    }

    public function test_binding_an_inviter_is_refused_after_the_first_binary_deposit(): void
    {
        $this->deposit('B', 100);
        $this->assertSame('deposited', app(GridBinder::class)->check($this->w('B'), $this->w('A')));
        $this->assertFalse(app(GridBinder::class)->canBind($this->w('B')));
    }

    // ───────────── หลังบ้าน ─────────────

    private function settingsForm(array $over = []): array
    {
        return $over + ['wallet' => self::WALLET, 'quota' => '50000000000', 'source' => 'operations', 'rest' => 'pool', 'ends' => ''];
    }

    public function test_page_renders_and_money_settings_are_owner_only(): void
    {
        $this->loginAs(self::STAFF, 'viewer')->get('/admin/binary')->assertOk()->assertSee('แผนปิดอยู่')->assertSee('ยังไม่ได้ตั้งกระเป๋า Binary')
            ->assertDontSee('name="wallet"', false)->assertDontSee('name="price"', false);
        $this->post('/admin/binary/plan', [])->assertForbidden();
        $this->post('/admin/binary/sync')->assertForbidden();

        $this->flushSession();
        $this->loginAs(self::STAFF, 'admin')->get('/admin/binary')->assertOk()->assertSee('name="price"', false)->assertDontSee('name="wallet"', false);
        $this->post('/admin/binary/settings', $this->settingsForm())->assertForbidden();
        $this->post('/admin/binary/toggle', ['enabled' => 1])->assertForbidden();
        $this->assertNull(app(BinaryPlan::class)->wallet());

        // ไม่มีสิทธิ์ส่วน binary = ไม่เห็นเมนูและเปิดหน้าไม่ได้
        $this->flushSession();
        $this->loginAs(self::STAFF, 'admin', ['sales' => 'view'])->get('/admin/binary')->assertForbidden();
        $this->get('/admin/sales')->assertOk()->assertDontSee('admin/binary"', false);
    }

    public function test_owner_sets_the_wallet_and_quota_with_guards(): void
    {
        $this->loginAsOwner();
        $this->post('/admin/binary/settings', $this->settingsForm(['wallet' => '0x123']))->assertSessionHasErrors('wallet');
        $this->post('/admin/binary/settings', $this->settingsForm(['wallet' => strtoupper(substr(self::WALLET, 0, 2)).'399679922B673fee28c507b07ff12e17abfd78A8']))->assertSessionHasErrors('wallet'); // checksum ผิด
        $this->post('/admin/binary/settings', $this->settingsForm(['wallet' => '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1']))->assertSessionHasErrors('wallet');  // สัญญา USDT
        $this->post('/admin/binary/settings', $this->settingsForm(['source' => 'presale']))->assertSessionHasErrors('source');
        $this->post('/admin/binary/settings', $this->settingsForm(['quota' => '90000000000']))->assertSessionHasErrors('quota');   // 100,000 ล้าน − งบ Grid 15,000 ล้าน = 85,000 ล้าน
        $this->post('/admin/binary/settings', $this->settingsForm(['rest' => 'me']))->assertSessionHasErrors('rest');
        $this->post('/admin/binary/settings', $this->settingsForm(['ends' => 'tomorrow']))->assertSessionHasErrors('ends');
        $this->assertNull(app(BinaryPlan::class)->wallet());

        $this->post('/admin/binary/settings', $this->settingsForm(['ends' => '2027-12-31']))->assertRedirect('/admin/binary')->assertSessionHas('status');
        $plan = app(BinaryPlan::class);
        $this->assertSame(strtolower(self::WALLET), $plan->wallet());
        $this->assertSame(5000, $plan->startBlock());                                   // บล็อกล่าสุดตอนตั้งกระเป๋า
        $this->assertSame(5e10, $plan->quota());
        $this->assertSame('operations', $plan->source());
        $this->assertSame('2027-12-31 23:59:59', $plan->endsAt()->setTimezone('Asia/Bangkok')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'binary.settings']);
        $this->get('/admin/binary')->assertOk()->assertSee(self::WALLET);
    }

    public function test_wallet_is_not_changed_when_the_chain_cannot_be_read(): void
    {
        $this->loginAsOwner();
        $this->failRpcConnection();
        $this->post('/admin/binary/settings', $this->settingsForm())->assertSessionHas('error');
        $this->assertNull(app(BinaryPlan::class)->wallet());
    }

    public function test_plan_can_open_only_when_complete(): void
    {
        $this->loginAsOwner();
        $this->post('/admin/binary/toggle', ['enabled' => 1])->assertSessionHas('error');
        $this->assertFalse(app(BinaryPlan::class)->enabled());

        $this->post('/admin/binary/settings', $this->settingsForm());
        $this->post('/admin/binary/toggle', ['enabled' => 1])->assertSessionHas('status');
        $this->assertTrue(app(BinaryPlan::class)->ready());
        $this->get('/admin/binary')->assertOk()->assertSee('แผนเปิดรับฝากอยู่');
        $this->post('/admin/binary/toggle', ['enabled' => 0])->assertSessionHas('status');
        $this->assertFalse(app(BinaryPlan::class)->enabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'binary.enabled']);
    }

    public function test_rates_apply_now_before_any_deposit_and_next_week_after(): void
    {
        $form = fn (array $over = []) => $over + ['price' => '0.000002', 'min_usdt' => '20', 'pool' => '25', 'pair' => '12', 'cap' => '10', 'directs' => '1', 'note' => 'ตั้งอัตราเปิดแผน'];
        $this->loginAs(self::STAFF, 'admin');

        $this->post('/admin/binary/plan', $form(['pool' => '60']))->assertSessionHasErrors('pool');     // เกินครึ่งของเงินที่เข้ามา
        $this->post('/admin/binary/plan', $form(['price' => '0']))->assertSessionHasErrors('price');
        $this->post('/admin/binary/plan', $form(['note' => 'สั้น']))->assertSessionHasErrors('note');

        $this->post('/admin/binary/plan', $form())->assertRedirect('/admin/binary')->assertSessionHas('status');
        $v = BinaryPlanVersion::query()->sole();
        $this->assertSame(0, $v->from_week);
        $this->assertSame('0.000002', $v->plan['price']);
        $this->assertEqualsWithDelta(0.25, $v->plan['pool'], 1e-12);
        $this->assertEqualsWithDelta(0.25, app(BinaryPlan::class)->current()['pool'], 1e-12);

        // มียอดฝากแล้ว → อัตราใหม่มีผลสัปดาห์ถัดไป
        $this->deposit('A', 100);
        $this->post('/admin/binary/plan', $form(['pool' => '20', 'note' => 'ลดกองรางวัลสัปดาห์หน้า']))->assertSessionHas('status');
        $this->assertSame([0, 1], BinaryPlanVersion::query()->orderBy('from_week')->pluck('from_week')->all());
        $this->assertEqualsWithDelta(0.25, app(BinaryPlan::class)->current()['pool'], 1e-12);
        $this->assertEqualsWithDelta(0.20, app(BinaryPlan::class)->at(1)['pool'], 1e-12);
        $this->assertSame(2, AuditLog::query()->where('action', 'binary.plan')->count());
    }

    public function test_refund_of_an_uncounted_deposit_is_recorded_once(): void
    {
        $this->open();
        app(Settings::class)->put('binary.enabled', false);
        $this->logs = [$this->transfer($this->w('A'), 100, 1200)];
        app(DepositIndexer::class)->run();
        $d = BinaryDeposit::query()->sole();
        $tx = '0x'.str_repeat('e', 64);

        $this->loginAs(self::STAFF, 'viewer')->post("/admin/binary/deposits/{$d->id}/refund", ['tx_hash' => $tx])->assertForbidden();
        $this->flushSession();
        $this->loginAs(self::STAFF, 'admin')->get('/admin/binary')->assertOk()->assertSee('โอนตอนแผนปิดอยู่');
        $this->post("/admin/binary/deposits/{$d->id}/refund", ['tx_hash' => 'nope'])->assertSessionHas('error');
        $this->post("/admin/binary/deposits/{$d->id}/refund", ['tx_hash' => $d->tx_hash])->assertSessionHas('error');   // tx ของการฝากเอง
        $this->post("/admin/binary/deposits/{$d->id}/refund", ['tx_hash' => $tx])->assertSessionHas('status');
        $this->assertSame($tx, $d->fresh()->refund_tx);
        $this->assertSame(self::STAFF, $d->fresh()->refunded_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'binary.refund']);
        $this->get('/admin/binary')->assertOk()->assertSee('คืนแล้ว');
    }
}

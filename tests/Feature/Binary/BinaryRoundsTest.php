<?php

namespace Tests\Feature\Binary;

use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Models\GridRound;
use App\Models\GridRoundItem;
use App\Services\Binary\BinaryRounds;
use App\Services\Chain\Abi;
use App\Services\Grid\GridBinder;
use App\Services\Grid\GridMerkle;
use App\Services\Grid\GridPlan;
use App\Services\Grid\GridRounds;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * แผน Binary โปรโมชั่น (เฟส 3): รอบจ่าย USDT (รางวัลจับคู่) และรอบส่ง IDTC ที่ซื้อ ผ่านสัญญา GridRewards ของแต่ละสาย
 * — ไม่จ่ายซ้ำ · แต่ละสายแยกจากกันและแยกจากรอบจ่ายของ Grid · สถานะตัดสินจากเชน · สมาชิกเห็นยอดที่กดรับได้
 */
class BinaryRoundsTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const STAFF = '0x2222222222222222222222222222222222222222';

    private const PAY = '0x7777777777777777777777777777777777777777';     // GridRewards ที่จ่าย USDT

    private const DEL = '0x6666666666666666666666666666666666666666';     // GridRewards ที่ส่ง IDTC

    private const GRID = '0x5555555555555555555555555555555555555555';    // GridRewards ของ IDTC Grid

    private const USDT = '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1';

    private const TOKEN = '0x8888888888888888888888888888888888888888';

    private const A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const C = '0xcccccccccccccccccccccccccccccccccccccccc';

    /** สถานะสัญญาจำลอง แยกตามที่อยู่ */
    private array $chain = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        config(['idtc.contracts.token' => self::TOKEN, 'idtc.contracts.binpay' => self::PAY, 'idtc.contracts.bindel' => self::DEL, 'idtc.contracts.rewards' => self::GRID]);
        foreach ([self::PAY => self::USDT, self::DEL => self::TOKEN, self::GRID => self::TOKEN] as $contract => $token) {
            $this->chain[$contract] = ['token' => $token, 'paused' => false, 'outstanding' => '0', 'balance' => '0', 'rounds' => [], 'claimed' => []];
        }
        $this->fakeRpc(fn (string $m, array $p) => $this->rpc($m, $p));

        // A บนสุด · B ขาซ้าย · C ขาขวา — ผูก 1 ธ.ค. ฝากสัปดาห์แรก (2 ธ.ค.) · ตอนนี้ 9 ธ.ค. = สัปดาห์แรกปิดแล้ว
        Carbon::setTestNow('2026-12-01 12:00:00');
        foreach ([self::B, self::C] as $w) {
            $this->assertInstanceOf(GridMember::class, app(GridBinder::class)->bind($w, self::A, 'm', 's'));
        }
        Carbon::setTestNow('2026-12-09 05:00:00');
        $this->deposit(self::A, 100);
        $this->deposit(self::B, 300);
        $this->deposit(self::C, 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function deposit(string $wallet, float $usdt, string $at = '2026-12-02 05:00:00'): void
    {
        $this->seq++;
        $ts = CarbonImmutable::parse($at);
        BinaryDeposit::query()->create([
            'tx_hash' => '0x'.str_pad(dechex($this->seq), 64, 'f', STR_PAD_LEFT), 'log_index' => 0, 'wallet' => $wallet, 'to' => '0x'.str_repeat('9', 40),
            'amount' => (string) (int) round($usdt * 1e6), 'usdt' => $usdt, 'block_number' => $this->seq, 'block_time' => $ts,
            'week' => GridPlan::purchaseWeek($ts->getTimestamp()), 'status' => 'ok', 'price' => '0.0000015',
            'idtc' => $usdt / 0.0000015, 'idtc_micro' => BinaryDeposit::microIdtc((string) (int) round($usdt * 1e6), '0.0000015'), 'pool_usdt' => $usdt * 0.30,
        ]);
    }

    private function rpc(string $method, array $params): mixed
    {
        if ($method === 'eth_blockNumber') {
            return '0x10';
        }
        if ($method === 'eth_getCode') {
            return isset($this->chain[strtolower($params[0])]) ? '0x6080' : '0x';
        }
        if ($method !== 'eth_call') {
            return ['__error' => 'not faked: '.$method];
        }
        $to = strtolower($params[0]['to']);
        $data = $params[0]['data'];
        $sel = substr($data, 0, 10);
        $args = Abi::words(substr($data, 10));
        $word = fn ($v) => '0x'.Abi::encodeUint((string) $v);
        if ($sel === Abi::selector('balanceOf(address)')) {
            return $word($this->chain[Abi::decodeAddress($args[0])]['balance'] ?? '0');
        }
        $c = $this->chain[$to] ?? null;
        if ($c === null) {
            return ['__error' => 'unknown contract'];
        }

        return match ($sel) {
            Abi::selector('owner()') => '0x'.Abi::encodeAddress(self::STAFF),
            Abi::selector('token()') => '0x'.Abi::encodeAddress($c['token']),
            Abi::selector('paused()') => $word($c['paused'] ? 1 : 0),
            Abi::selector('outstanding()') => $word($c['outstanding']),
            Abi::selector('roundCount()') => $word(count($c['rounds'])),
            Abi::selector('rounds(uint256)') => (function () use ($args, $c) {
                $r = $c['rounds'][(int) Abi::decodeUint($args[0])];

                return '0x'.Abi::encodeBytes32($r['root']).Abi::encodeUint($r['total']).Abi::encodeUint($r['claimed'] ?? '0')
                    .Abi::encodeUint(0).Abi::encodeUint(1765860000).Abi::encodeUint(($r['revoked'] ?? false) ? 1 : 0);
            })(),
            Abi::selector('claimed(uint256,address)') => $word(($c['claimed'][(int) Abi::decodeUint($args[0])][Abi::decodeAddress($args[1])] ?? false) ? 1 : 0),
            default => ['__error' => 'not faked: '.$sel],
        };
    }

    /** เจ้าของประกาศรอบนี้บนเชน (จำลอง addRound) */
    private function publish(GridRound $r, array $over = []): void
    {
        $this->chain[$r->contract]['rounds'][$r->chain_round] = $over + ['root' => $r->root, 'total' => $r->total];
    }

    private function usdt(): BinaryRounds
    {
        return BinaryRounds::of(BinaryRounds::USDT);
    }

    private function idtc(): BinaryRounds
    {
        return BinaryRounds::of(BinaryRounds::IDTC);
    }

    public function test_usdt_round_pays_closed_week_rewards_once(): void
    {
        $due = $this->usdt()->dueNow();
        $this->assertSame([self::A], array_column($due, 'wallet'));
        $this->assertEqualsWithDelta(10.0, $due[0]['due'], 1e-9);                 // 10% ของยอดจับคู่ 100 USDT

        $res = $this->usdt()->build(self::STAFF, 'สัปดาห์ 1');
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertStringContainsString('USDT', $res['message']);
        $round = GridRound::query()->sole();
        $this->assertSame(BinaryRounds::USDT, $round->stream);
        $this->assertSame(self::PAY, $round->contract);
        $this->assertSame('full', $round->kind);                                  // ไม่แบ่งทันที/ปลดทีหลัง
        $this->assertNull($round->unlock_at);
        $this->assertSame('10000000', $round->total);                             // USDT 6 ทศนิยม
        $this->assertSame(10.0, $round->total_idtc);
        $item = GridRoundItem::query()->sole();
        $this->assertSame('10000000', $item->amount);
        $leaf = GridMerkle::leaf(self::PAY, 0, self::A, '10000000');
        $this->assertTrue(GridMerkle::verify($item->proof, $round->root, $leaf));

        // มีรอบค้างประกาศ → สร้างซ้ำไม่ได้ · ประกาศแล้ว → ไม่มียอดเหลือให้จ่ายซ้ำ
        $this->assertFalse($this->usdt()->build(self::STAFF, null)['ok']);
        $this->publish($round);
        $this->assertSame(1, $this->usdt()->reconcile());
        $this->assertSame(GridRound::LIVE, $round->fresh()->status);
        $this->assertSame([], $this->usdt()->dueNow());
        $this->assertFalse($this->usdt()->build(self::STAFF, null)['ok']);

        // สัปดาห์ถัดไปปิด: C ฝากอีก 150 → จับคู่กับยอดยกมา → จ่ายเฉพาะส่วนที่เพิ่ม
        $this->deposit(self::C, 150, '2026-12-09 06:00:00');
        Carbon::setTestNow('2026-12-16 05:00:00');
        $due = $this->usdt()->dueNow();
        $this->assertEqualsWithDelta(9.0, $due[0]['due'], 1e-9);                  // เพดานต่อคน 20% ของกอง 45
        $this->assertEqualsWithDelta(10.0, $due[0]['allocated'], 1e-9);
        $second = $this->usdt()->build(self::STAFF, null);
        $this->assertTrue($second['ok']);
        $this->assertSame(1, $second['rounds'][0]->chain_round);
        $this->assertSame('9000000', $second['rounds'][0]->total);
    }

    public function test_usdt_round_ignores_the_open_week_and_held_wallets(): void
    {
        // รางวัลของสัปดาห์ที่ยังไม่ปิดไม่เข้ารอบ
        Carbon::setTestNow('2026-12-03 05:00:00');
        $this->assertSame([], $this->usdt()->dueNow());
        $this->assertFalse($this->usdt()->build(self::STAFF, null)['ok']);

        Carbon::setTestNow('2026-12-09 05:00:00');
        GridMember::query()->updateOrCreate(['wallet' => self::A], ['held_at' => now(), 'held_reason' => 'ตรวจสอบ']);
        $this->assertSame([], $this->usdt()->dueNow());                           // ผู้รับถูกพัก
        $this->assertNotContains(self::A, array_column($this->idtc()->dueNow(), 'wallet'));
    }

    public function test_idtc_round_delivers_every_counted_purchase(): void
    {
        $due = $this->idtc()->dueNow();
        $this->assertEqualsCanonicalizing([self::A, self::B, self::C], array_column($due, 'wallet'));
        $this->assertSame(self::B, $due[0]['wallet']);                            // มากสุดก่อน

        $res = $this->idtc()->build(self::STAFF, null);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertStringContainsString('IDTC', $res['message']);
        $round = GridRound::query()->where('stream', BinaryRounds::IDTC)->sole();
        $this->assertSame(self::DEL, $round->contract);
        $this->assertSame(3, $round->items);
        $a = GridRoundItem::query()->where('round_id', $round->id)->where('wallet', self::A)->sole();
        // 100 / 0.0000015 = 66,666,666.666666… IDTC → ปัดลงที่ 0.000001 → 18 ทศนิยม
        $this->assertSame('66666666666666'.str_repeat('0', 12), $a->amount);
        $this->assertTrue(GridMerkle::verify($a->proof, $round->root, GridMerkle::leaf(self::DEL, 0, self::A, $a->amount)));
        $this->assertEqualsWithDelta(500 / 0.0000015, $round->total_idtc, 1.0);

        // ฝากเพิ่มหลังสร้างรอบ → รอบถัดไปส่งเฉพาะส่วนเพิ่ม
        $this->publish($round);
        $this->idtc()->reconcile();
        $this->deposit(self::C, 30, '2026-12-09 04:00:00');
        $due = $this->idtc()->dueNow();
        $this->assertSame([self::C], array_column($due, 'wallet'));
        $this->assertEqualsWithDelta(30 / 0.0000015, $due[0]['due'], 1.0);
    }

    public function test_streams_do_not_touch_each_other_or_the_grid_rounds(): void
    {
        $this->usdt()->build(self::STAFF, null);
        $this->idtc()->build(self::STAFF, null);
        $grid = GridRound::query()->create([
            'stream' => 'grid', 'contract' => self::GRID, 'chain_round' => 0, 'kind' => 'full', 'status' => GridRound::DRAFT,
            'root' => '0x'.str_repeat('1', 64), 'total' => '5', 'total_idtc' => 5, 'items' => 1,
        ]);
        GridRoundItem::query()->insert(['round_id' => $grid->id, 'wallet' => self::A, 'amount' => '5', 'amount_idtc' => 5, 'proof' => '[]']);

        // ยอดจัดสรรนับแยกสาย
        $this->assertEqualsWithDelta(5.0, app(GridRounds::class)->allocated()[self::A], 1e-9);
        $this->assertEqualsWithDelta(10.0, $this->usdt()->allocated()[self::A], 1e-9);
        $this->assertEqualsWithDelta(100 / 0.0000015, $this->idtc()->allocated()[self::A], 1.0);

        // reconcile ของสายหนึ่งไม่ทิ้ง draft ของสายอื่น (สัญญาคนละตัว)
        app(GridRounds::class)->reconcile();
        $this->usdt()->reconcile();
        $this->idtc()->reconcile();
        $this->assertSame(3, GridRound::query()->where('status', GridRound::DRAFT)->count());

        // ทิ้งรอบข้ามสายไม่ได้
        $this->assertFalse($this->usdt()->discard($grid));
        $this->assertFalse(app(GridRounds::class)->discard(GridRound::query()->where('stream', BinaryRounds::USDT)->sole()));
        $this->assertTrue($this->usdt()->discard(GridRound::query()->where('stream', BinaryRounds::USDT)->sole()));
        $this->assertSame(GridRound::DRAFT, GridRound::query()->where('stream', BinaryRounds::IDTC)->sole()->status);

        // เปลี่ยนสัญญาของสาย IDTC → draft ของสัญญาเดิมถูกทิ้ง เฉพาะสายนั้น
        config(['idtc.contracts.bindel' => '0x4444444444444444444444444444444444444444']);
        $this->chain['0x4444444444444444444444444444444444444444'] = ['token' => self::TOKEN, 'paused' => false, 'outstanding' => '0', 'balance' => '0', 'rounds' => [], 'claimed' => []];
        $this->idtc()->reconcile();
        $this->assertSame(GridRound::DISCARDED, GridRound::query()->where('stream', BinaryRounds::IDTC)->sole()->status);
        $this->assertSame(GridRound::DRAFT, $grid->fresh()->status);
    }

    public function test_round_cannot_be_built_without_a_readable_contract(): void
    {
        config(['idtc.contracts.binpay' => null]);
        $res = $this->usdt()->build(self::STAFF, null);
        $this->assertFalse($res['ok']);
        $this->assertSame(0, GridRound::query()->count());
    }

    public function test_admin_pages_build_publish_flow_and_permissions(): void
    {
        $this->loginAs(self::STAFF, 'viewer');
        $this->get('/admin/binary/rounds/usdt')->assertOk()->assertSee('รางวัลจับคู่ (USDT)')->assertSee('USDT ในสัญญา')->assertDontSee('สร้างรอบจ่าย</button>', false);
        $this->get('/admin/binary/rounds/idtc')->assertOk()->assertSee('IDTC ในสัญญา');
        $this->get('/admin/binary/rounds/other')->assertNotFound();
        $this->post('/admin/binary/rounds/usdt')->assertForbidden();
        $this->get('/admin/binary')->assertOk()->assertSee('รอบจ่าย USDT')->assertSee('รอบส่ง IDTC');

        $this->flushSession();
        $this->loginAs(self::STAFF, 'admin');
        $this->post('/admin/binary/rounds/usdt', ['note' => 'สัปดาห์ 1'])->assertRedirect()->assertSessionHas('status');
        $round = GridRound::query()->sole();
        $this->assertDatabaseHas('audit_logs', ['action' => 'binary.round.build']);

        $page = $this->get('/admin/binary/round/'.$round->id)->assertOk();
        $page->assertSee("chainAction('binpay.addRound'", false)->assertSee('ยังขาด')->assertSee('10.00 USDT')->assertSee('สัปดาห์ 1');
        $this->get('/admin/grid/rounds/'.$round->id)->assertNotFound();           // ไม่ปนกับหน้ารอบจ่ายของ Grid
        $this->get('/admin/grid/rounds')->assertOk()->assertDontSee('#0</a>', false);
        $csv = $this->get('/admin/binary/round/'.$round->id.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString(self::A, $csv);
        $this->assertStringContainsString('10000000', $csv);

        // ประกาศบนเชนแล้ว: หน้ารอบเปลี่ยนเป็นยกเลิกได้ · ทิ้งไม่ได้
        $this->chain[self::PAY]['balance'] = '10000000';
        $this->publish($round);
        $this->get('/admin/binary/round/'.$round->id)->assertOk()->assertSee("chainAction('binpay.revokeRound'", false);
        $this->post('/admin/binary/round/'.$round->id.'/discard')->assertSessionHas('error');
        $this->assertSame(GridRound::LIVE, $round->fresh()->status);

        // รอบของ Grid เปิดจากหน้า Binary ไม่ได้
        $grid = GridRound::query()->create(['stream' => 'grid', 'contract' => self::GRID, 'chain_round' => 0, 'kind' => 'full', 'status' => GridRound::DRAFT, 'root' => '0x'.str_repeat('1', 64), 'total' => '5', 'total_idtc' => 5, 'items' => 0]);
        $this->get('/admin/binary/round/'.$grid->id)->assertNotFound();
        $this->post('/admin/binary/round/'.$grid->id.'/discard')->assertNotFound();
    }

    public function test_draft_round_can_be_discarded(): void
    {
        $this->loginAs(self::STAFF, 'admin');
        $this->post('/admin/binary/rounds/idtc')->assertRedirect();
        $round = GridRound::query()->sole();
        $this->post('/admin/binary/round/'.$round->id.'/discard')->assertRedirect('/admin/binary/rounds/idtc')->assertSessionHas('status');
        $this->assertSame(GridRound::DISCARDED, $round->fresh()->status);
        $this->assertCount(3, $this->idtc()->dueNow());                           // ยอดกลับมารอรอบถัดไป
    }

    public function test_chain_actions_of_the_binary_contracts_need_the_binary_permission(): void
    {
        $tx = ['action' => 'binpay.addRound', 'tx_hash' => '0x'.str_repeat('a', 64), 'params' => ['id' => '0', 'root' => '0x'.str_repeat('1', 64), 'total' => '10000000', 'unlockAt' => '0']];
        $this->loginAs(self::STAFF, 'admin', ['grid_rounds' => 'edit', 'presale' => 'edit']);
        $this->postJson('/admin/tx', $tx)->assertForbidden();                     // สิทธิ์รอบจ่าย Grid ใช้กับสัญญา Binary ไม่ได้

        $this->flushSession();
        $this->loginAs(self::STAFF, 'admin', ['binary' => 'edit']);
        $this->postJson('/admin/tx', $tx)->assertCreated();
        $this->postJson('/admin/tx', ['action' => 'bindel.pause', 'tx_hash' => '0x'.str_repeat('b', 64)])->assertCreated();
        $this->postJson('/admin/tx', ['action' => 'rewards.pause', 'tx_hash' => '0x'.str_repeat('c', 64)])->assertForbidden();
    }

    public function test_payout_contracts_must_pay_the_right_token(): void
    {
        config(['idtc.contracts.binpay' => null, 'idtc.contracts.bindel' => null]);
        app(Settings::class)->put('contracts', ['token' => self::TOKEN]);
        $this->loginAsOwner();
        $pay = '0x7777777777777777777777777777777777777777';
        $del = '0x6666666666666666666666666666666666666666';

        // สัญญาที่จ่าย IDTC ตั้งเป็นสัญญาจ่าย USDT ไม่ได้ และกลับกัน
        $this->post('/admin/settings/contracts', ['key' => 'binpay', 'address' => $del])->assertSessionHasErrors('address_binpay');
        $this->post('/admin/settings/contracts', ['key' => 'bindel', 'address' => $pay])->assertSessionHasErrors('address_bindel');
        $this->post('/admin/settings/contracts', ['key' => 'binpay', 'address' => $pay])->assertSessionHas('status');
        $this->post('/admin/settings/contracts', ['key' => 'bindel', 'address' => $del])->assertSessionHas('status');
        $this->get('/admin/settings')->assertOk()->assertSee('สัญญาจ่ายรางวัล Binary (USDT)')->assertSee('สัญญาส่ง IDTC ของแผน Binary');
    }

    public function test_member_sees_claims_with_proofs_and_live_claimed_status(): void
    {
        app(Settings::class)->put('binary.enabled', true);
        $this->usdt()->build(self::STAFF, null);
        $this->idtc()->build(self::STAFF, null);
        $this->withSession(['grid' => ['address' => self::A]]);

        // ยังไม่ประกาศ = ยังไม่มีอะไรให้รับ
        $c = $this->getJson('/binary/me')->assertOk()->json('claims');
        $this->assertSame([], $c['usdt']['items']);
        $this->assertSame([], $c['idtc']['items']);
        $this->assertSame('0x7777777777777777777777777777777777777777', $c['usdt']['contract']);

        foreach (GridRound::query()->get() as $r) {
            $this->publish($r);
        }
        $this->artisan('idtc:index-sales');                                       // งานตามเวลาตัดสินสถานะรอบจากเชน
        $this->assertSame(2, GridRound::query()->where('status', GridRound::LIVE)->count());

        $c = $this->getJson('/binary/me')->json('claims');
        $u = $c['usdt']['items'][0];
        $this->assertSame(0, $u['round']);
        $this->assertSame('10000000', $u['amount']);
        $this->assertEqualsWithDelta(10.0, $u['value'], 1e-9);
        $this->assertFalse($u['claimed']);
        $this->assertTrue(GridMerkle::verify($u['proof'], GridRound::query()->where('stream', BinaryRounds::USDT)->sole()->root, GridMerkle::leaf(self::PAY, 0, self::A, $u['amount'])));
        $this->assertSame('66666666666666'.str_repeat('0', 12), $c['idtc']['items'][0]['amount']);

        // รับ USDT ไปแล้วบนเชน
        $this->chain[self::PAY]['claimed'][0][self::A] = true;
        $c = $this->getJson('/binary/me')->json('claims');
        $this->assertTrue($c['usdt']['items'][0]['claimed']);
        $this->assertFalse($c['idtc']['items'][0]['claimed']);

        // คนที่ไม่อยู่ในรายชื่อไม่เห็นอะไร
        $this->flushSession();
        $this->withSession(['grid' => ['address' => '0x'.str_repeat('d', 40)]]);
        $this->assertSame([], $this->getJson('/binary/me')->json('claims.usdt.items'));

        $this->get('/binary')->assertOk()->assertSee('data-testid="binary-claim"', false);
    }
}

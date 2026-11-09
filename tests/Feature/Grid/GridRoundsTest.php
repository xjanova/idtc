<?php

namespace Tests\Feature\Grid;

use App\Models\AuditLog;
use App\Models\GridMember;
use App\Models\GridRound;
use App\Models\GridRoundItem;
use App\Models\Purchase;
use App\Services\Admin\ChainActions;
use App\Services\Chain\Abi;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridMerkle;
use App\Services\Grid\GridRounds;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * รอบจ่ายรางวัล Grid (สัญญา GridRewards): Merkle ตรงกับสัญญา · สร้างรอบไม่จ่ายซ้ำ · สถานะตัดสินจากเชน · สมาชิกเห็นรางวัลที่รับได้
 */
class GridRoundsTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const ADMIN = '0x2222222222222222222222222222222222222222';

    private const VIEWER = '0x3333333333333333333333333333333333333333';

    private const REWARDS = '0x7777777777777777777777777777777777777777';

    private const TOKEN = '0x8888888888888888888888888888888888888888';

    private const A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** สถานะสัญญาจำลอง */
    private array $chain = ['paused' => false, 'outstanding' => '0', 'balance' => '0', 'rounds' => [], 'claimed' => []];

    private bool $claimReadFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAdmins();
        Carbon::setTestNow('2026-12-16 05:00:00'); // ซื้อ 2 ธ.ค. + ถือ 14 วัน = ครบแล้ว · ยังไม่ปิดการขาย
        config(['idtc.contracts.rewards' => self::REWARDS, 'idtc.contracts.token' => null]);
        GridMember::query()->create(['wallet' => self::B, 'referrer' => self::A, 'bound_at' => '2026-12-02 00:00:00']);
        foreach ([[self::A, 100, 1], [self::B, 50, 2]] as [$who, $usdt, $i]) {
            Purchase::query()->create([
                'contract' => '0x4444444444444444444444444444444444444444', 'tx_hash' => '0x'.str_repeat((string) $i, 64), 'log_index' => 0,
                'buyer' => $who, 'pay_amount' => (string) ($usdt * 1000000), 'tokens_out' => '0', 'pay_usdt' => $usdt,
                'tokens_idtc' => $usdt * 2e6 / 3, 'block_number' => $i, 'block_time' => '2026-12-02 0'.$i.':00:00',
            ]);
        }
        $this->fakeRpc(fn (string $m, array $p) => $this->rpc($m, $p));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rpc(string $method, array $params): mixed
    {
        if ($method !== 'eth_call') {
            return ['__error' => 'not faked: '.$method];
        }
        $to = strtolower($params[0]['to']);
        $data = $params[0]['data'];
        $sel = substr($data, 0, 10);
        $args = Abi::words(substr($data, 10));
        $word = fn ($v) => '0x'.Abi::encodeUint((string) $v);
        if ($to === self::TOKEN && $sel === Abi::selector('balanceOf(address)')) {
            return $word($this->chain['balance']);
        }
        if ($to !== self::REWARDS) {
            return ['__error' => 'unknown contract'];
        }

        return match ($sel) {
            Abi::selector('owner()') => '0x'.Abi::encodeAddress(self::ADMIN),
            Abi::selector('token()') => '0x'.Abi::encodeAddress(self::TOKEN),
            Abi::selector('paused()') => $word($this->chain['paused'] ? 1 : 0),
            Abi::selector('outstanding()') => $word($this->chain['outstanding']),
            Abi::selector('roundCount()') => $word(count($this->chain['rounds'])),
            Abi::selector('rounds(uint256)') => (function () use ($args) {
                $r = $this->chain['rounds'][(int) Abi::decodeUint($args[0])];

                return '0x'.Abi::encodeBytes32($r['root']).Abi::encodeUint($r['total']).Abi::encodeUint($r['claimed'] ?? '0')
                    .Abi::encodeUint($r['unlock_at'] ?? 0).Abi::encodeUint(1765860000).Abi::encodeUint(($r['revoked'] ?? false) ? 1 : 0);
            })(),
            Abi::selector('claimed(uint256,address)') => $this->claimReadFails ? ['__error' => 'node down']
                : $word(($this->chain['claimed'][(int) Abi::decodeUint($args[0])][Abi::decodeAddress($args[1])] ?? false) ? 1 : 0),
            default => ['__error' => 'not faked: '.$sel],
        };
    }

    /** เจ้าของประกาศรอบนี้บนเชน (จำลอง addRound) */
    private function publish(GridRound $r, array $over = []): void
    {
        $this->chain['rounds'][$r->chain_round] = $over + ['root' => $r->root, 'total' => $r->total, 'unlock_at' => $r->unlock_at?->getTimestamp() ?? 0];
    }

    private function rounds(): GridRounds
    {
        return app(GridRounds::class);
    }

    private function dueFor(string $wallet): float
    {
        foreach ($this->rounds()->due(app(GridLedger::class)->book(null, true)) as $d) {
            if ($d['wallet'] === $wallet) {
                return $d['due'];
            }
        }

        return 0.0;
    }

    public function test_merkle_tree_matches_the_fixture_the_contract_test_verifies(): void
    {
        $fx = json_decode((string) file_get_contents(base_path('tests/Fixtures/grid-rewards-php.json')), true);
        $leaves = array_map(fn ($i) => GridMerkle::leaf($fx['contract'], $fx['round'], $i['wallet'], $i['amount']), $fx['items']);
        $tree = new GridMerkle($leaves);
        $this->assertSame($fx['root'], $tree->root());
        foreach ($fx['items'] as $k => $i) {
            $this->assertSame($i['leaf'], $leaves[$k]);
            $this->assertSame($i['proof'], $tree->proof($k));
            $this->assertTrue(GridMerkle::verify($tree->proof($k), $tree->root(), $leaves[$k]));
        }
        // leaf ผูกกับสัญญาและเลขรอบ — proof ของรอบอื่น/สัญญาอื่นใช้ไม่ได้
        $this->assertFalse(GridMerkle::verify($fx['items'][0]['proof'], $fx['root'], GridMerkle::leaf($fx['contract'], $fx['round'] + 1, $fx['items'][0]['wallet'], $fx['items'][0]['amount'])));
        $this->assertFalse(GridMerkle::verify($fx['items'][0]['proof'], $fx['root'], GridMerkle::leaf(self::REWARDS, $fx['round'], $fx['items'][0]['wallet'], $fx['items'][0]['amount'])));
    }

    public function test_building_before_the_sale_ends_splits_and_never_allocates_twice(): void
    {
        $before = $this->dueFor(self::A);
        $this->assertGreaterThan(1, $before);

        $this->loginAs(self::ADMIN, 'admin')->post('/admin/grid/rounds', ['note' => 'รอบแรก'])->assertRedirect();
        $made = GridRound::query()->orderBy('chain_round')->get();
        $this->assertSame(['instant', 'deferred'], $made->pluck('kind')->all());
        $this->assertSame([0, 1], $made->pluck('chain_round')->all());
        $this->assertNull($made[0]->unlock_at);
        $this->assertEquals(CarbonImmutable::parse(config('idtc.sale.end')), $made[1]->unlock_at);

        // สองส่วนรวมกัน = ยอดครบระยะ (ปัดลงที่ 0.000001) · ส่วนทันที = 50%
        $a = GridRoundItem::query()->where('wallet', self::A)->get()->keyBy(fn ($i) => $i->round->kind);
        $this->assertEqualsWithDelta($before, $a['instant']->amount_idtc + $a['deferred']->amount_idtc, 0.000002);
        $this->assertEqualsWithDelta($before / 2, $a['instant']->amount_idtc, 0.000002);
        $this->assertSame(GridRounds::wei((int) round($a['instant']->amount_idtc * 1e6)), $a['instant']->amount);

        // ทุกรายชื่อพิสูจน์กับ root ได้
        foreach ($made as $r) {
            foreach ($r->entries as $i) {
                $this->assertTrue(GridMerkle::verify($i->proof, $r->root, GridMerkle::leaf(self::REWARDS, $r->chain_round, $i->wallet, $i->amount)));
            }
        }

        // จัดสรรแล้ว → รอบถัดไปไม่มียอดซ้ำ · มี draft ค้าง → สร้างใหม่ไม่ได้
        $this->assertSame(0.0, $this->dueFor(self::A));
        $this->loginAs(self::ADMIN)->post('/admin/grid/rounds')->assertSessionHas('error');
        $this->assertSame(2, GridRound::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'grid.round.build')->count());
    }

    public function test_status_comes_from_the_chain(): void
    {
        $this->rounds()->build(self::ADMIN, null);
        [$instant, $deferred] = GridRound::query()->orderBy('chain_round')->get()->all();

        $this->publish($instant);
        $this->publish($deferred, ['root' => '0x'.str_repeat('ab', 32)]); // เลขรอบนี้บนเชนเป็นรายชื่ออื่น
        $this->assertSame(2, $this->rounds()->reconcile());
        $this->assertSame(GridRound::LIVE, $instant->fresh()->status);
        $this->assertNotNull($instant->fresh()->live_at);
        $this->assertSame(GridRound::STALE, $deferred->fresh()->status);

        // รอบ stale ไม่นับว่าจัดสรร → ส่วนนั้นกลับมารอรอบถัดไป
        $this->assertEqualsWithDelta($deferred->entries()->where('wallet', self::A)->value('amount_idtc'), $this->dueFor(self::A), 0.000002);
    }

    public function test_a_revoked_round_never_pays_twice(): void
    {
        config(['idtc.sale.end' => '2026-12-10T00:00:00+07:00']); // ปิดการขายแล้ว → รอบเต็มรอบเดียว
        $res = $this->rounds()->build(self::ADMIN, null);
        $this->assertTrue($res['ok']);
        $round = $res['rounds'][0];
        $this->assertSame('full', $round->kind);
        $this->publish($round);
        $this->rounds()->reconcile();
        $this->assertSame(GridRound::LIVE, $round->fresh()->status);

        // A กดรับไปแล้ว แล้วเจ้าของยกเลิกรอบ — อ่านสถานะรับไม่ได้ = ยังนับว่าจัดสรร (ไม่จ่ายใคร)
        $this->chain['claimed'][0][self::A] = true;
        $this->chain['rounds'][0]['revoked'] = true;
        $this->claimReadFails = true;
        $this->rounds()->reconcile();
        $this->assertSame(GridRound::REVOKED, $round->fresh()->status);
        $this->assertSame(2, $round->entries()->whereNull('claimed')->count());
        $this->assertSame(0.0, $this->dueFor(self::A));
        $this->assertSame(0.0, $this->dueFor(self::B));

        // อ่านได้แล้ว: A รับไปแล้ว → ไม่ได้ซ้ำ · B ยังไม่รับ → กลับมารอรอบถัดไป
        $this->claimReadFails = false;
        $this->rounds()->reconcile();
        $this->assertTrue((bool) $round->entries()->where('wallet', self::A)->value('claimed'));
        $this->assertFalse((bool) $round->entries()->where('wallet', self::B)->value('claimed'));
        $this->assertSame(0.0, $this->dueFor(self::A));
        $this->assertEqualsWithDelta($round->entries()->where('wallet', self::B)->value('amount_idtc'), $this->dueFor(self::B), 0.000002);
    }

    public function test_discarding_a_draft_frees_its_allocation(): void
    {
        $this->rounds()->build(self::ADMIN, null);
        [$instant] = GridRound::query()->orderBy('chain_round')->get()->all();
        $this->loginAs(self::VIEWER, 'viewer')->post('/admin/grid/rounds/'.$instant->id.'/discard')->assertForbidden();

        $this->loginAs(self::ADMIN, 'admin')->post('/admin/grid/rounds/'.$instant->id.'/discard')->assertRedirect('/admin/grid/rounds');
        $this->assertSame(2, GridRound::query()->where('status', GridRound::DISCARDED)->count()); // ทิ้งทั้งชุด
        $this->assertGreaterThan(1, $this->dueFor(self::A));
        $this->assertSame(1, AuditLog::query()->where('action', 'grid.round.discard')->count());

        // รอบที่ประกาศแล้วทิ้งไม่ได้
        $this->rounds()->build(self::ADMIN, null);
        $live = GridRound::query()->where('status', GridRound::DRAFT)->orderBy('chain_round')->first();
        $this->publish($live);
        $this->post('/admin/grid/rounds/'.$live->id.'/discard')->assertSessionHas('error');
        $this->assertSame(GridRound::LIVE, $live->fresh()->status);
    }

    public function test_changing_the_contract_drops_drafts_that_can_no_longer_be_published(): void
    {
        $this->rounds()->build(self::ADMIN, null);
        $other = '0x9999999999999999999999999999999999999999';
        config(['idtc.contracts.rewards' => $other]);
        $this->assertNull($this->rounds()->draft());
        $this->rounds()->reconcile();
        $this->assertSame(2, GridRound::query()->where('status', GridRound::DISCARDED)->count());
        $this->assertGreaterThan(1, $this->dueFor(self::A));
    }

    public function test_a_build_already_running_blocks_a_second_one(): void
    {
        $lock = Cache::lock('idtc:grid:rounds:build', 120);
        $this->assertTrue($lock->get());
        try {
            $res = $this->rounds()->build(self::ADMIN, null);
            $this->assertFalse($res['ok']);
            $this->assertSame(0, GridRound::query()->count());
        } finally {
            $lock->release();
        }
        $this->assertTrue($this->rounds()->build(self::ADMIN, null)['ok']);
    }

    public function test_round_pages_by_role(): void
    {
        $this->loginAs(self::VIEWER, 'viewer')->get('/admin/grid/rounds')->assertOk()->assertSee('ยอดที่จะเข้ารอบถัดไป')->assertDontSee('สร้างรอบจ่าย</button>', false);
        $this->post('/admin/grid/rounds')->assertForbidden();

        $this->loginAs(self::ADMIN, 'admin')->get('/admin/grid/rounds')->assertOk()->assertSee('สร้างรอบจ่าย');
        $this->rounds()->build(self::ADMIN, 'หมายเหตุทดสอบ');
        [$instant, $deferred] = GridRound::query()->orderBy('chain_round')->get()->all();

        $this->get('/admin/grid/rounds/'.$instant->id)->assertOk()->assertSee('หมายเหตุทดสอบ')->assertSee('ประกาศบนเชน')->assertSee('ยังขาด');
        // รอบที่สองต้องรอรอบแรกขึ้นเชนก่อน
        $this->get('/admin/grid/rounds/'.$deferred->id)->assertOk()->assertSee('ต้องประกาศรอบที่ 0 ก่อน');
        $this->chain['balance'] = '999000000000000000000000000';
        $this->get('/admin/grid/rounds/'.$instant->id)->assertOk()->assertSee('มีพอแล้ว');

        $this->loginAs(self::VIEWER)->get('/admin/grid/rounds/'.$instant->id)->assertOk()->assertDontSee('ประกาศบนเชน</button>', false);
        $csv = $this->get('/admin/grid/rounds/'.$instant->id.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString(self::A, $csv);
        $this->assertStringContainsString($instant->root, $csv);

        config(['idtc.contracts.rewards' => null]);
        $this->get('/admin/grid/rounds')->assertOk()->assertSee('รอ deploy');
    }

    public function test_publish_params_are_validated(): void
    {
        $root = '0x'.str_repeat('Ab', 32);
        $this->assertSame(['id' => '3', 'root' => strtolower($root), 'total' => '5', 'unlockAt' => '0'],
            ChainActions::cleanParams('rewards.addRound', ['id' => '3', 'root' => $root, 'total' => '5', 'unlockAt' => '0']));
        $this->assertNull(ChainActions::cleanParams('rewards.addRound', ['id' => '3', 'root' => '0x1234', 'total' => '5', 'unlockAt' => '0']));
    }

    public function test_a_member_sees_what_they_can_claim(): void
    {
        app(Settings::class)->put('grid.enabled', true);
        $this->rounds()->build(self::ADMIN, null);
        [$instant] = GridRound::query()->orderBy('chain_round')->get()->all();

        $me = $this->withSession(['grid' => ['address' => self::A]])->getJson('/grid/me')->assertOk();
        $this->assertSame([], $me->json('claims.items')); // ยังไม่ประกาศ = ยังไม่แสดง

        $this->publish($instant);
        $this->rounds()->reconcile();
        $me = $this->getJson('/grid/me')->assertOk();
        $item = $me->json('claims.items.0');
        $this->assertSame(0, $item['round']);
        $this->assertFalse($item['claimed']);
        $this->assertSame($instant->entries()->where('wallet', self::A)->value('amount'), $item['amount']);
        $this->assertTrue(GridMerkle::verify($item['proof'], $instant->root, GridMerkle::leaf(self::REWARDS, 0, self::A, $item['amount'])));

        $this->chain['claimed'][0][self::A] = true;
        $this->assertTrue($this->getJson('/grid/me')->json('claims.items.0.claimed'));
    }
}

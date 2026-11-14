<?php

namespace Tests\Feature\Binary;

use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Services\Binary\BinaryLedger;
use App\Services\Binary\BinaryMap;
use App\Services\Binary\BinarySample;
use App\Services\Grid\GridPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * หลังบ้าน > Binary > ผังสายงาน (/admin/binary/map): ต้นไม้สองขาของแผน Binary เป็นหน้าของตัวเอง ไม่ใช้ผังวงของ IDTC Grid
 *
 * ผังที่ใช้ทดสอบ:        A
 *                      /   \
 *                     B     C
 *                    / \     \
 *                   D   E     F
 *                  /
 *                 G                 และผังแยก X ─ Y (ขาซ้าย)
 */
class BinaryMapTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const VIEWER = '0x3333333333333333333333333333333333333333';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        URL::forceRootUrl('http://localhost');
        $this->setUpAdmins();
        Carbon::setTestNow('2026-12-03 12:00:00');
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

    private function member(string $who, ?string $parent = null, ?string $leg = null): void
    {
        GridMember::query()->create([
            'wallet' => $this->w($who),
            'referrer' => $parent ? $this->w($parent) : null,
            'bound_at' => $parent ? '2026-12-01 00:00:00' : null,
            'bin_parent' => $parent ? $this->w($parent) : null,
            'bin_leg' => $leg,
            'bin_placed_at' => $parent ? '2026-12-01 00:00:00' : null,
        ]);
    }

    private function tree(): void
    {
        $this->member('A');
        $this->member('B', 'A', 'L');
        $this->member('C', 'A', 'R');
        $this->member('D', 'B', 'L');
        $this->member('E', 'B', 'R');
        $this->member('F', 'C', 'R');
        $this->member('G', 'D', 'L');
        $this->member('X');
        $this->member('Y', 'X', 'L');
    }

    private function deposit(string $who, float $usdt, string $at = '2026-12-02 10:00:00'): void
    {
        $this->seq++;
        $ts = CarbonImmutable::parse($at);
        BinaryDeposit::query()->create([
            'tx_hash' => '0x'.str_pad(dechex($this->seq), 64, 'f', STR_PAD_LEFT), 'log_index' => 0, 'wallet' => $this->w($who), 'to' => '0x399679922b673fee28c507b07ff12e17abfd78a8',
            'amount' => (string) (int) round($usdt * 1e6), 'usdt' => $usdt, 'block_number' => $this->seq, 'block_time' => $ts,
            'week' => GridPlan::purchaseWeek($ts->getTimestamp()), 'status' => 'ok', 'price' => '0.0000015',
            'idtc' => $usdt / 0.0000015, 'idtc_micro' => BinaryDeposit::microIdtc((string) (int) round($usdt * 1e6), '0.0000015'), 'pool_usdt' => $usdt * 0.30,
        ]);
    }

    /** @return array<string, mixed> ข้อมูลที่หน้าเว็บส่งให้ตัววาดผัง */
    private function payload(TestResponse $res): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/json" data-bmap>(.*?)<\/script>/s', (string) $res->getContent(), $m), 'no map payload on the page');

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<mixed>> ชื่อ => แถว
     */
    private function rows(array $payload): array
    {
        $names = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'X', 'Y'] as $n) {
            $names[$this->w($n)] = $n;
        }
        $out = [];
        foreach ($payload['nodes'] as $row) {
            $out[$names[$row[0]] ?? $row[0]] = $row;
        }

        return $out;
    }

    public function test_map_is_its_own_page_and_keeps_left_on_the_left(): void
    {
        $this->tree();
        $res = $this->loginAsOwner()->get('/admin/binary/map')->assertOk()
            ->assertSee('ผังสายงาน Binary')->assertSee('แสดง 7 จาก 7 กระเป๋า')
            ->assertSee('admin/binary/map"', false)             // อยู่ในเมนู Binary
            ->assertDontSee('data-map>', false);                 // ไม่ใช่ผังวงของ Grid

        $p = $this->payload($res);
        $this->assertSame($this->w('A'), $p['root']);            // ไม่ระบุกระเป๋า = บนสุดของผังที่ใหญ่ที่สุด
        $rows = $this->rows($p);
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G'], array_keys($rows));   // ชั้นต่อชั้น แม่ก่อนลูก ซ้ายก่อนขวา

        $at = array_flip(array_keys($rows));
        // [wallet, แม่, ขา, ชั้น, …]
        $this->assertSame([-1, null, 0], array_slice($rows['A'], 1, 3));
        $this->assertSame([$at['A'], 'L', 1], array_slice($rows['B'], 1, 3));
        $this->assertSame([$at['A'], 'R', 1], array_slice($rows['C'], 1, 3));
        $this->assertSame([$at['B'], 'L', 2], array_slice($rows['D'], 1, 3));
        $this->assertSame([$at['B'], 'R', 2], array_slice($rows['E'], 1, 3));
        $this->assertSame([$at['C'], 'R', 2], array_slice($rows['F'], 1, 3));   // C มีแต่ขาขวา — ยังเป็นขาขวา
        $this->assertSame([$at['D'], 'L', 3], array_slice($rows['G'], 1, 3));

        // จำนวนคนต่อขานับจากผังที่วาด
        $this->assertSame([4, 2], [$rows['A'][5], $rows['A'][6]]);
        $this->assertSame([2, 1], [$rows['B'][5], $rows['B'][6]]);
        $this->assertSame([0, 1], [$rows['C'][5], $rows['C'][6]]);
        $this->assertSame(0, array_sum(array_column($p['nodes'], 12)));          // ไม่มีอะไรถูกพับ
    }

    public function test_map_shows_the_plan_deposits_from_the_ledger(): void
    {
        $this->tree();
        $this->deposit('B', 100);
        $this->deposit('D', 50);
        $this->deposit('C', 4);                                  // ต่ำกว่าขั้นต่ำ 10 USDT

        $rows = $this->rows($this->payload($this->loginAsOwner()->get('/admin/binary/map')->assertOk()));
        $book = app(BinaryLedger::class)->compute();
        $a = $book['nodes'][$this->w('A')];

        $this->assertEqualsWithDelta(100.0, $rows['B'][4], 1e-9);
        $this->assertSame(BinaryMap::ACTIVE, $rows['B'][11] & BinaryMap::ACTIVE);
        $this->assertSame(0, $rows['C'][11] & BinaryMap::ACTIVE);
        $this->assertSame(0, $rows['E'][11] & BinaryMap::ACTIVE);
        // ยอดขาเป็นตัวเลขเดียวกับที่ใช้คิดรางวัล
        $this->assertEqualsWithDelta($a['l'], $rows['A'][7], 0.005);
        $this->assertEqualsWithDelta($a['r'], $rows['A'][8], 0.005);
        $this->assertEqualsWithDelta(150.0, $rows['A'][7], 0.005);
        $this->assertEqualsWithDelta(4.0, $rows['A'][8], 0.005);
    }

    public function test_held_wallet_is_marked(): void
    {
        $this->tree();
        GridMember::query()->where('wallet', $this->w('E'))->update(['held_at' => now(), 'held_reason' => 'test', 'held_by' => self::VIEWER]);

        $rows = $this->rows($this->payload($this->loginAsOwner()->get('/admin/binary/map')->assertOk()));
        $this->assertSame(BinaryMap::HELD, $rows['E'][11] & BinaryMap::HELD);
        $this->assertSame(0, $rows['D'][11] & BinaryMap::HELD);
    }

    public function test_starting_from_a_wallet_folds_what_is_deeper(): void
    {
        $this->tree();
        $res = $this->loginAsOwner()->get('/admin/binary/map?root='.$this->w('B').'&depth=1')->assertOk()
            ->assertSee('แสดง 3 จาก 4 กระเป๋า')->assertSee('ขึ้นหนึ่งชั้น')
            ->assertSee('admin/binary/map?root='.$this->w('A'), false);            // ทางขึ้นไปหา A

        $rows = $this->rows($this->payload($res));
        $this->assertSame(['B', 'D', 'E'], array_keys($rows));
        $this->assertSame(1, $rows['D'][12]);                    // G ถูกพับไว้ใต้ D
        $this->assertSame(0, $rows['E'][12]);
        $this->assertSame([1, 0], [$rows['D'][5], $rows['D'][6]]);

        // ล่างสุดของขอบซ้าย/ขวาเป็นลิงก์
        $this->get('/admin/binary/map')->assertOk()
            ->assertSee('admin/binary/map?root='.$this->w('G'), false)->assertSee('admin/binary/map?root='.$this->w('F'), false);
    }

    public function test_other_trees_are_listed_and_can_be_opened(): void
    {
        $this->tree();
        $res = $this->loginAsOwner()->get('/admin/binary/map')->assertOk()
            ->assertSee('data-testid="bmap-tops"', false)->assertSee('admin/binary/map?root='.$this->w('X'), false);
        $this->assertSame($this->w('A'), $this->payload($res)['root']);

        $rows = $this->rows($this->payload($this->get('/admin/binary/map?root='.$this->w('X'))->assertOk()->assertSee('แสดง 2 จาก 2 กระเป๋า')));
        $this->assertSame(['X', 'Y'], array_keys($rows));
        $this->assertSame('L', $rows['Y'][2]);

        // กระเป๋าที่ไม่มีสาย: แสดงตัวเดียว
        $this->member('Z');
        $this->assertCount(1, $this->payload($this->get('/admin/binary/map?root='.$this->w('Z'))->assertOk())['nodes']);
    }

    public function test_unknown_or_malformed_wallet_shows_a_notice_and_the_main_tree(): void
    {
        $this->tree();
        $this->loginAsOwner();

        foreach (['0x'.str_repeat('e', 40), 'nope', '<script>alert(1)</script>', '0x'.str_repeat('0', 40)] as $bad) {
            $res = $this->get('/admin/binary/map?root='.urlencode($bad))->assertOk()->assertSee('ไม่พบกระเป๋า');
            $this->assertSame($this->w('A'), $this->payload($res)['root']);
            $res->assertDontSee('<script>alert(1)</script>', false);
        }
    }

    public function test_empty_plan_has_no_canvas(): void
    {
        $this->loginAsOwner()->get('/admin/binary/map')->assertOk()
            ->assertSee('data-testid="bmap-empty"', false)->assertSee('ยังไม่มีผัง')->assertDontSee('data-bmap', false);
    }

    public function test_page_follows_the_binary_permission(): void
    {
        $this->tree();
        $this->get('/admin/binary/map')->assertRedirect('/admin/login');

        $this->loginAs(self::VIEWER, 'viewer', ['grid_map' => 'view']);
        $this->get('/admin/binary/map')->assertForbidden();
        $this->get('/admin/grid/map')->assertOk()->assertDontSee('admin/binary/map', false);   // ไม่มีสิทธิ์ = ไม่มีลิงก์

        $res = $this->loginAs(self::VIEWER, 'viewer', ['binary' => 'view'])->get('/admin/binary/map')->assertOk();
        $this->assertNull($this->payload($res)['memberUrl']);     // ไม่มีสิทธิ์ดูสมาชิก Grid = ไม่มีปุ่มข้อมูลกระเป๋า

        $res = $this->loginAs(self::VIEWER, 'viewer', ['binary' => 'view', 'grid_members' => 'view'])->get('/admin/binary/map')->assertOk();
        $this->assertStringContainsString('/admin/grid/members/0x', (string) $this->payload($res)['memberUrl']);
    }

    public function test_grid_map_no_longer_draws_the_binary_tree(): void
    {
        $this->tree();
        $this->loginAsOwner();

        $this->get('/admin/grid/map')->assertOk()->assertSee('ผังสายงาน Binary (หน้าแยก)')->assertDontSee('ดูผังสายแนะนำ')->assertDontSee('tree=binary', false);
        // ลิงก์เก่าพาไปหน้าใหม่
        $this->get('/admin/grid/map?tree=binary')->assertRedirect('/admin/binary/map');
        $this->get('/admin/grid/map?tree=binary&root='.$this->w('B'))->assertRedirect('/admin/binary/map?root='.$this->w('B'));
        $this->get('/admin/grid/map?tree=binary&root=nope')->assertRedirect('/admin/binary/map');
        $this->get('/admin/binary/tree')->assertOk()->assertSee('admin/binary/map"', false)->assertDontSee('tree=binary', false);
    }

    public function test_slice_respects_the_node_limit_and_never_splits_a_pair(): void
    {
        $this->tree();
        $book = app(BinaryLedger::class)->compute();
        $s = app(BinaryMap::class)->slice($book, null, 6, 10.0, 3);

        $this->assertSame(3, $s['shown']);
        $this->assertSame(7, $s['total']);
        $this->assertSame([$this->w('A'), $this->w('B'), $this->w('C')], array_column($s['nodes'], 0));
        $this->assertSame([0, 3, 1], array_column($s['nodes'], 12));             // ใต้ B มี D E G · ใต้ C มี F
        $this->assertSame(1, $s['others']);
        $this->assertSame($this->w('G'), $s['edge']['L']);
        $this->assertSame($this->w('F'), $s['edge']['R']);
    }

    // ───────────── โหมดตัวอย่าง (ปุ่ม "ตัวอย่าง") ─────────────

    public function test_sample_button_is_offered_on_the_real_map_and_on_the_empty_state(): void
    {
        // ยังไม่มีสมาชิก (สภาพของเว็บจริงก่อนเปิดแผน): มีปุ่มไปดูตัวอย่าง
        $this->loginAsOwner()->get('/admin/binary/map')->assertOk()
            ->assertSee('data-testid="bmap-empty"', false)->assertSee('data-testid="bmap-sample-btn"', false)
            ->assertSee('ตัวอย่าง')->assertSee('admin/binary/map?sample=small', false)->assertDontSee('data-testid="bmap-sample"', false);

        $this->tree();
        $res = $this->get('/admin/binary/map')->assertOk()->assertSee('data-testid="bmap-sample-btn"', false)->assertDontSee('ข้อมูลตัวอย่าง');
        $this->assertSame($this->w('A'), $this->payload($res)['root']);          // ข้อมูลจริงยังเป็นค่าเริ่มต้น
    }

    public function test_sample_mode_draws_made_up_wallets_and_never_touches_real_data(): void
    {
        $this->tree();
        $this->deposit('B', 100);
        $members = GridMember::query()->count();
        $deposits = BinaryDeposit::query()->count();

        $res = $this->loginAsOwner()->get('/admin/binary/map?sample=mid')->assertOk()
            ->assertSee('ผังสายงาน Binary (ตัวอย่าง)')->assertSee('data-testid="bmap-sample"', false)->assertSee('ข้อมูลตัวอย่าง')
            ->assertSee('data-testid="bmap-real"', false)->assertSee('กลับไปข้อมูลจริง')
            ->assertSee('ทีมเริ่มต้น 20 คน')->assertSee('ทีมกลาง 150 คน')->assertSee('ทีมใหญ่ 900 คน')
            ->assertSee('จาก 150 กระเป๋า')->assertDontSee('data-testid="bmap-sample-btn"', false);
        $p = $this->payload($res);

        // ไม่มีกระเป๋าจริงปนอยู่ และไม่มีลิงก์ไปหน้าจัดสาย/ข้อมูลสมาชิกของกระเป๋าสมมติ
        $real = array_map(fn ($n) => $this->w($n), ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'X', 'Y']);
        $this->assertSame([], array_values(array_intersect(array_column($p['nodes'], 0), $real)));
        $this->assertNull($p['treeUrl']);
        $this->assertNull($p['memberUrl']);
        $this->assertStringContainsString('sample=mid', $p['mapUrl']);
        // ไม่เขียนอะไรลงฐานข้อมูล
        $this->assertSame($members, GridMember::query()->count());
        $this->assertSame($deposits, BinaryDeposit::query()->count());

        // เปิดซ้ำได้ผังเดิม → ลิงก์ ?root= ในโหมดตัวอย่างใช้ได้ และทุกลิงก์ในหน้ายังอยู่ในโหมดตัวอย่าง
        $again = $this->payload($this->get('/admin/binary/map?sample=mid')->assertOk());
        $this->assertSame($p['nodes'], $again['nodes']);
        $child = $p['nodes'][1][0];
        $sub = $this->get('/admin/binary/map?sample=mid&depth=4&root='.$child)->assertOk()
            ->assertSee('ขึ้นหนึ่งชั้น')->assertSee('root='.$p['root'].'&amp;depth=4&amp;sample=mid', false)
            ->assertSee('name="sample" value="mid"', false);
        $this->assertSame($child, $this->payload($sub)['root']);
        $this->assertDoesNotMatchRegularExpression('/admin\/binary\/map\?(root=0x[0-9a-f]{40}&amp;)?depth=\d+"/', (string) $sub->getContent());

        // กระเป๋าจริงไม่อยู่ในผังตัวอย่าง · ชื่อชุดที่ไม่รู้จัก = ข้อมูลจริง
        $this->get('/admin/binary/map?sample=mid&root='.$this->w('A'))->assertOk()->assertSee('ในผังตัวอย่าง');
        $this->assertSame($this->w('A'), $this->payload($this->get('/admin/binary/map?sample=nope')->assertOk()->assertDontSee('ข้อมูลตัวอย่าง'))['root']);
    }

    public function test_sample_mode_follows_the_binary_permission(): void
    {
        $this->get('/admin/binary/map?sample=small')->assertRedirect('/admin/login');
        $this->loginAs(self::VIEWER, 'viewer', ['grid_map' => 'view'])->get('/admin/binary/map?sample=small')->assertForbidden();
        $this->loginAs(self::VIEWER, 'viewer', ['binary' => 'view'])->get('/admin/binary/map?sample=small')->assertOk()->assertSee('ข้อมูลตัวอย่าง');
    }

    public function test_every_sample_set_is_a_consistent_two_leg_tree(): void
    {
        foreach (BinarySample::SETS as $key => [$count]) {
            $book = app(BinarySample::class)->book($key, 10.0);
            $nodes = $book['nodes'];
            $this->assertSame($book, app(BinarySample::class)->book($key, 10.0), $key.' is not repeatable');

            $s = app(BinaryMap::class)->slice($book, null, BinaryMap::MAX_DEPTH, 10.0);
            $this->assertSame($count, $s['total'], $key);
            $this->assertSame($count, $s['shown'] + array_sum(array_column($s['nodes'], 12)), $key);
            $this->assertSame($count, 1 + $s['nodes'][0][5] + $s['nodes'][0][6], $key);
            $this->assertSame(1, $s['others'], $key);                            // มีผังแยกหนึ่งผังให้เห็น

            $active = fn (array $n) => $n['usdt'] >= 10.0;
            foreach ($nodes as $w => $n) {
                $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $w);
                foreach (['bl' => 'L', 'br' => 'R'] as $slot => $leg) {
                    if ($n[$slot] !== null) {
                        $this->assertSame([$w, $leg], [$nodes[$n[$slot]]['parent'], $nodes[$n[$slot]]['leg']]);
                    }
                }
                // ยอดขา = ยอดฝากของคนในขานั้น (ไม่นับกระเป๋าที่ถูกพัก)
                foreach (['bl' => 'l', 'br' => 'r'] as $slot => $vol) {
                    $kid = $n[$slot] !== null ? $nodes[$n[$slot]] : null;
                    $want = $kid === null ? 0.0 : ($kid['held'] ? 0.0 : $kid['usdt']) + $kid['l'] + $kid['r'];
                    $this->assertEqualsWithDelta($want, $n[$vol], 1e-6);
                }
                $this->assertSame($active($n) && $n['dl'] >= 1 && $n['dr'] >= 1, $n['q']);
                $this->assertEqualsWithDelta($n['q'] ? abs($n['l'] - $n['r']) : 0.0, $n['cl'] + $n['cr'], 1e-6);
            }
            $this->assertGreaterThan(0, count(array_filter($nodes, fn ($n) => $n['q'])), $key);
            $this->assertGreaterThan(0, count(array_filter($nodes, fn ($n) => ! $active($n))), $key);
        }
        $this->assertGreaterThan(0, count(array_filter(app(BinarySample::class)->book('mid', 10.0)['nodes'], fn ($n) => $n['held'])));

        $this->assertSame('mid', BinarySample::key('mid'));
        foreach ([null, '', 'MID', 'nope', ['mid'], 1] as $bad) {
            $this->assertNull(BinarySample::key($bad));
        }
    }

    public function test_slice_survives_a_looped_tree(): void
    {
        $p = $this->w('P');
        $q = $this->w('Q');
        $node = fn (string $w, ?string $parent, ?string $bl) => ['wallet' => $w, 'parent' => $parent, 'leg' => 'L', 'bl' => $bl, 'br' => null, 'usdt' => 0.0, 'held' => false];
        $book = ['nodes' => [$p => $node($p, $q, $q), $q => $node($q, $p, $p)]];   // P ↔ Q ชี้หากัน (ข้อมูลเสีย)

        $s = app(BinaryMap::class)->slice($book, $p, 12, 10.0);
        $this->assertSame([$p, $q], array_column($s['nodes'], 0));
        $this->assertSame(2, $s['total']);
        $this->assertSame([$q], $s['crumbs']);                   // เดินขึ้นไม่วน

        $this->assertNull(app(BinaryMap::class)->slice($book, null, 6, 10.0)['root']);   // ไม่มีบนสุด = ไม่มีผังหลัก
    }
}

<?php

namespace Tests\Feature\Binary;

use App\Models\AuditLog;
use App\Models\BinaryDeposit;
use App\Models\GridMember;
use App\Models\GridPlanVersion;
use App\Models\GridTreeChange;
use App\Models\Purchase;
use App\Services\Binary\BinaryLedger;
use App\Services\Chain\Address;
use App\Services\Grid\GridBinder;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridPlacer;
use App\Services\Grid\GridPlan;
use App\Services\Grid\TreeEditor;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/**
 * จัดการผัง Binary: วิธีวาง 6 แบบ · คิวรอจัดสาย · จัด/ย้าย/ต่อสาย (หลังบ้าน + แม่ทีม) · เปลี่ยนผู้แนะนำ (owner)
 * หัวใจ: การย้ายมีผลตั้งแต่สัปดาห์ที่ย้าย สัปดาห์ที่ปิดแล้วคิดด้วยผังเดิม · รางวัลของการซื้อก่อนเปลี่ยนผู้แนะนำเป็นของสายเดิม
 */
class BinaryTreeTest extends TestCase
{
    use InteractsWithAdmin, RefreshDatabase;

    private const WALLET = '0x399679922b673fee28c507b07ff12e17abfd78a8';

    /** 1 USDT = 666,666.67 IDTC (อัตราใน config sale) */
    private const P = 2e6 / 3;

    private const ADMIN = '0x2222222222222222222222222222222222222222';

    private const VIEWER = '0x3333333333333333333333333333333333333333';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        URL::forceRootUrl('http://localhost');
        $this->setUpAdmins();
        Carbon::setTestNow('2026-12-01 12:00:00');
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

    private function bind(string $who, string $ref, ?string $side = null): GridMember
    {
        $row = app(GridBinder::class)->bind($this->w($who), $this->w($ref), 'msg', 'sig', null, $side);
        $this->assertInstanceOf(GridMember::class, $row, is_string($row) ? $row : '');

        return $row->fresh();
    }

    private function slot(string $who): ?string
    {
        $m = GridMember::query()->where('wallet', $this->w($who))->firstOrFail();

        return $m->bin_parent === null ? null : $m->bin_leg.':'.$m->bin_parent;
    }

    private function at(string $leg, string $parent): string
    {
        return $leg.':'.$this->w($parent);
    }

    private function mode(string $who, string $mode): void
    {
        app(TreeEditor::class)->setMode($this->w($who), $mode);
    }

    private function open(): void
    {
        $s = app(Settings::class);
        $s->put('binary.wallet', self::WALLET);
        $s->put('binary.quota', '1000000000000');
        $s->put('binary.source', 'operations');
        $s->put('binary.enabled', true);
    }

    private function deposit(string $who, float $usdt, string $at): void
    {
        $this->seq++;
        $ts = CarbonImmutable::parse($at);
        BinaryDeposit::query()->create([
            'tx_hash' => '0x'.str_pad(dechex($this->seq), 64, 'f', STR_PAD_LEFT), 'log_index' => 0, 'wallet' => $this->w($who), 'to' => self::WALLET,
            'amount' => (string) (int) round($usdt * 1e6), 'usdt' => $usdt, 'block_number' => $this->seq, 'block_time' => $ts,
            'week' => GridPlan::purchaseWeek($ts->getTimestamp()), 'status' => 'ok', 'price' => '0.0000015',
            'idtc' => $usdt / 0.0000015, 'idtc_micro' => BinaryDeposit::microIdtc((string) (int) round($usdt * 1e6), '0.0000015'), 'pool_usdt' => $usdt * 0.30,
        ]);
    }

    private function buy(string $who, float $usdt, string $at): void
    {
        $this->seq++;
        Purchase::query()->create([
            'contract' => '0x3333333333333333333333333333333333333333',
            'tx_hash' => '0x'.str_pad(dechex($this->seq), 64, '0', STR_PAD_LEFT),
            'log_index' => 0, 'buyer' => $this->w($who), 'pay_amount' => (string) (int) round($usdt * 1e6), 'tokens_out' => '0',
            'pay_usdt' => $usdt, 'tokens_idtc' => $usdt * self::P, 'block_number' => $this->seq, 'block_time' => $at,
        ]);
    }

    /** ย้าย/จัดด้วยบริการจริง ณ เวลา $at (แอดมิน) */
    private function move(string $who, string $parent, string $leg, string $at): GridTreeChange
    {
        $now = Carbon::now();
        Carbon::setTestNow($at);
        $r = app(TreeEditor::class)->place($this->w($who), $this->w($parent), $leg, self::ADMIN, 'admin', 'test move');
        Carbon::setTestNow($now);
        $this->assertInstanceOf(GridTreeChange::class, $r, is_string($r) ? $r : '');

        return $r;
    }

    // ───────────── วิธีวาง ─────────────

    public function test_each_placement_mode_picks_the_slot_the_sponsor_chose(): void
    {
        $this->mode('A', GridPlacer::FILL);
        foreach (['B', 'C', 'D', 'E', 'F'] as $who) {
            $this->bind($who, 'A');
        }
        // เต็มชั้น อุดฟันหลอ: ไล่ทีละชั้น ซ้ายก่อนขวา
        $this->assertSame($this->at('L', 'A'), $this->slot('B'));
        $this->assertSame($this->at('R', 'A'), $this->slot('C'));
        $this->assertSame($this->at('L', 'B'), $this->slot('D'));
        $this->assertSame($this->at('R', 'B'), $this->slot('E'));
        $this->assertSame($this->at('L', 'C'), $this->slot('F'));

        $this->mode('A', GridPlacer::STRONG);                       // ขาที่คนมากกว่า (ซ้าย 3 > ขวา 2) ลงขอบนอก
        $this->bind('G', 'A');
        $this->assertSame($this->at('L', 'D'), $this->slot('G'));

        $this->mode('A', GridPlacer::RIGHT_EDGE);
        $this->bind('H', 'A');
        $this->assertSame($this->at('R', 'C'), $this->slot('H'));

        $this->mode('A', GridPlacer::LEFT_EDGE);
        $this->bind('I', 'A');
        $this->assertSame($this->at('L', 'G'), $this->slot('I'));
        $this->assertSame('L', GridMember::query()->where('wallet', $this->w('A'))->value('bin_side_pref'));   // ค่าเดิมยังตรงกัน

        $this->mode('A', GridPlacer::FILL);
        $this->bind('J', 'A', 'r');                                  // ลิงก์ระบุขา = ไล่เต็มชั้นเฉพาะในขาขวา
        $this->assertSame($this->at('L', 'F'), $this->slot('J'));

        $this->mode('A', GridPlacer::WEAK);                          // ขาอ่อน: ซ้าย 5 · ขวา 4 → ขวา
        $this->bind('K', 'A');
        $this->assertSame($this->at('R', 'H'), $this->slot('K'));
        $this->assertNull(GridPlacer::mode('bogus'));
        $this->assertSame(GridPlacer::RIGHT_EDGE, GridPlacer::modeOf(new GridMember(['bin_side_pref' => 'R'])));
    }

    public function test_manual_mode_queues_new_members_and_backfill_leaves_them_waiting(): void
    {
        $this->mode('A', GridPlacer::MANUAL);
        $b = $this->bind('B', 'A');
        $c = $this->bind('C', 'A', 'r');
        $this->assertNull($b->bin_parent);
        $this->assertNotNull($b->bin_wait_at);
        $this->assertNull($b->bin_wait_side);
        $this->assertSame('R', $c->bin_wait_side);

        $this->artisan('idtc:grid-place')->assertExitCode(0);
        $this->assertNull($this->slot('B'));
        $this->assertSame(0, app(GridPlacer::class)->missing());

        // ให้ระบบจัดจากคิว (ในทีมของผู้ชวน) · เลือกช่องเอง
        $r = app(TreeEditor::class)->placeAuto($this->w('B'), GridPlacer::WEAK, self::ADMIN, 'admin');
        $this->assertInstanceOf(GridTreeChange::class, $r);
        $this->assertSame($this->at('L', 'A'), $this->slot('B'));
        $this->assertSame('auto:weak', $r->reason);
        $this->assertSame('mode', app(TreeEditor::class)->placeAuto($this->w('C'), GridPlacer::MANUAL, self::ADMIN, 'admin'));
        $this->assertSame('not_waiting', app(TreeEditor::class)->placeAuto($this->w('B'), GridPlacer::FILL, self::ADMIN, 'admin'));
        $this->move('C', 'A', 'R', '2026-12-01 13:00:00');
        $c = $c->fresh();
        $this->assertNull($c->bin_wait_at);
        $this->assertNotNull($c->bin_placed_at);
        $this->assertSame([GridTreeChange::PLACE, GridTreeChange::PLACE], GridTreeChange::query()->orderBy('id')->pluck('kind')->all());
    }

    public function test_check_explains_every_refusal_and_warning_before_a_move(): void
    {
        $this->bind('B', 'A');
        $this->bind('C', 'A');
        $this->bind('D', 'B');
        $this->bind('E', 'C');
        $ed = app(TreeEditor::class);

        $this->assertSame('slot_taken', $ed->check($this->w('D'), $this->w('A'), 'L')['error']);
        $this->assertSame('cycle', $ed->check($this->w('B'), $this->w('D'), 'R')['error']);
        $this->assertSame('unknown_parent', $ed->check($this->w('D'), '0x'.str_repeat('9', 40), 'L')['error']);
        $this->assertSame('self', $ed->check($this->w('D'), $this->w('D'), 'L')['error']);
        $this->assertSame('same', $ed->check($this->w('D'), $this->w('B'), 'L')['error']);
        $this->assertSame('leg', $ed->check($this->w('D'), $this->w('C'), 'X')['error']);
        $this->assertSame('wallet', $ed->check('0xnope', $this->w('C'), 'R')['error']);

        $ok = $ed->check($this->w('D'), $this->w('C'), 'R');
        $this->assertNull($ok['error']);
        $this->assertSame(GridTreeChange::MOVE, $ok['info']['kind']);
        $this->assertSame(['outside_sponsor'], $ok['warnings']);    // D ถูก B ชวน แต่ไปอยู่ทีม C

        $team = $ed->check($this->w('B'), $this->w('E'), 'L');      // B ย้ายพร้อมทีม (B, D)
        $this->assertNull($team['error']);
        $this->assertSame(['team'], $team['warnings']);
        $this->assertSame(2, $team['info']['team']);
    }

    // ───────────── ประวัติผัง: สัปดาห์ที่ปิดแล้วไม่ถูกแบ่งใหม่ ─────────────

    public function test_a_move_counts_from_its_week_and_closed_weeks_keep_the_old_tree(): void
    {
        $this->bind('B', 'A');
        $this->bind('C', 'A');
        $this->bind('D', 'B');                                       // D อยู่ขาซ้ายของ A (ใต้ B)
        foreach (['A', 'B', 'C', 'D'] as $who) {
            $this->deposit($who, 100, '2026-12-02 05:00:00');
        }
        $ledger = app(BinaryLedger::class);
        $before = $ledger->compute(CarbonImmutable::parse('2026-12-09 01:00:00'));
        $this->assertEqualsWithDelta(100.0, $before['weeks'][0]['rows'][0]['matched'], 1e-9);
        $readyBefore = $before['nodes'][$this->w('A')]['ready'];
        $this->assertGreaterThan(0, $readyBefore);

        // สัปดาห์ที่ 2: ย้าย D (พร้อมทีม) ไปขาขวาของ A แล้ว D ฝากเพิ่ม
        $change = $this->move('D', 'C', 'L', '2026-12-10 00:00:00');
        $this->assertSame(GridTreeChange::MOVE, $change->kind);
        $this->assertSame(1, $change->week);
        $this->deposit('D', 100, '2026-12-10 05:00:00');

        $after = $ledger->compute(CarbonImmutable::parse('2026-12-11 00:00:00'));
        $this->assertSame($before['weeks'][0]['rows'], $after['weeks'][0]['rows']);       // สัปดาห์ที่ปิดแล้วเหมือนเดิมทุกตัว
        $this->assertEqualsWithDelta($readyBefore, $after['nodes'][$this->w('A')]['ready'], 1e-9);
        // สัปดาห์ใหม่: ยอดยกขาซ้าย 100 จับคู่กับ D 100 ที่อยู่ขาขวาแล้ว (ไม่ย้าย = ซ้าย 200 ขวา 0 ไม่ได้จับคู่)
        $row = collect($after['weeks'][1]['rows'])->firstWhere('wallet', $this->w('A'));
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(100.0, $row['matched'], 1e-9);
        $a = $after['nodes'][$this->w('A')];
        $this->assertSame([1, 2], [$a['nl'], $a['nr']]);             // ผังปัจจุบัน
    }

    public function test_grid_binary_pairing_follows_the_same_history(): void
    {
        $plan = GridPlan::defaults();
        $plan['binary'] = ['pool' => 0.02, 'pair' => 0.10, 'cap' => 1.0, 'directs' => 1];
        GridPlanVersion::query()->create(['from_week' => 0, 'plan' => $plan, 'note' => 'binary on']);
        app(GridPlan::class)->forget();
        $this->bind('B', 'A');
        $this->bind('C', 'A');
        $this->bind('D', 'B');
        foreach (['A', 'B', 'C', 'D'] as $who) {
            $this->buy($who, 100, '2026-12-02 05:00:00');
        }
        $ledger = app(GridLedger::class);
        $week0 = $ledger->compute(CarbonImmutable::parse('2026-12-09 01:00:00'))->binary[0]['rows'];

        $this->move('D', 'C', 'L', '2026-12-10 00:00:00');
        $this->buy('D', 100, '2026-12-10 05:00:00');
        $book = $ledger->compute(CarbonImmutable::parse('2026-12-11 00:00:00'));

        $this->assertSame($week0, $book->binary[0]['rows']);
        $row = collect($book->binary[1]['rows'])->firstWhere('wallet', $this->w('A'));
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(100 * self::P, $row['matched'], 1e-3);
    }

    // ───────────── เปลี่ยนผู้แนะนำ ─────────────

    public function test_changing_the_referrer_keeps_past_ring_rewards_with_the_old_line(): void
    {
        $this->bind('B', 'A');
        $this->buy('A', 100, '2026-12-02 01:00:00');
        $this->buy('Z', 100, '2026-12-02 01:00:00');
        $this->buy('B', 100, '2026-12-02 05:00:00');                // ผู้แนะนำตอนซื้อ = A

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->loginAs(self::ADMIN, 'admin')
            ->postJson('/admin/binary/tree/referrer', ['wallet' => $this->w('B'), 'referrer' => $this->w('Z'), 'reason' => 'ย้ายไปทีม Z ตามคำขอ'])
            ->assertForbidden();
        $this->loginAsOwner()
            ->postJson('/admin/binary/tree/referrer', ['wallet' => $this->w('B'), 'referrer' => $this->w('Z'), 'reason' => 'สั้น'])
            ->assertStatus(422);
        $this->loginAsOwner()
            ->postJson('/admin/binary/tree/referrer/check', ['wallet' => $this->w('A'), 'referrer' => $this->w('B')])
            ->assertOk()->assertJsonPath('ok', false);                // B อยู่ใต้ A ทางการแนะนำ = วงวน
        $this->loginAsOwner()
            ->postJson('/admin/binary/tree/referrer', ['wallet' => $this->w('B'), 'referrer' => $this->w('Z'), 'reason' => 'ย้ายไปทีม Z ตามคำขอ'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($this->w('Z'), GridMember::query()->where('wallet', $this->w('B'))->value('referrer'));
        $this->assertSame(1, AuditLog::query()->where('action', 'binary.tree.referrer')->count());

        $this->buy('B', 100, '2026-12-02 12:00:00');                // ผู้แนะนำตอนซื้อ = Z
        $book = app(GridLedger::class)->compute(CarbonImmutable::parse('2026-12-03 00:00:00'));
        $a = $book->node($this->w('A'))['wait'];
        $z = $book->node($this->w('Z'))['wait'];
        $this->assertGreaterThan(0, $a);
        $this->assertEqualsWithDelta($a, $z, 1e-6);                  // คนละการซื้อ ยอดเท่ากัน → รางวัลวง 1 เท่ากัน
    }

    // ───────────── แม่ทีมจัดสายเอง (/binary) ─────────────

    public function test_a_sponsor_places_only_their_own_queue_inside_their_own_team(): void
    {
        $this->open();
        $this->bind('X', 'Y');                                       // อีกทีมหนึ่ง
        $this->withSession(['grid' => ['address' => $this->w('A')]])
            ->postJson('/binary/side', ['mode' => 'manual'])->assertOk()->assertJsonPath('mode', 'manual');
        $this->postJson('/binary/side', ['mode' => 'bogus'])->assertStatus(422);
        $this->bind('B', 'A');
        $this->bind('C', 'A', 'r');

        $team = $this->getJson('/binary/team')->assertOk();
        $this->assertCount(2, $team->json('queue'));
        $this->assertSame('R', $team->json('queue.1.side'));
        $this->assertSame('manual', $team->json('mode'));
        $this->assertSame(strtolower($team->json('window.root')), $this->w('A'));
        $this->assertSame(strtolower($this->getJson('/binary/team?root='.$this->w('Y'))->json('window.root')), $this->w('A'));   // ทีมคนอื่น = กลับมาที่ตัวเอง
        $this->getJson('/binary/me')->assertOk()->assertJsonPath('waiting', 2)->assertJsonPath('mode', 'manual');

        $this->postJson('/binary/team/place', ['wallet' => $this->w('C'), 'parent' => $this->w('Y'), 'leg' => 'R'])
            ->assertStatus(422)->assertJsonPath('code', 'outside_team');
        $this->postJson('/binary/team/place', ['wallet' => $this->w('X'), 'parent' => $this->w('A'), 'leg' => 'R'])
            ->assertStatus(422)->assertJsonPath('code', 'not_yours');
        $this->postJson('/binary/team/place', ['wallet' => $this->w('B'), 'parent' => $this->w('A'), 'leg' => 'L'])->assertOk();
        $this->assertSame($this->at('L', 'A'), $this->slot('B'));
        $this->postJson('/binary/team/place', ['wallet' => $this->w('B'), 'parent' => $this->w('A'), 'leg' => 'R'])
            ->assertStatus(422)->assertJsonPath('code', 'not_waiting');   // ย้ายคนที่วางแล้ว = หลังบ้านเท่านั้น
        $this->postJson('/binary/team/place', ['wallet' => $this->w('C'), 'mode' => 'fill'])->assertOk();
        $this->assertSame($this->at('R', 'A'), $this->slot('C'));
        $this->assertSame(['member', 'member'], GridTreeChange::query()->orderBy('id')->pluck('actor_role')->all());

        $this->get('/binary')->assertOk()->assertSee('data-testid="binary-team"', false)->assertSee('data-testid="binary-qr-left"', false)
            ->assertSee(__('binary.mode.fill', [], 'th'));
        $this->get('/en/binary')->assertOk()->assertSee('Fill level by level', false);
    }

    public function test_team_endpoints_need_a_signed_in_member(): void
    {
        $this->open();
        $this->getJson('/binary/team')->assertStatus(401);
        $this->postJson('/binary/team/place', ['wallet' => $this->w('B'), 'mode' => 'weak'])->assertStatus(401);
    }

    // ───────────── หลังบ้าน ─────────────

    public function test_admin_tree_pages_respect_view_edit_and_owner_rights(): void
    {
        $this->bind('B', 'A');
        $this->bind('C', 'A');
        $this->bind('D', 'B');
        $body = ['wallet' => $this->w('D'), 'parent' => $this->w('C'), 'leg' => 'L', 'reason' => 'ปรับสมดุลทีม'];

        $this->loginAs(self::VIEWER, 'viewer')->get('/admin/binary/tree')->assertOk()
            ->assertSee('data-testid="admin-tree"', false)->assertDontSee('data-testid="tree-move-panel"', false)->assertDontSee('data-testid="tree-referrer"', false);
        $this->getJson('/admin/binary/tree/data')->assertOk()->assertJsonPath('window.levels.0.0.wallet', Address::checksum($this->w('A')));
        $this->getJson('/admin/binary/tree/lists')->assertOk()->assertJsonStructure(['queue', 'outside', 'history']);
        $this->postJson('/admin/binary/tree/check', $body)->assertOk()->assertJsonPath('ok', true)->assertJsonPath('info.kind', 'move');
        $this->postJson('/admin/binary/tree/place', $body)->assertForbidden();
        $this->postJson('/admin/binary/tree/mode', ['wallet' => $this->w('A'), 'mode' => 'fill'])->assertForbidden();

        $this->loginAs(self::ADMIN, 'admin')->get('/admin/binary/tree')->assertOk()
            ->assertSee('data-testid="tree-move-panel"', false)->assertDontSee('data-testid="tree-referrer"', false);
        $this->postJson('/admin/binary/tree/place', ['reason' => 'x'] + $body)->assertStatus(422);
        $this->postJson('/admin/binary/tree/place', $body)->assertOk()->assertJsonPath('ok', true);
        $this->postJson('/admin/binary/tree/place', $body)->assertStatus(422)->assertJsonPath('code', 'same');   // กดซ้ำไม่ย้ายซ้ำ
        $this->assertSame($this->at('L', 'C'), $this->slot('D'));
        $this->assertSame(1, GridTreeChange::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'binary.tree.move')->count());
        $this->postJson('/admin/binary/tree/mode', ['wallet' => $this->w('A'), 'mode' => 'fill'])->assertOk();
        $this->assertSame('fill', GridMember::query()->where('wallet', $this->w('A'))->value('bin_mode'));
        $this->postJson('/admin/binary/tree/mode', ['wallet' => $this->w('A'), 'mode' => 'nope'])->assertStatus(422);
        $this->getJson('/admin/binary/tree/lists')->assertOk()->assertJsonPath('history.0.kind', 'move')->assertJsonPath('history.0.reason', 'ปรับสมดุลทีม');

        $this->loginAsOwner()->get('/admin/binary/tree')->assertOk()->assertSee('data-testid="tree-referrer"', false);
        $this->get('/admin/binary')->assertOk()->assertSee(route('admin.binary.tree'), false);
    }

    public function test_admin_attaches_a_separate_tree_and_sees_it_in_the_outside_list(): void
    {
        $this->bind('Y', 'X');                                       // X = บนสุดของผังแยก (ไม่มีผู้แนะนำ)
        $this->bind('B', 'A');
        $this->mode('B', GridPlacer::MANUAL);
        $this->bind('Q', 'B');                                       // อยู่ในคิวของ B และฝากแล้ว
        $this->deposit('Q', 50, '2026-12-01 13:00:00');
        $this->deposit('S', 50, '2026-12-01 13:00:00');              // ฝากแต่ไม่มีผู้แนะนำ
        $lists = $this->loginAs(self::ADMIN, 'admin')->getJson('/admin/binary/tree/lists')->json();
        $wallets = array_map('strtolower', array_column($lists['outside'], 'wallet'));
        $this->assertContains($this->w('X'), $wallets);
        $this->assertContains($this->w('A'), $wallets);
        $this->assertContains($this->w('S'), $wallets);
        $this->assertNotContains($this->w('Q'), $wallets);           // อยู่ในคิวแล้ว ไม่ซ้ำในนอกผัง
        $this->assertSame([$this->w('Q')], array_map('strtolower', array_column($lists['queue'], 'wallet')));

        $this->postJson('/admin/binary/tree/place', ['wallet' => $this->w('X'), 'parent' => $this->w('B'), 'leg' => 'R', 'reason' => 'ต่อสายทีม X'])
            ->assertOk();
        $this->assertSame($this->at('R', 'B'), $this->slot('X'));
        $this->assertSame($this->at('L', 'X'), $this->slot('Y'));      // ทีมด้านล่างตามไปด้วย
        $this->assertSame(GridTreeChange::PLACE, GridTreeChange::query()->value('kind'));
        $this->assertSame(1, AuditLog::query()->where('action', 'binary.tree.place')->count());
    }
}

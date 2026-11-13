<?php

namespace App\Services\Grid;

use App\Models\GridMember;
use App\Models\GridPlanVersion;
use App\Models\GridTreeChange;
use App\Models\Purchase;
use App\Support\Idtc;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * คำนวณรางวัล IDTC Grid จากยอดซื้อจริง (ตาราง purchases) และการผูกผู้แนะนำ (grid_members)
 *
 * กติกา (ตรงกับต้นแบบ design/network):
 *  - การซื้อหนึ่งรายการจ่ายวง 1..3 นับขึ้นจากผู้ซื้อ: ผู้แนะนำตรง = วง 1 · ไม่บีบอัดสาย (วงที่ไม่มีคน/ระดับไม่ถึง คืนเข้างบ)
 *  - วง L จ่ายเมื่อสายบนมีระดับ >= L (สปาร์กปลดวง 1 · เซลล์วง 2 · กริดวง 3) · ผู้ซื้อที่มาจากลิงก์ได้ส่วนผู้ซื้อ
 *  - อัตราตามสัปดาห์ขายของการซื้อนั้น (แผนเวอร์ชันที่มีผลสัปดาห์นั้น) · วง 1 ได้โบนัสขั้นบันไดช่วงเปิดตัว
 *  - รางวัลนับเมื่อผู้ซื้อถือครบ hold_days (ก่อนหน้านั้น = รอ) · การซื้อก่อนผูกผู้แนะนำไม่นับให้สายบน
 *  - ขาย/โอนออกก่อนครบระยะ (HoldCheck จาก event Transfer) → ส่วนที่ออกไปไม่นับรางวัล คืนเข้างบ (sold)
 *    และไม่นับเป็นยอดซื้อของตัวเอง/ยอดทีม · ตั้งสัญญาเหรียญแล้ว = พร้อมจ่ายเมื่อดึงการโอนครบถึงวันครบระยะเท่านั้น
 *  - Sprint: กองรายสัปดาห์ = sprint × IDTC ที่ขายสัปดาห์นั้น แบ่งให้ระดับกริดขึ้นไปตามยอดทีมสัปดาห์นั้น
 *    (คอร์ ×2) คนเดียวไม่เกิน sprint_cap ของกอง ส่วนเกินแบ่งให้คนอื่น ที่แจกไม่หมดคืนเข้างบ
 *  - Binary (สองขา · ปิดไว้จนกว่าแผนจะตั้ง binary.pool > 0): รางวัลจับคู่รายสัปดาห์จากยอดขาอ่อน จ่ายรวมไม่เกินกอง
 *    ของสัปดาห์ ที่เหลือคืนเข้างบ — ดู binary() · ตำแหน่งในผังมาจาก GridPlacer ตอนผูกผู้แนะนำ
 *  - ระดับคิดจากยอด ณ ตอนคำนวณ · ตัวเลขทั้งหมดเป็นประมาณการจนกว่าจะสร้างรอบจ่าย (ช่วงถัดไป)
 */
final class GridLedger
{
    public const READY = 'ready';   // ครบระยะถือ รอรอบจ่าย

    public const WAIT = 'wait';     // ผู้ซื้อยังถือไม่ครบ / สัปดาห์ยังไม่ปิด

    public const HELD = 'held';     // แอดมินพักไว้ (ตรวจโกง)

    public const RANK = 'rank';     // สายบนระดับยังไม่ถึงวงนี้ → คืนงบ

    public const NONE = 'none';     // ไม่มีสายบนวงนี้ → คืนงบ

    public const SOLD = 'sold';     // ผู้ซื้อขาย/โอนออกก่อนครบระยะถือ → คืนงบ

    public const RANKS = ['none', 'spark', 'cell', 'grid', 'core'];

    public function __construct(protected GridPlan $plans, protected HoldCheck $holds) {}

    /** คำนวณ (แคช 60 วินาที ตามลายนิ้วมือข้อมูล) */
    public function book(?CarbonInterface $now = null, bool $fresh = false): GridBook
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        if ($fresh) {
            return $this->compute($now);
        }
        $fp = implode(':', [
            Purchase::query()->count(), (int) Purchase::query()->max('id'),
            GridMember::query()->count(), (string) GridMember::query()->max('updated_at'), (int) GridTreeChange::query()->max('id'),
            GridPlanVersion::query()->count(), (string) GridPlanVersion::query()->max('updated_at'),
            DB::table('token_transfers')->count(), (int) DB::table('token_transfers')->max('id'), (string) $this->holds->coveredUntil(), (string) Idtc::contract('token'),
            intdiv($now->getTimestamp(), 60),
        ]);

        // แคชเป็น array ล้วน — Laravel ไม่ unserialize object ที่ไม่ได้อนุญาตจากแคช (cache.serializable_classes)
        $data = Cache::remember('idtc:grid:book:'.md5($fp), 60, fn () => $this->compute($now)->toArray());

        return is_array($data) ? GridBook::fromArray($data) : $this->compute($now);
    }

    public function compute(CarbonInterface $now): GridBook
    {
        $nowTs = $now->getTimestamp();
        $nowWeek = GridPlan::weekAt($nowTs);
        $current = $this->plans->current($now);
        $holdSec = (int) $current['hold_days'] * 86400;
        $req = $current['rank'];

        // ---- nodes ----
        $nodes = [];
        $blank = fn (string $w) => [
            'wallet' => $w, 'referrer' => null, 'bound_at' => null, 'member' => false, 'held' => false,
            'own_usdt' => 0.0, 'own_idtc' => 0.0, 'bought_usdt' => 0.0, 'cut_idtc' => 0.0, 'gap' => false, 'buys' => 0, 'first_at' => null,
            'children' => [], 'directs' => 0, 'team_usdt' => 0.0, 'team_count' => 0, 'rank' => 0, 'depth' => 0,
            'week_team' => [], 'ready' => 0.0, 'wait' => 0.0, 'held_amt' => 0.0, 'sprint' => 0.0,
            // ผัง Binary: คนเหนือ/ขา · ลูกซ้าย/ขวา · จำนวนคนและยอด (IDTC) ต่อขา · ยอดยกไป · เพื่อนตรงต่อขา · ผ่านเงื่อนไข · รางวัลจับคู่รวม
            'bin_parent' => null, 'bin_leg' => null, 'bl' => null, 'br' => null, 'bin_nl' => 0, 'bin_nr' => 0, 'bin_l' => 0.0, 'bin_r' => 0.0,
            'bin_cl' => 0.0, 'bin_cr' => 0.0, 'bin_dl' => 0, 'bin_dr' => 0, 'bin_q' => false, 'bin' => 0.0,
        ];
        // ประวัติการย้ายสาย/เปลี่ยนผู้แนะนำ — ผู้แนะนำเดิมยังต้องมีตัวตนในสมุด (รางวัลของการซื้อก่อนเปลี่ยนเป็นของเขา)
        $changes = BinaryTree::changes();
        foreach ($changes as $c) {
            foreach ([$c['from_referrer'], $c['to_referrer']] as $r) {
                if ($r !== null) {
                    $nodes[$r] ??= $blank($r);
                }
            }
        }
        $members = [];
        foreach (GridMember::query()->orderBy('id')->get(['wallet', 'referrer', 'bound_at', 'held_at', 'bin_parent', 'bin_leg', 'bin_placed_at']) as $m) {
            $w = $m->wallet;
            $members[$w] = ['referrer' => $m->referrer, 'parent' => $m->bin_parent, 'leg' => $m->bin_leg, 'placed' => $m->bin_placed_at?->getTimestamp()];
            $nodes[$w] ??= $blank($w);
            $nodes[$w]['member'] = true;
            $nodes[$w]['referrer'] = $m->referrer;
            $nodes[$w]['bound_at'] = $m->bound_at?->getTimestamp();
            $nodes[$w]['held'] = $m->held_at !== null;
            if ($m->referrer !== null) {
                $nodes[$m->referrer] ??= $blank($m->referrer);
            }
            if ($m->bin_parent !== null && in_array($m->bin_leg, [GridPlacer::LEFT, GridPlacer::RIGHT], true)) {
                $nodes[$m->bin_parent] ??= $blank($m->bin_parent);
                $slot = $m->bin_leg === GridPlacer::LEFT ? 'bl' : 'br';
                if ($nodes[$m->bin_parent][$slot] === null && $m->bin_parent !== $w) {   // ตำแหน่งซ้ำ/ชี้ตัวเอง (ไม่ควรเกิด) = ไม่นับในผัง
                    $nodes[$m->bin_parent][$slot] = $w;
                    $nodes[$w]['bin_parent'] = $m->bin_parent;
                    $nodes[$w]['bin_leg'] = $m->bin_leg;
                }
            }
        }

        $purchases = Purchase::query()->orderBy('block_time')->orderBy('id')->toBase()
            ->get(['id', 'tx_hash', 'log_index', 'buyer', 'pay_usdt', 'tokens_idtc', 'block_number', 'block_time']);

        // ---- ถือครบจริงไหม (event Transfer) ----
        $hold = $this->holds->evaluate($purchases->map(fn ($p) => [
            'id' => (int) $p->id, 'buyer' => strtolower($p->buyer), 'idtc' => (float) $p->tokens_idtc,
            'block' => (int) $p->block_number, 'log' => (int) $p->log_index,
            'at' => $p->block_time ? CarbonImmutable::parse($p->block_time)->getTimestamp() : $nowTs,
        ])->all(), $holdSec);
        $kept = fn ($p): float => (float) ($hold['lots'][(int) $p->id]['kept'] ?? 1.0);
        // ครบระยะแล้ว และ (ไม่ได้เปิดตรวจ หรือ ดึงการโอนครบถึงวันครบระยะแล้ว)
        $settled = fn (int $until): bool => $until <= $nowTs && (! $hold['enabled'] || ($hold['covered'] !== null && $until <= $hold['covered']));

        foreach ($purchases as $p) {
            $w = strtolower($p->buyer);
            $nodes[$w] ??= $blank($w);
            $f = $kept($p);
            $nodes[$w]['own_usdt'] += (float) $p->pay_usdt * $f;
            $nodes[$w]['own_idtc'] += (float) $p->tokens_idtc * $f;
            $nodes[$w]['bought_usdt'] += (float) $p->pay_usdt;
            $nodes[$w]['cut_idtc'] += (float) $p->tokens_idtc * (1 - $f);
            $nodes[$w]['gap'] = isset($hold['gaps'][$w]);
            $nodes[$w]['buys']++;
            $ts = $p->block_time ? CarbonImmutable::parse($p->block_time)->getTimestamp() : $nowTs;
            $nodes[$w]['first_at'] ??= $ts;
        }

        // ---- tree: กันวงวน (ไม่ควรเกิด เพราะตอนผูกตรวจแล้ว) แล้วเรียงจากล่างขึ้นบน ----
        $depthOf = [];
        foreach (array_keys($nodes) as $w) {
            $path = [];
            $onPath = [];
            $cur = $w;
            while ($cur !== null && ! isset($depthOf[$cur])) {
                if (isset($onPath[$cur])) {          // วงวน: ตัดลิงก์ของตัวสุดท้ายที่ปิดวง
                    $nodes[$path[count($path) - 1]]['referrer'] = null;
                    $cur = null;
                    break;
                }
                $onPath[$cur] = true;
                $path[] = $cur;
                $cur = $nodes[$cur]['referrer'];
            }
            $d = $cur === null ? -1 : $depthOf[$cur];
            for ($i = count($path) - 1; $i >= 0; $i--) {
                $depthOf[$path[$i]] = ++$d;
            }
        }
        foreach ($nodes as $w => $n) {
            $nodes[$w]['depth'] = $depthOf[$w] ?? 0;
            if ($n['referrer'] !== null) {
                $nodes[$n['referrer']]['children'][] = $w;
            }
        }

        // ยอดทีมรายสัปดาห์ (ไม่รวมตัวเอง) — ใช้ทำคะแนน Sprint
        $ownWeek = [];
        foreach ($purchases as $p) {
            $w = strtolower($p->buyer);
            $k = GridPlan::purchaseWeek($p->block_time ? CarbonImmutable::parse($p->block_time)->getTimestamp() : $nowTs);
            $ownWeek[$w][$k] = ($ownWeek[$w][$k] ?? 0) + (float) $p->pay_usdt * $kept($p);
        }

        $order = array_keys($nodes);
        usort($order, fn ($a, $b) => $nodes[$b]['depth'] <=> $nodes[$a]['depth']);
        $subWeek = [];
        foreach ($order as $w) {
            $n = &$nodes[$w];
            $directs = 0;
            foreach ($n['children'] as $c) {
                $cn = $nodes[$c];
                $n['team_usdt'] += $cn['own_usdt'] + $cn['team_usdt'];
                $n['team_count'] += 1 + $cn['team_count'];
                if ($req['spark_own'] <= $cn['own_usdt'] + 1e-9 && $cn['own_usdt'] > 0) {
                    $directs++;
                }
                foreach (($ownWeek[$c] ?? []) as $k => $v) {
                    $n['week_team'][$k] = ($n['week_team'][$k] ?? 0) + $v;
                }
                foreach (($subWeek[$c] ?? []) as $k => $v) {
                    $n['week_team'][$k] = ($n['week_team'][$k] ?? 0) + $v;
                }
            }
            $subWeek[$w] = $n['week_team'];
            $n['directs'] = $directs;
            $own = $n['own_usdt'];
            $n['rank'] = ($own <= 0 || $own + 1e-9 < $req['spark_own']) ? 0
                : (($directs >= $req['core_d'] && $req['core_t'] <= $n['team_usdt'] + 1e-9) ? 4
                : (($directs >= $req['grid_d'] && $req['grid_t'] <= $n['team_usdt'] + 1e-9) ? 3
                : ($directs >= $req['cell_d'] ? 2 : 1)));
            unset($n);
        }
        unset($subWeek);

        // ผู้แนะนำ ณ เวลาที่ซื้อ — เปลี่ยนผู้แนะนำแล้ว รางวัลของการซื้อก่อนหน้ายังเป็นของสายเดิม (ไม่จ่ายซ้ำ)
        $refAt = BinaryTree::referrerAt(array_map(fn ($n) => $n['referrer'], $nodes), $changes);
        if (BinaryTree::hasReferrerChanges($changes)) {
            // คะแนน Sprint รายสัปดาห์ก็ต้องเดินตามสายของตอนนั้น (ระดับยังคิดจากสายปัจจุบันเหมือนเดิม)
            foreach (array_keys($nodes) as $w) {
                $nodes[$w]['week_team'] = [];
            }
            foreach ($purchases as $p) {
                $buyer = strtolower($p->buyer);
                $ts = $p->block_time ? CarbonImmutable::parse($p->block_time)->getTimestamp() : $nowTs;
                $k = GridPlan::purchaseWeek($ts);
                $v = (float) $p->pay_usdt * $kept($p);
                $up = [$buyer => true];
                for ($a = $refAt($buyer, $ts), $i = 0; $a !== null && isset($nodes[$a]) && ! isset($up[$a]) && $i < GridBinder::MAX_WALK; $a = $refAt($a, $ts), $i++) {
                    $up[$a] = true;
                    $nodes[$a]['week_team'][$k] = ($nodes[$a]['week_team'][$k] ?? 0) + $v;
                }
            }
        }

        // ---- events ----
        $totals = [self::READY => 0.0, self::WAIT => 0.0, self::HELD => 0.0, self::RANK => 0.0, self::NONE => 0.0, self::SOLD => 0.0];
        $weeks = [];
        $events = [];
        $maxWeek = max($nowWeek, 0);
        $weekRow = fn () => [
            'usdt' => 0.0, 'idtc' => 0.0, 'buys' => 0, 'first' => 0, 'ring' => [1 => 0.0, 2 => 0.0, 3 => 0.0], 'buyer' => 0.0,
            'status' => [self::READY => 0.0, self::WAIT => 0.0, self::HELD => 0.0, self::RANK => 0.0, self::NONE => 0.0, self::SOLD => 0.0],
            'sprint_pool' => 0.0, 'sprint_paid' => 0.0, 'sprint_status' => self::WAIT,
            'binary_pool' => 0.0, 'binary_paid' => 0.0, 'binary_status' => self::WAIT,
        ];
        $binOwn = [];
        $seen = [];
        foreach ($purchases as $p) {
            $buyer = strtolower($p->buyer);
            $ts = $p->block_time ? CarbonImmutable::parse($p->block_time)->getTimestamp() : $nowTs;
            $k = GridPlan::purchaseWeek($ts);
            $maxWeek = max($maxWeek, $k);
            $weeks[$k] ??= $weekRow();
            $plan = $this->plans->at($k);
            $idtc = (float) $p->tokens_idtc;
            $usdt = (float) $p->pay_usdt;
            $w = &$weeks[$k];
            $w['usdt'] += $usdt;
            $w['idtc'] += $idtc;
            $w['buys']++;
            if (! isset($seen[$buyer])) {
                $seen[$buyer] = true;
                $w['first']++;
            }
            $confirmed = $settled($ts + $holdSec);
            $f = $kept($p);
            $bn = $nodes[$buyer];
            // ผูกหลังซื้อ (ตัวดึงยอดช้ากว่า) → การซื้อนี้ไม่นับให้สายบน
            $ref1 = $refAt($buyer, $ts);
            $linked = $ref1 !== null && $bn['bound_at'] !== null && $bn['bound_at'] <= $ts;
            // ยอดขา Binary ของสายบนในผัง — สัปดาห์ที่ผู้ซื้อยังไม่อยู่ในผัง BinaryTree ไม่ส่งยอดขึ้นเอง
            // (ต่อสายโดยแอดมินโดยไม่มีผู้แนะนำ: bound_at ว่าง = นับตั้งแต่สัปดาห์ที่อยู่ในผัง)
            if (($bn['bound_at'] === null || $bn['bound_at'] <= $ts) && ! $bn['held'] && $f > 0) {
                $binOwn[$buyer][$k] = ($binOwn[$buyer][$k] ?? 0.0) + $idtc * $f;
            }
            $rewards = [];
            $a = $linked ? $ref1 : null;
            for ($L = 1; $L <= 3; $L++) {
                $rate = GridPlan::ringRate($plan, $L, $k);
                $amt = $idtc * $rate;
                $status = match (true) {
                    $a === null => self::NONE,
                    $nodes[$a]['rank'] < $L => self::RANK,
                    $nodes[$a]['held'] || $bn['held'] => self::HELD,
                    ! $confirmed => self::WAIT,
                    default => self::READY,
                };
                foreach ($this->split($amt, $f, $status) as [$part, $st]) {
                    $rewards[] = ['kind' => 'ring', 'ring' => $L, 'to' => $a, 'rate' => $rate, 'amount' => $part, 'status' => $st];
                    $this->tally($nodes, $totals, $w, $a, $part, $st);
                    if ($st !== self::RANK && $st !== self::NONE && $st !== self::SOLD) {
                        $w['ring'][$L] += $part;
                    }
                }
                $a = $a === null ? null : $refAt($a, $ts);
            }
            $bAmt = $idtc * $plan['buyer'];
            $bStatus = ! $linked ? self::NONE : ($bn['held'] ? self::HELD : ($confirmed ? self::READY : self::WAIT));
            foreach ($this->split($bAmt, $f, $bStatus) as [$part, $st]) {
                $rewards[] = ['kind' => 'buyer', 'ring' => 0, 'to' => $linked ? $buyer : null, 'rate' => $plan['buyer'], 'amount' => $part, 'status' => $st];
                $this->tally($nodes, $totals, $w, $linked ? $buyer : null, $part, $st);
                if ($st !== self::NONE && $st !== self::SOLD) {
                    $w['buyer'] += $part;
                }
            }
            unset($w);
            $events[] = [
                'id' => (int) $p->id, 'tx' => $p->tx_hash, 'log' => (int) $p->log_index, 'buyer' => $buyer, 'at' => $ts,
                'week' => $k, 'usdt' => $usdt, 'idtc' => $idtc, 'confirm_at' => $ts + $holdSec, 'linked' => $linked, 'rewards' => $rewards,
                'kept' => $f, 'cut_at' => $hold['lots'][(int) $p->id]['cut_at'] ?? null,
            ];
        }

        // ---- Sprint ----
        $sprint = [];
        $sprintReturned = 0.0;
        $eligible = array_filter($nodes, fn ($n) => $n['rank'] >= 3);
        for ($k = 0; $k <= $maxWeek; $k++) {
            $weeks[$k] ??= $weekRow();
            $plan = $this->plans->at($k);
            $pool = $weeks[$k]['idtc'] * $plan['sprint'];
            $cap = $pool * $plan['sprint_cap'];
            $rows = [];
            foreach ($eligible as $w => $n) {
                $pts = (float) ($n['week_team'][$k] ?? 0);
                if ($pts > 0) {
                    $mult = $n['rank'] >= 4 ? 2 : 1;
                    $rows[] = ['wallet' => $w, 'points' => $pts, 'mult' => $mult, 'score' => $pts * $mult, 'share' => 0.0, 'capped' => false];
                }
            }
            $rows = self::capSplit($pool, $rows, $cap);
            usort($rows, fn ($x, $y) => $y['share'] <=> $x['share']);
            $weekEnd = GridPlan::weekStart($k + 1)->getTimestamp();
            $state = $nowTs < $weekEnd ? 'live' : ($settled($weekEnd + $holdSec) ? self::READY : self::WAIT);
            $given = 0.0;
            foreach ($rows as $r) {
                $status = $nodes[$r['wallet']]['held'] ? self::HELD : ($state === self::READY ? self::READY : self::WAIT);
                $given += $r['share'];
                $nodes[$r['wallet']]['sprint'] += $r['share'];
                $this->tally($nodes, $totals, $weeks[$k], $r['wallet'], $r['share'], $status);
            }
            $sprintReturned += max(0.0, $pool - $given);
            $weeks[$k]['sprint_pool'] = $pool;
            $weeks[$k]['sprint_paid'] = $given;
            $weeks[$k]['sprint_status'] = $state;
            $sprint[$k] = ['week' => $k, 'pool' => $pool, 'cap' => $cap, 'given' => $given, 'state' => $state, 'rows' => $rows];
        }
        // ---- Binary ----
        [$binary, $binaryReturned, $binaryPaid] = $this->binary($nodes, $weeks, $totals, $binOwn, $ownWeek, $current, $maxWeek, $nowTs, $holdSec, $settled, $members, $changes);
        ksort($weeks);

        $sold = array_sum(array_column($weeks, 'idtc'));
        $committed = $totals[self::READY] + $totals[self::WAIT] + $totals[self::HELD];
        $pending = $this->plans->pending($now);
        $maxRate = max(GridPlan::maxRate($current), $pending ? GridPlan::maxRate($pending['plan']) : 0);
        $budget = (float) $current['budget'];
        $worst = $committed + max(0.0, GridPlan::saleCap() - $sold) * $maxRate;

        return new GridBook($nodes, $events, $weeks, $sprint, [
            'ready' => $totals[self::READY],
            'wait' => $totals[self::WAIT],
            'held' => $totals[self::HELD],
            'returned' => $totals[self::RANK] + $totals[self::NONE] + $totals[self::SOLD] + $sprintReturned + $binaryReturned,
            'returned_rank' => $totals[self::RANK],
            'returned_sold' => $totals[self::SOLD],
            'returned_none' => $totals[self::NONE],
            'returned_sprint' => $sprintReturned,
            'returned_binary' => $binaryReturned,
            'binary_paid' => $binaryPaid,
            'binary_on' => GridPlan::binaryPool($current) > 0,
            'committed' => $committed,
            'sold_idtc' => $sold,
            'sold_usdt' => array_sum(array_column($weeks, 'usdt')),
            'buyers' => count($seen),
            'members' => count(array_filter($nodes, fn ($n) => $n['member'])),
            'linked' => count(array_filter($nodes, fn ($n) => $n['referrer'] !== null)),
            'budget' => $budget,
            'max_rate' => $maxRate,
            'worst' => $worst,
            'ranks' => array_count_values(array_map(fn ($n) => self::RANKS[$n['rank']], $nodes)),
            'hold' => ['enabled' => $hold['enabled'], 'covered' => $hold['covered'], 'gaps' => array_keys($hold['gaps'])],
        ], $nowWeek, $nowTs, $binary);
    }

    /**
     * รางวัลจับคู่ของแผน Binary (สองขา) — รายสัปดาห์ขาย
     *
     *  - ยอดขา = IDTC ที่คนในขานั้นซื้อ (หลังผูก · หักส่วนที่ขายออกก่อนครบระยะถือ · ไม่นับผู้ซื้อที่ถูกพัก)
     *  - สัปดาห์ k: ซ้าย = ยกมา + ยอดใหม่ · ขวา = ยกมา + ยอดใหม่ · จับคู่ = ขาที่น้อยกว่า · ส่วนเกินของขาที่มากกว่ายกไปสัปดาห์ถัดไป
     *  - รางวัลตั้งต้น = จับคู่ × binary.pair → ไม่เกินเพดานต่อคน (binary.cap ของกอง) → ถ้ารวมเกินกอง ย่อทุกคนลงเท่ากัน
     *    กองสัปดาห์ = binary.pool × IDTC ที่ขายสัปดาห์นั้น — จ่ายรวมไม่เกินกองเสมอ ที่จ่ายไม่ถึงคืนเข้างบ
     *  - ได้รางวัลตั้งแต่สัปดาห์ที่: ยอดซื้อเองสะสมถึงเกณฑ์สปาร์ก + มีเพื่อนตรงที่ซื้อถึงเกณฑ์แล้วครบ binary.directs คนในแต่ละขา
     *    คิดจากยอดสะสม "ถึงสัปดาห์นั้น" ไม่ใช่ ณ ตอนคำนวณ — คนที่ผ่านเงื่อนไขทีหลังไม่ได้ส่วนแบ่งของสัปดาห์ที่ปิดไปแล้ว
     *    (กองของสัปดาห์เก่าไม่ถูกแบ่งใหม่หลังจ่าย) · สัปดาห์ที่ยังไม่ผ่านเงื่อนไข ยอดขาไม่นับและไม่ยกยอด
     *  - สัปดาห์ที่กอง = 0 (ปิด Binary) ยอดของสัปดาห์นั้นไม่นับและไม่ยกยอด
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $weeks
     * @param  array<string, float>  $totals
     * @param  array<string, array<int, float>>  $own  กระเป๋า => [สัปดาห์ => IDTC ที่นับเป็นยอดขา]
     * @param  array<string, array<int, float>>  $ownUsdt  กระเป๋า => [สัปดาห์ => USDT ที่ซื้อเอง (ยังถือ)] ใช้ดูว่าถึงเกณฑ์สปาร์กสัปดาห์ไหน
     * @param  array<string, mixed>  $current
     * @param  array<string, array{referrer: ?string, parent: ?string, leg: ?string, placed: ?int}>  $members  สถานะผังปัจจุบัน
     * @param  list<array<string, mixed>>  $changes  ประวัติการย้ายสาย/เปลี่ยนผู้แนะนำ (BinaryTree::changes)
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: float} [สรุปรายสัปดาห์, คืนงบ, จ่ายรวม]
     */
    private function binary(array &$nodes, array &$weeks, array &$totals, array $own, array $ownUsdt, array $current, int $maxWeek, int $nowTs, int $holdSec, \Closure $settled, array $members, array $changes): array
    {
        // สัปดาห์แรกที่ยอดซื้อเองสะสมถึงเกณฑ์สปาร์ก (null = ยังไม่ถึง)
        $need = (int) $current['binary']['directs'];
        $spark = (float) $current['rank']['spark_own'];
        $sparkWeek = function (string $w) use ($ownUsdt, $spark): ?int {
            $byWeek = $ownUsdt[$w] ?? [];
            ksort($byWeek);
            $sum = 0.0;
            foreach ($byWeek as $k => $v) {
                $sum += $v;
                if ($sum > 0 && $sum + 1e-9 >= $spark) {
                    return (int) $k;
                }
            }

            return null;
        };
        // ยอดขารายสัปดาห์ + เพื่อนตรงที่ซื้อถึงเกณฑ์ต่อขา ตามผังของแต่ละสัปดาห์ (ย้ายสายไม่แตะสัปดาห์ที่ปิดแล้ว)
        // from[w] = สัปดาห์แรกที่ผ่านเงื่อนไขครบ (ยอดสะสมไม่ลดตามเวลา → ผ่านแล้วผ่านตลอด)
        $tree = BinaryTree::compute($members, $changes, $own, $sparkWeek, $need, $maxWeek);
        $legs = $tree['legs'];
        $from = $tree['from'];
        foreach ($tree['nodes'] as $w => $t) {
            if (! isset($nodes[$w])) {
                continue;
            }
            $nodes[$w]['bin_nl'] = $t['nl'];
            $nodes[$w]['bin_nr'] = $t['nr'];
            $nodes[$w]['bin_l'] = $t['l'];
            $nodes[$w]['bin_r'] = $t['r'];
            $nodes[$w]['bin_dl'] = $t['dl'];
            $nodes[$w]['bin_dr'] = $t['dr'];
            $nodes[$w]['bin_q'] = $t['q'];
        }

        $carry = [];
        $out = [];
        $returned = 0.0;
        $paid = 0.0;
        for ($k = 0; $k <= $maxWeek; $k++) {
            $b = $this->plans->at($k)['binary'];
            $rate = max(0.0, (float) $b['pool']);
            $pool = $weeks[$k]['idtc'] * $rate;
            $cap = $pool * (float) $b['cap'];
            $rows = [];
            $sum = 0.0;
            if ($rate > 0) {
                foreach ($from as $w => $since) {
                    if ($k < $since) {
                        continue;
                    }
                    $l = ($carry[$w][0] ?? 0.0) + ($legs[$w][GridPlacer::LEFT][$k] ?? 0.0);
                    $r = ($carry[$w][1] ?? 0.0) + ($legs[$w][GridPlacer::RIGHT][$k] ?? 0.0);
                    $m = min($l, $r);
                    $carry[$w] = [$l - $m, $r - $m];
                    if ($m <= 0) {
                        continue;
                    }
                    $nominal = $m * (float) $b['pair'];
                    $share = min($nominal, $cap);
                    $sum += $share;
                    $rows[] = ['wallet' => $w, 'left' => $l, 'right' => $r, 'matched' => $m, 'nominal' => $nominal, 'share' => $share, 'capped' => $nominal > $cap + 1e-9];
                }
            }
            // รวมเกินกอง → ย่อทุกคนลงเท่ากัน (จ่ายรวมพอดีกอง)
            $factor = $sum > $pool + 1e-9 ? $pool / $sum : 1.0;
            $weekEnd = GridPlan::weekStart($k + 1)->getTimestamp();
            $state = $nowTs < $weekEnd ? 'live' : ($settled($weekEnd + $holdSec) ? self::READY : self::WAIT);
            $given = 0.0;
            foreach ($rows as $i => $row) {
                $share = $row['share'] * $factor;
                $rows[$i]['share'] = $share;
                $status = $nodes[$row['wallet']]['held'] ? self::HELD : ($state === self::READY ? self::READY : self::WAIT);
                $given += $share;
                $nodes[$row['wallet']]['bin'] += $share;
                $this->tally($nodes, $totals, $weeks[$k], $row['wallet'], $share, $status);
            }
            usort($rows, fn ($x, $y) => $y['share'] <=> $x['share'] ?: strcmp($x['wallet'], $y['wallet']));
            $returned += max(0.0, $pool - $given);
            $paid += $given;
            $weeks[$k]['binary_pool'] = $pool;
            $weeks[$k]['binary_paid'] = $given;
            $weeks[$k]['binary_status'] = $state;
            $out[$k] = ['week' => $k, 'pool' => $pool, 'cap' => $cap, 'pair' => (float) $b['pair'], 'nominal' => array_sum(array_column($rows, 'nominal')),
                'given' => $given, 'factor' => $factor, 'state' => $state, 'rows' => $rows];
        }
        foreach ($carry as $w => [$l, $r]) {
            $nodes[$w]['bin_cl'] = $l;
            $nodes[$w]['bin_cr'] = $r;
        }

        return [$out, $returned, $paid];
    }

    /**
     * แบ่งกองตามสัดส่วนคะแนน ไม่มีใครเกินเพดาน ส่วนเกินแบ่งให้คนที่เหลือ (water-filling)
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function capSplit(float $pool, array $rows, float $cap): array
    {
        $left = array_keys(array_filter($rows, fn ($r) => $r['score'] > 0));
        $remain = $pool;
        for ($guard = 0; $guard < 64 && $left && $remain > 1e-9; $guard++) {
            $sum = 0.0;
            foreach ($left as $i) {
                $sum += $rows[$i]['score'];
            }
            $over = [];
            $tmp = [];
            foreach ($left as $i) {
                $tmp[$i] = $remain * $rows[$i]['score'] / $sum;
                if ($rows[$i]['share'] + $tmp[$i] > $cap + 1e-12) {
                    $over[] = $i;
                }
            }
            if (! $over) {
                foreach ($left as $i) {
                    $rows[$i]['share'] += $tmp[$i];
                }
                break;
            }
            foreach ($over as $i) {
                $remain -= $cap - $rows[$i]['share'];
                $rows[$i]['share'] = $cap;
                $rows[$i]['capped'] = true;
            }
            $left = array_values(array_diff($left, $over));
        }

        return $rows;
    }

    /**
     * แยกรางวัลเป็นส่วนที่ยังนับ (ตามสถานะเดิม) กับส่วนที่ผู้ซื้อขาย/โอนออกไปแล้ว (sold)
     * สถานะที่คืนงบอยู่แล้ว (rank/none) ไม่ต้องแยก
     *
     * @return list<array{0: float, 1: string}>
     */
    private function split(float $amt, float $kept, string $status): array
    {
        if ($kept >= 1.0 || $status === self::RANK || $status === self::NONE) {
            return [[$amt, $status]];
        }
        $out = [];
        if ($kept > 0) {
            $out[] = [$amt * $kept, $status];
        }
        $out[] = [$amt * (1 - $kept), self::SOLD];

        return $out;
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function tally(array &$nodes, array &$totals, array &$week, ?string $to, float $amt, string $status): void
    {
        $totals[$status] += $amt;
        $week['status'][$status] += $amt;
        if ($to === null) {
            return;
        }
        match ($status) {
            self::READY => $nodes[$to]['ready'] += $amt,
            self::WAIT => $nodes[$to]['wait'] += $amt,
            self::HELD => $nodes[$to]['held_amt'] += $amt,
            default => null,
        };
    }
}

<?php

namespace App\Services\Grid;

use App\Models\GridMember;
use App\Models\GridPlanVersion;
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
            GridMember::query()->count(), (string) GridMember::query()->max('updated_at'),
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
        ];
        foreach (GridMember::query()->orderBy('id')->get(['wallet', 'referrer', 'bound_at', 'held_at']) as $m) {
            $w = $m->wallet;
            $nodes[$w] ??= $blank($w);
            $nodes[$w]['member'] = true;
            $nodes[$w]['referrer'] = $m->referrer;
            $nodes[$w]['bound_at'] = $m->bound_at?->getTimestamp();
            $nodes[$w]['held'] = $m->held_at !== null;
            if ($m->referrer !== null) {
                $nodes[$m->referrer] ??= $blank($m->referrer);
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

        // ---- events ----
        $totals = [self::READY => 0.0, self::WAIT => 0.0, self::HELD => 0.0, self::RANK => 0.0, self::NONE => 0.0, self::SOLD => 0.0];
        $weeks = [];
        $events = [];
        $maxWeek = max($nowWeek, 0);
        $weekRow = fn () => [
            'usdt' => 0.0, 'idtc' => 0.0, 'buys' => 0, 'first' => 0, 'ring' => [1 => 0.0, 2 => 0.0, 3 => 0.0], 'buyer' => 0.0,
            'status' => [self::READY => 0.0, self::WAIT => 0.0, self::HELD => 0.0, self::RANK => 0.0, self::NONE => 0.0, self::SOLD => 0.0],
            'sprint_pool' => 0.0, 'sprint_paid' => 0.0, 'sprint_status' => self::WAIT,
        ];
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
            $linked = $bn['referrer'] !== null && $bn['bound_at'] !== null && $bn['bound_at'] <= $ts;
            $rewards = [];
            $a = $linked ? $bn['referrer'] : null;
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
                $a = $a === null ? null : $nodes[$a]['referrer'];
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
            'returned' => $totals[self::RANK] + $totals[self::NONE] + $totals[self::SOLD] + $sprintReturned,
            'returned_rank' => $totals[self::RANK],
            'returned_sold' => $totals[self::SOLD],
            'returned_none' => $totals[self::NONE],
            'returned_sprint' => $sprintReturned,
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
        ], $nowWeek, $nowTs);
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

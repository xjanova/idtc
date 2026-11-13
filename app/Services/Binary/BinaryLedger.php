<?php

namespace App\Services\Binary;

use App\Models\BinaryDeposit;
use App\Models\BinaryPlanVersion;
use App\Models\GridMember;
use App\Services\Grid\GridPlacer;
use App\Services\Grid\GridPlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * คำนวณแผน Binary โปรโมชั่นจากยอดฝากจริง (binary_deposits ที่ status = ok) และผังซ้าย/ขวา (grid_members.bin_*)
 *
 * กติกา:
 *  - ยอดขา = USDT ที่คนในขานั้นฝาก (หลังผูกผู้แนะนำ · ไม่นับกระเป๋าที่ถูกพัก)
 *  - สัปดาห์ k: ซ้าย = ยกมา + ยอดใหม่ · ขวา = ยกมา + ยอดใหม่ · จับคู่ = ขาที่น้อยกว่า · ส่วนเกินของขาที่มากกว่ายกไปสัปดาห์ถัดไป
 *  - รางวัลตั้งต้น = จับคู่ × pair (USDT) → ไม่เกินเพดานต่อคน (cap ของกอง) → ถ้ารวมเกินกอง ย่อทุกคนลงเท่ากัน
 *  - กองของสัปดาห์ = ผลรวม pool_usdt ของรายการฝากสัปดาห์นั้น (บันทึกตายตัวตอนฝาก) → จ่ายรวมไม่เกินเงินที่กันไว้จากยอดฝากจริงเสมอ
 *    ส่วนที่จ่ายไม่ถึงกองไม่ถูกแจก (อยู่กับโครงการ — แสดงในหลังบ้าน)
 *  - ได้รางวัลตั้งแต่สัปดาห์ที่: ยอดฝากเองสะสมถึงขั้นต่ำของแผน + มีเพื่อนตรงที่ฝากถึงขั้นต่ำครบ directs คนในแต่ละขา
 *    (คิดจากยอดสะสมถึงสัปดาห์นั้น — ผ่านเงื่อนไขทีหลังไม่ได้ส่วนแบ่งของสัปดาห์ที่ปิดไปแล้ว) · ก่อนผ่าน ยอดขาไม่นับและไม่ยกยอด
 *  - สัปดาห์ที่ยังไม่ปิด = ประมาณการ (wait) · ปิดแล้ว = พร้อมจ่าย (ready) · กระเป๋าผู้รับถูกพัก = held
 *
 * คืน array ล้วน (แคชได้) — ดู compute()
 */
final class BinaryLedger
{
    public const READY = 'ready';

    public const WAIT = 'wait';

    public const HELD = 'held';

    public function __construct(protected BinaryPlan $plans) {}

    /**
     * ผลคำนวณล่าสุด — แคช 30 วินาทีตามลายนิ้วมือข้อมูล (ยอดฝาก/ผัง/แผน/โควตาเปลี่ยน = คำนวณใหม่)
     *
     * @return array{nodes: array<string, array<string, mixed>>, weeks: array<int, array<string, mixed>>, totals: array<string, mixed>, nowWeek: int, at: int}
     */
    public function book(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $fp = implode(':', [
            BinaryDeposit::query()->count(), (string) BinaryDeposit::query()->max('updated_at'),
            GridMember::query()->count(), (string) GridMember::query()->max('updated_at'),
            BinaryPlanVersion::query()->count(), (string) BinaryPlanVersion::query()->max('updated_at'),
            (string) $this->plans->quota(), intdiv($now->getTimestamp(), 30),
        ]);
        $data = Cache::remember('idtc:binary:book:'.md5($fp), 30, fn () => $this->compute($now));

        return is_array($data) ? $data : $this->compute($now);
    }

    /**
     * @return array{nodes: array<string, array<string, mixed>>, weeks: array<int, array<string, mixed>>, totals: array<string, mixed>, nowWeek: int, at: int}
     */
    public function compute(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $nowTs = $now->getTimestamp();
        $nowWeek = GridPlan::weekAt($nowTs);
        $current = $this->plans->current($now);
        $need = (int) $current['directs'];
        $active = (float) $current['min_usdt'];

        // ---- nodes: ทุกกระเป๋าในผัง + ทุกกระเป๋าที่ฝาก ----
        $nodes = [];
        $blank = fn (string $w) => [
            'wallet' => $w, 'referrer' => null, 'bound_at' => null, 'held' => false, 'parent' => null, 'leg' => null, 'bl' => null, 'br' => null,
            'usdt' => 0.0, 'idtc' => 0.0, 'deposits' => 0, 'first_at' => null,
            'nl' => 0, 'nr' => 0, 'l' => 0.0, 'r' => 0.0, 'cl' => 0.0, 'cr' => 0.0, 'dl' => 0, 'dr' => 0, 'q' => false, 'since' => null,
            'ready' => 0.0, 'wait' => 0.0, 'held_amt' => 0.0,
        ];
        foreach (GridMember::query()->orderBy('id')->toBase()->get(['wallet', 'referrer', 'bound_at', 'held_at', 'bin_parent', 'bin_leg']) as $m) {
            $w = $m->wallet;
            $nodes[$w] ??= $blank($w);
            $nodes[$w]['referrer'] = $m->referrer;
            $nodes[$w]['bound_at'] = $m->bound_at ? CarbonImmutable::parse($m->bound_at)->getTimestamp() : null;
            $nodes[$w]['held'] = $m->held_at !== null;
            if ($m->referrer !== null) {
                $nodes[$m->referrer] ??= $blank($m->referrer);
            }
            if ($m->bin_parent !== null && in_array($m->bin_leg, [GridPlacer::LEFT, GridPlacer::RIGHT], true) && $m->bin_parent !== $w) {
                $nodes[$m->bin_parent] ??= $blank($m->bin_parent);
                $slot = $m->bin_leg === GridPlacer::LEFT ? 'bl' : 'br';
                if ($nodes[$m->bin_parent][$slot] === null) {
                    $nodes[$m->bin_parent][$slot] = $w;
                    $nodes[$w]['parent'] = $m->bin_parent;
                    $nodes[$w]['leg'] = $m->bin_leg;
                }
            }
        }

        // ---- ยอดฝาก ----
        $weeks = [];
        $weekRow = fn () => ['usdt' => 0.0, 'idtc' => 0.0, 'deposits' => 0, 'pool' => 0.0, 'nominal' => 0.0, 'given' => 0.0, 'factor' => 1.0, 'state' => 'live', 'rows' => []];
        $own = [];      // กระเป๋า => [สัปดาห์ => USDT ที่ฝากเอง] (ดูว่าถึงขั้นต่ำสัปดาห์ไหน)
        $vol = [];      // กระเป๋า => [สัปดาห์ => USDT ที่นับเป็นยอดขาของสายบน]
        $maxWeek = max($nowWeek, 0);
        foreach (BinaryDeposit::query()->where('status', BinaryDeposit::OK)->orderBy('block_number')->orderBy('log_index')->toBase()
            ->get(['wallet', 'usdt', 'idtc', 'pool_usdt', 'week', 'block_time']) as $d) {
            $w = $d->wallet;
            $k = (int) $d->week;
            $ts = $d->block_time ? CarbonImmutable::parse($d->block_time)->getTimestamp() : $nowTs;
            $nodes[$w] ??= $blank($w);
            $maxWeek = max($maxWeek, $k);
            $weeks[$k] ??= $weekRow();
            $weeks[$k]['usdt'] += (float) $d->usdt;
            $weeks[$k]['idtc'] += (float) $d->idtc;
            $weeks[$k]['deposits']++;
            $weeks[$k]['pool'] += (float) $d->pool_usdt;
            $n = &$nodes[$w];
            $n['usdt'] += (float) $d->usdt;
            $n['idtc'] += (float) $d->idtc;
            $n['deposits']++;
            $n['first_at'] ??= $ts;
            $own[$w][$k] = ($own[$w][$k] ?? 0.0) + (float) $d->usdt;
            // ฝากก่อนผูกผู้แนะนำ = ไม่นับให้สายบน · กระเป๋าที่ถูกพัก = ไม่นับเป็นยอดขา
            if ($n['parent'] !== null && $n['bound_at'] !== null && $n['bound_at'] <= $ts && ! $n['held']) {
                $vol[$w][$k] = ($vol[$w][$k] ?? 0.0) + (float) $d->usdt;
            }
            unset($n);
        }
        for ($k = 0; $k <= $maxWeek; $k++) {
            $weeks[$k] ??= $weekRow();
        }
        ksort($weeks);

        // ---- ผัง: preorder (บนก่อนล่าง) → สายล่างของกระเป๋าหนึ่งเป็นช่วงติดกัน [tin, tin + size) ----
        $order = [];
        foreach ($nodes as $w => $n) {
            if ($n['parent'] !== null || ($n['bl'] === null && $n['br'] === null)) {
                continue;
            }
            $stack = [$w];
            while ($stack) {
                $x = array_pop($stack);
                $order[] = $x;
                if ($nodes[$x]['br'] !== null) {
                    $stack[] = $nodes[$x]['br'];
                }
                if ($nodes[$x]['bl'] !== null) {
                    $stack[] = $nodes[$x]['bl'];
                }
            }
        }
        $tin = array_flip($order);
        $size = [];
        $sub = [];
        $legs = [];
        for ($i = count($order) - 1; $i >= 0; $i--) {
            $w = $order[$i];
            $mine = $vol[$w] ?? [];
            $sz = 1;
            foreach ([GridPlacer::LEFT => $nodes[$w]['bl'], GridPlacer::RIGHT => $nodes[$w]['br']] as $leg => $c) {
                if ($c === null) {
                    continue;
                }
                $sz += $size[$c];
                $legs[$w][$leg] = $sub[$c];
                foreach ($sub[$c] as $k => $v) {
                    $mine[$k] = ($mine[$k] ?? 0.0) + $v;
                }
                $nodes[$w][$leg === GridPlacer::LEFT ? 'nl' : 'nr'] = $size[$c];
                $nodes[$w][$leg === GridPlacer::LEFT ? 'l' : 'r'] = (float) array_sum($sub[$c]);
                unset($sub[$c]);
            }
            $size[$w] = $sz;
            $sub[$w] = $mine;
        }
        unset($sub);

        // ---- เงื่อนไข: สัปดาห์แรกที่ยอดฝากเองสะสมถึงขั้นต่ำ + เพื่อนตรงที่ถึงขั้นต่ำครบในแต่ละขา ----
        $activeWeek = function (string $w) use ($own, $active): ?int {
            $byWeek = $own[$w] ?? [];
            ksort($byWeek);
            $sum = 0.0;
            foreach ($byWeek as $k => $v) {
                $sum += $v;
                if ($sum > 0 && $sum + 1e-9 >= $active) {
                    return (int) $k;
                }
            }

            return null;
        };
        $directs = [];
        foreach ($nodes as $c => $n) {
            $s = $n['referrer'];
            if ($s === null || ! isset($tin[$c], $tin[$s]) || ($at = $activeWeek($c)) === null) {
                continue;
            }
            foreach ([GridPlacer::LEFT => $nodes[$s]['bl'], GridPlacer::RIGHT => $nodes[$s]['br']] as $leg => $top) {
                if ($top !== null && $tin[$c] >= $tin[$top] && $tin[$c] < $tin[$top] + $size[$top]) {
                    $directs[$s][$leg][] = $at;
                    break;
                }
            }
        }
        $from = [];
        foreach ($order as $w) {
            $l = $directs[$w][GridPlacer::LEFT] ?? [];
            $r = $directs[$w][GridPlacer::RIGHT] ?? [];
            sort($l);
            sort($r);
            $nodes[$w]['dl'] = count($l);
            $nodes[$w]['dr'] = count($r);
            $self = $activeWeek($w);
            $ok = $self !== null && count($l) >= $need && count($r) >= $need;
            $week = $ok ? max($self, $need > 0 ? $l[$need - 1] : 0, $need > 0 ? $r[$need - 1] : 0) : null;
            $nodes[$w]['since'] = $week;
            $nodes[$w]['q'] = $week !== null && $week <= $maxWeek;
            if ($week !== null && isset($legs[$w][GridPlacer::LEFT], $legs[$w][GridPlacer::RIGHT])) {
                $from[$w] = $week;
            }
        }

        // ---- จับคู่รายสัปดาห์ ----
        $carry = [];
        $totals = [self::READY => 0.0, self::WAIT => 0.0, self::HELD => 0.0];
        $unpaid = 0.0;
        for ($k = 0; $k <= $maxWeek; $k++) {
            $plan = $this->plans->at($k);
            $pool = $weeks[$k]['pool'];
            $cap = $pool * (float) $plan['cap'];
            $rows = [];
            $sum = 0.0;
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
                $nominal = $m * (float) $plan['pair'];
                $share = min($nominal, $cap);
                $sum += $share;
                $rows[] = ['wallet' => $w, 'left' => $l, 'right' => $r, 'matched' => $m, 'nominal' => $nominal, 'share' => $share, 'capped' => $nominal > $cap + 1e-9];
            }
            $factor = $sum > $pool + 1e-9 ? ($sum > 0 ? $pool / $sum : 0.0) : 1.0;
            $closed = $nowTs >= GridPlan::weekStart($k + 1)->getTimestamp();
            $given = 0.0;
            foreach ($rows as $i => $row) {
                $share = $row['share'] * $factor;
                $rows[$i]['share'] = $share;
                $status = $nodes[$row['wallet']]['held'] ? self::HELD : ($closed ? self::READY : self::WAIT);
                $rows[$i]['status'] = $status;
                $given += $share;
                $totals[$status] += $share;
                $nodes[$row['wallet']][$status === self::HELD ? 'held_amt' : $status] += $share;
            }
            usort($rows, fn ($x, $y) => $y['share'] <=> $x['share'] ?: strcmp($x['wallet'], $y['wallet']));
            $weeks[$k]['nominal'] = array_sum(array_column($rows, 'nominal'));
            $weeks[$k]['given'] = $given;
            $weeks[$k]['factor'] = $factor;
            $weeks[$k]['state'] = $closed ? 'closed' : 'live';
            $weeks[$k]['rows'] = $rows;
            if ($closed) {
                $unpaid += max(0.0, $pool - $given);
            }
        }
        foreach ($carry as $w => [$l, $r]) {
            $nodes[$w]['cl'] = $l;
            $nodes[$w]['cr'] = $r;
        }

        $sold = array_sum(array_column($weeks, 'idtc'));
        $quota = $this->plans->quota();

        return [
            'nodes' => $nodes,
            'weeks' => $weeks,
            'totals' => [
                'usdt' => array_sum(array_column($weeks, 'usdt')),
                'idtc' => $sold,
                'deposits' => array_sum(array_column($weeks, 'deposits')),
                'depositors' => count(array_filter($nodes, fn ($n) => $n['deposits'] > 0)),
                'pool' => array_sum(array_column($weeks, 'pool')),
                'ready' => $totals[self::READY],
                'wait' => $totals[self::WAIT],
                'held' => $totals[self::HELD],
                'unpaid' => $unpaid,          // กองของสัปดาห์ที่ปิดแล้วซึ่งไม่ถูกแจก
                'quota' => $quota,
                'quota_left' => max(0.0, $quota - $sold),
            ],
            'nowWeek' => $nowWeek,
            'at' => $nowTs,
        ];
    }
}

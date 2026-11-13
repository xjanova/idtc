<?php

namespace App\Services\Grid;

use App\Models\GridMember;
use App\Models\GridTreeChange;
use Carbon\CarbonImmutable;
use Closure;

/**
 * ผัง Binary (สองขา) ตามเวลา — ตัวคำนวณยอดขาที่ใช้ร่วมกันโดย GridLedger::binary() และ BinaryLedger
 *
 * กติกา:
 *  - ผังของสัปดาห์ k = ผังหลังการเปลี่ยนทุกรายการที่มีผลภายในสัปดาห์ k (จบสัปดาห์)
 *    ตำแหน่งแรกของกระเป๋าเริ่มมีผลสัปดาห์ที่วาง (bin_placed_at) · จัดสายจากคิว / ย้ายสาย / ต่อสาย / เปลี่ยนผู้แนะนำ
 *    มีผลตั้งแต่สัปดาห์ที่ทำรายการ (grid_tree_changes)
 *  - ยอดของสัปดาห์ k ไหลขึ้นตามผังของสัปดาห์ k เท่านั้น → ย้ายสายสัปดาห์นี้ไม่แตะยอดและรางวัลของสัปดาห์ที่ปิดไปแล้ว
 *    (สัปดาห์ที่ปิดแล้วจ่ายไปแล้ว ถ้าแบ่งใหม่จะจ่ายซ้ำเกินกอง)
 *  - เพื่อนตรงต่อขาดูจากผังและผู้แนะนำของสัปดาห์นั้น · ผ่านเงื่อนไขครั้งแรกสัปดาห์ไหน ได้ตั้งแต่สัปดาห์นั้นตลอดไป (เหมือนเดิม)
 *  - ไม่มีประวัติการย้าย = ผลเท่ากับคิดจากผังปัจจุบัน (แบบเดิม)
 *
 * คลาสนี้ไม่รู้ว่ายอดเป็น USDT หรือ IDTC — ผู้เรียกส่งยอดรายสัปดาห์ที่นับให้สายบนมาเอง
 */
final class BinaryTree
{
    private const L = GridPlacer::LEFT;

    private const R = GridPlacer::RIGHT;

    /**
     * สถานะปัจจุบันของทุกแถวใน grid_members
     *
     * @return array<string, array{referrer: ?string, parent: ?string, leg: ?string, placed: ?int}>
     */
    public static function members(): array
    {
        $out = [];
        foreach (GridMember::query()->orderBy('id')->toBase()->get(['wallet', 'referrer', 'bin_parent', 'bin_leg', 'bin_placed_at']) as $m) {
            $out[$m->wallet] = [
                'referrer' => $m->referrer,
                'parent' => $m->bin_parent,
                'leg' => $m->bin_leg,
                'placed' => $m->bin_placed_at ? CarbonImmutable::parse($m->bin_placed_at)->getTimestamp() : null,
            ];
        }

        return $out;
    }

    /**
     * ประวัติการเปลี่ยนเรียงตามเวลา
     *
     * @return list<array{wallet: string, kind: string, at: int, from_parent: ?string, from_leg: ?string, to_parent: ?string, to_leg: ?string, from_referrer: ?string, to_referrer: ?string}>
     */
    public static function changes(): array
    {
        $out = [];
        foreach (GridTreeChange::query()->orderBy('effective_at')->orderBy('id')->toBase()
            ->get(['wallet', 'kind', 'effective_at', 'from_parent', 'from_leg', 'to_parent', 'to_leg', 'from_referrer', 'to_referrer']) as $c) {
            $out[] = [
                'wallet' => $c->wallet, 'kind' => $c->kind, 'at' => CarbonImmutable::parse($c->effective_at)->getTimestamp(),
                'from_parent' => $c->from_parent, 'from_leg' => $c->from_leg, 'to_parent' => $c->to_parent, 'to_leg' => $c->to_leg,
                'from_referrer' => $c->from_referrer, 'to_referrer' => $c->to_referrer,
            ];
        }

        return $out;
    }

    /**
     * ผู้แนะนำของกระเป๋า ณ เวลาหนึ่ง (ใช้กับรางวัลรายการซื้อ ซึ่งพร้อมจ่ายทีละรายการ ไม่รอปิดสัปดาห์ จึงต้องละเอียดถึงวินาที)
     *
     * @param  array<string, ?string>  $current  ผู้แนะนำปัจจุบัน กระเป๋า => ผู้แนะนำ
     * @param  list<array<string, mixed>>  $changes
     * @return Closure(?string, int): ?string
     */
    public static function referrerAt(array $current, array $changes): Closure
    {
        $hist = [];
        foreach ($changes as $c) {
            if ($c['kind'] === GridTreeChange::REFERRER) {
                $hist[$c['wallet']][] = $c;
            }
        }

        return function (?string $wallet, int $ts) use ($current, $hist): ?string {
            if ($wallet === null) {
                return null;
            }
            if (! isset($hist[$wallet])) {
                return $current[$wallet] ?? null;
            }
            $ref = $hist[$wallet][0]['from_referrer'];
            foreach ($hist[$wallet] as $c) {
                if ($c['at'] > $ts) {
                    break;
                }
                $ref = $c['to_referrer'];
            }

            return $ref;
        };
    }

    /** มีการเปลี่ยนผู้แนะนำในประวัติไหม @param list<array<string, mixed>> $changes */
    public static function hasReferrerChanges(array $changes): bool
    {
        foreach ($changes as $c) {
            if ($c['kind'] === GridTreeChange::REFERRER) {
                return true;
            }
        }

        return false;
    }

    /**
     * ยอดรายสัปดาห์ของแต่ละขา + เงื่อนไขเพื่อนตรง ตามผังของแต่ละสัปดาห์
     *
     * @param  array<string, array{referrer: ?string, parent: ?string, leg: ?string, placed: ?int}>  $members  จาก members()
     * @param  list<array<string, mixed>>  $changes  จาก changes()
     * @param  array<string, array<int, float>>  $vol  กระเป๋า => [สัปดาห์ => ยอดของกระเป๋านี้ที่นับให้สายบน]
     * @param  Closure(string): ?int  $activeWeek  สัปดาห์แรกที่ยอดเองถึงเกณฑ์ (null = ยังไม่ถึง)
     * @param  int  $need  เพื่อนตรงที่ใช้งานแล้วต่อขาที่ต้องมี
     * @return array{
     *     legs: array<string, array<string, array<int, float>>>,
     *     from: array<string, int>,
     *     nodes: array<string, array{nl: int, nr: int, l: float, r: float, dl: int, dr: int, q: bool, since: ?int}>
     * }
     *         legs = กระเป๋า => [L|R => [สัปดาห์ => ยอดที่เข้าขานั้น]] · from = สัปดาห์แรกที่จับคู่ได้ (มีสองขา + ผ่านเงื่อนไข)
     *         nodes: จำนวนคน/เพื่อนตรงต่อขาตามผังปัจจุบัน · ยอดขารวมทุกสัปดาห์ · q/since = ผ่านเงื่อนไข (ไม่ดูว่ามีสองขาไหม)
     */
    public static function compute(array $members, array $changes, array $vol, Closure $activeWeek, int $need, int $maxWeek): array
    {
        $maxWeek = max(0, $maxWeek);
        $weekOf = fn (int $ts): int => min($maxWeek, max(0, GridPlan::weekAt($ts)));

        // ---- เหตุการณ์: [สัปดาห์, เวลา, ลำดับ, ชนิด, กระเป๋า, ค่า1, ค่า2] ----
        $pos = [];
        $refs = [];
        foreach ($changes as $c) {
            if ($c['kind'] === GridTreeChange::REFERRER) {
                $refs[$c['wallet']][] = $c;
            } else {
                $pos[$c['wallet']][] = $c;
            }
        }
        $events = [];
        $seq = 0;
        $ref = [];
        foreach ($members as $w => $m) {
            $rh = $refs[$w] ?? [];
            $ref[$w] = $rh ? $rh[0]['from_referrer'] : $m['referrer'];
            foreach ($rh as $c) {
                $events[] = [$weekOf($c['at']), $c['at'], $seq++, 'ref', $w, $c['to_referrer'], null];
            }
            $ph = $pos[$w] ?? [];
            [$p0, $l0] = $ph ? [$ph[0]['from_parent'], $ph[0]['from_leg']] : [$m['parent'], $m['leg']];
            if ($p0 !== null) {
                // ตำแหน่งแรก (วางอัตโนมัติตอนผูก) — ไม่มีเวลาวาง (ข้อมูลเก่า) = มีตั้งแต่ต้น
                $events[] = [$m['placed'] === null ? 0 : $weekOf($m['placed']), $m['placed'] ?? PHP_INT_MIN, $seq++, 'pos', $w, $p0, $l0];
            }
            foreach ($ph as $c) {
                $events[] = [$weekOf($c['at']), $c['at'], $seq++, 'pos', $w, $c['to_parent'], $c['to_leg']];
            }
        }
        usort($events, fn ($x, $y) => [$x[0], $x[1], $x[2]] <=> [$y[0], $y[1], $y[2]]);

        // ---- ช่วงสัปดาห์ที่ผังคงที่ (epoch) ----
        $starts = [0];
        foreach ($events as $e) {
            if ($e[0] > end($starts)) {
                $starts[] = $e[0];
            }
        }
        $epochOf = [];
        for ($k = 0, $e = 0; $k <= $maxWeek; $k++) {
            while (isset($starts[$e + 1]) && $starts[$e + 1] <= $k) {
                $e++;
            }
            $epochOf[$k] = $e;
        }
        $volE = [];
        foreach ($vol as $w => $byWeek) {
            foreach ($byWeek as $k => $v) {
                if ($k >= 0 && $k <= $maxWeek && $v != 0.0) {
                    $volE[$epochOf[$k]][$w][$k] = $v;
                }
            }
        }

        $activeCache = [];
        $active = function (string $w) use (&$activeCache, $activeWeek): ?int {
            if (! array_key_exists($w, $activeCache)) {
                $activeCache[$w] = $activeWeek($w);
            }

            return $activeCache[$w];
        };

        $slot = [];      // parent => [L => ลูก, R => ลูก]
        $parentOf = [];
        $legOf = [];
        $legs = [];
        $from = [];
        $qWeek = [];
        $out = [];
        $blank = ['nl' => 0, 'nr' => 0, 'l' => 0.0, 'r' => 0.0, 'dl' => 0, 'dr' => 0, 'q' => false, 'since' => null];
        $i = 0;
        $n = count($events);
        foreach ($starts as $idx => $a) {
            // ใช้ทุกเหตุการณ์ของสัปดาห์ a (และก่อนหน้า) → ผังตอนจบสัปดาห์ a
            for (; $i < $n && $events[$i][0] <= $a; $i++) {
                [, , , $type, $w, $v1, $v2] = $events[$i];
                if ($type === 'ref') {
                    $ref[$w] = $v1;

                    continue;
                }
                if (isset($parentOf[$w])) {
                    unset($slot[$parentOf[$w]][$legOf[$w]]);
                    if ($slot[$parentOf[$w]] === []) {
                        unset($slot[$parentOf[$w]]);
                    }
                    unset($parentOf[$w], $legOf[$w]);
                }
                // ตำแหน่งชนกัน/ชี้ตัวเอง (ไม่ควรเกิด) = ไม่นับในผัง
                if ($v1 !== null && ($v2 === self::L || $v2 === self::R) && $v1 !== $w && ! isset($slot[$v1][$v2])) {
                    $slot[$v1][$v2] = $w;
                    $parentOf[$w] = $v1;
                    $legOf[$w] = $v2;
                }
            }
            $b = isset($starts[$idx + 1]) ? $starts[$idx + 1] - 1 : $maxWeek;
            $last = ! isset($starts[$idx + 1]);

            // preorder (บนก่อนล่าง ซ้ายก่อนขวา) — สายล่างของกระเป๋าหนึ่งเป็นช่วงติดกัน [tin, tin + size)
            $order = [];
            $seen = [];
            foreach ($slot as $root => $_) {
                if (isset($parentOf[$root])) {
                    continue;
                }
                $stack = [$root];
                while ($stack) {
                    $x = array_pop($stack);
                    if (isset($seen[$x])) {
                        continue;
                    }
                    $seen[$x] = true;
                    $order[] = $x;
                    if (isset($slot[$x][self::R])) {
                        $stack[] = $slot[$x][self::R];
                    }
                    if (isset($slot[$x][self::L])) {
                        $stack[] = $slot[$x][self::L];
                    }
                }
            }
            $tin = array_flip($order);

            // ล่างขึ้นบน: ขนาดสายล่าง + ยอดรายสัปดาห์ของแต่ละขา (เฉพาะสัปดาห์ในช่วงนี้)
            $mineE = $volE[$idx] ?? [];
            $size = [];
            $sub = [];
            for ($j = count($order) - 1; $j >= 0; $j--) {
                $w = $order[$j];
                $mine = $mineE[$w] ?? [];
                $sz = 1;
                foreach ([self::L, self::R] as $lg) {
                    $c = $slot[$w][$lg] ?? null;
                    if ($c === null || ! isset($size[$c])) {
                        continue;
                    }
                    $sz += $size[$c];
                    $legs[$w][$lg] ??= [];
                    foreach ($sub[$c] as $k => $v) {
                        $legs[$w][$lg][$k] = ($legs[$w][$lg][$k] ?? 0.0) + $v;
                        $mine[$k] = ($mine[$k] ?? 0.0) + $v;
                    }
                    unset($sub[$c]);
                }
                $size[$w] = $sz;
                $sub[$w] = $mine;
            }
            unset($sub);

            // เพื่อนตรงที่ใช้งานแล้ว อยู่ขาไหนของผู้แนะนำ (ตามผังและผู้แนะนำของช่วงนี้)
            $dirs = [];
            foreach ($ref as $c => $s) {
                if ($s === null || ! isset($tin[$c], $tin[$s]) || ($at = $active($c)) === null) {
                    continue;
                }
                foreach ([self::L, self::R] as $lg) {
                    $top = $slot[$s][$lg] ?? null;
                    if ($top !== null && isset($tin[$top]) && $tin[$c] >= $tin[$top] && $tin[$c] < $tin[$top] + $size[$top]) {
                        $dirs[$s][$lg][] = $at;
                        break;
                    }
                }
            }
            foreach ($order as $w) {
                $l = $dirs[$w][self::L] ?? [];
                $r = $dirs[$w][self::R] ?? [];
                if ($last) {
                    $out[$w] = [
                        'nl' => isset($slot[$w][self::L]) ? $size[$slot[$w][self::L]] ?? 0 : 0,
                        'nr' => isset($slot[$w][self::R]) ? $size[$slot[$w][self::R]] ?? 0 : 0,
                        'dl' => count($l), 'dr' => count($r),
                    ] + $blank;
                }
                if (isset($from[$w])) {
                    continue;
                }
                $self = $active($w);
                if ($self === null || count($l) < $need || count($r) < $need) {
                    continue;
                }
                sort($l);
                sort($r);
                $week = max($a, $self, $need > 0 ? $l[$need - 1] : 0, $need > 0 ? $r[$need - 1] : 0);
                if ($week > $b) {
                    continue;
                }
                $qWeek[$w] ??= $week;
                if (isset($slot[$w][self::L], $slot[$w][self::R])) {
                    $from[$w] = $week;
                }
            }
        }

        foreach ($legs as $w => $byLeg) {
            $out[$w] ??= $blank;
            $out[$w]['l'] = (float) array_sum($byLeg[self::L] ?? []);
            $out[$w]['r'] = (float) array_sum($byLeg[self::R] ?? []);
        }
        foreach ($qWeek as $w => $week) {
            $out[$w] ??= $blank;
            $out[$w]['q'] = true;
            $out[$w]['since'] = $week;
        }

        return ['legs' => $legs, 'from' => $from, 'nodes' => $out];
    }
}

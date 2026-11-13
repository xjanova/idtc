<?php

namespace App\Services\Grid;

/**
 * ผลคำนวณรางวัล Grid ทั้งระบบ ณ เวลาหนึ่ง (GridLedger::compute) — ข้อมูลล้วน ไม่แตะฐานข้อมูล
 *
 * nodes[wallet]  กระเป๋าทุกใบที่ผูกผู้แนะนำ เป็นผู้แนะนำ หรือซื้อ presale
 * events         การซื้อแต่ละรายการ พร้อมรางวัลที่เกิด (วง 1..3 + ผู้ซื้อ) และสถานะ
 * weeks[k]       สรุปรายสัปดาห์ขาย · sprint[k] กอง Sprint ของสัปดาห์นั้น · binary[k] กอง Binary (รางวัลจับคู่) ของสัปดาห์นั้น
 * totals         รวมตามสถานะ + งบ + ภาระสูงสุดถ้าขายหมด
 *
 * จำนวน IDTC เป็นทศนิยม (ใช้แสดงและประมาณการ) — รอบจ่ายจริงจะแปลงเป็นหน่วยเล็กสุดตอนสร้างรอบ
 */
final class GridBook
{
    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $weeks
     * @param  array<int, array<string, mixed>>  $sprint
     * @param  array<string, mixed>  $totals
     * @param  array<int, array<string, mixed>>  $binary
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $events,
        public readonly array $weeks,
        public readonly array $sprint,
        public readonly array $totals,
        public readonly int $nowWeek,
        public readonly int $at,
        public readonly array $binary = [],
    ) {}

    /** @return array<string, mixed> ข้อมูลล้วน (ใช้แคช) */
    public function toArray(): array
    {
        return [
            'nodes' => $this->nodes, 'events' => $this->events, 'weeks' => $this->weeks, 'sprint' => $this->sprint,
            'totals' => $this->totals, 'nowWeek' => $this->nowWeek, 'at' => $this->at, 'binary' => $this->binary,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self($d['nodes'], $d['events'], $d['weeks'], $d['sprint'], $d['totals'], (int) $d['nowWeek'], (int) $d['at'], $d['binary'] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function node(string $wallet): ?array
    {
        return $this->nodes[strtolower($wallet)] ?? null;
    }

    /**
     * รางวัลที่เข้ากระเป๋านี้ (ใหม่สุดก่อน) — จากวง 1..3 และส่วนผู้ซื้อ
     *
     * @return list<array<string, mixed>>
     */
    public function rewardsTo(string $wallet, int $limit = 0): array
    {
        $wallet = strtolower($wallet);
        $out = [];
        for ($i = count($this->events) - 1; $i >= 0; $i--) {
            $e = $this->events[$i];
            foreach ($e['rewards'] as $r) {
                if ($r['to'] === $wallet) {
                    $out[] = ['event' => $e['id'], 'tx' => $e['tx'], 'buyer' => $e['buyer'], 'at' => $e['at'], 'week' => $e['week'], 'usdt' => $e['usdt']] + $r;
                    if ($limit && count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * สายล่างตามวง (1 = เพื่อนตรง) ไม่เกิน $depth วง · แต่ละวงไม่เกิน $perRing รายการ (count บอกจำนวนจริง)
     *
     * @return array<int, array{count: int, items: list<array<string, mixed>>}>
     */
    public function rings(string $wallet, int $depth = 3, int $perRing = 200): array
    {
        $front = [strtolower($wallet)];
        $rings = [];
        for ($d = 1; $d <= $depth; $d++) {
            $next = [];
            foreach ($front as $w) {
                foreach ($this->nodes[$w]['children'] ?? [] as $c) {
                    $next[] = $c;
                }
            }
            $items = [];
            foreach (array_slice($next, 0, $perRing) as $c) {
                $n = $this->nodes[$c];
                $items[] = [
                    'wallet' => $c, 'parent' => $n['referrer'], 'rank' => $n['rank'], 'own_usdt' => $n['own_usdt'],
                    'team_usdt' => $n['team_usdt'], 'team_count' => $n['team_count'], 'directs' => $n['directs'], 'bound_at' => $n['bound_at'],
                ];
            }
            $rings[$d] = ['count' => count($next), 'items' => $items];
            $front = $next;
            if (! $next) {
                break;
            }
        }

        return $rings;
    }

    /** @return list<string> สายบนจากผู้แนะนำตรงขึ้นไป · $binary = ตามผัง Binary (คนที่อยู่เหนือในผัง) */
    public function upline(string $wallet, int $max = 64, bool $binary = false): array
    {
        $key = $binary ? 'bin_parent' : 'referrer';
        $out = [];
        $w = $this->nodes[strtolower($wallet)][$key] ?? null;
        while ($w !== null && count($out) < $max && ! in_array($w, $out, true)) {
            $out[] = $w;
            $w = $this->nodes[$w][$key] ?? null;
        }

        return $out;
    }

    /**
     * สายงานสำหรับผัง: ไล่ทีละวงจาก $root (null = ทั้งระบบ: กระเป๋าที่ไม่มีผู้แนะนำเป็นวง 1)
     * ไม่เกิน $depth วงและ $max กระเป๋า — กระเป๋าที่สายล่างถูกตัด บอกจำนวนที่ซ่อนไว้ใน fold
     *
     * node = [wallet, parent|null, วง, rank, own_usdt, team_usdt, team_count, held, fold]
     *
     * $binary = ไล่ตามผัง Binary (ลูกซ้าย/ขวา) แทนสายแนะนำ — ทั้งระบบ = กระเป๋าบนสุดของแต่ละผังที่มีคนอยู่ใต้
     *
     * @return array{root: string|null, nodes: list<array{0: string, 1: string|null, 2: int, 3: int, 4: float, 5: float, 6: int, 7: bool, 8: int}>, total: int, shown: int}
     */
    public function slice(?string $root, int $depth = 6, int $max = 2500, bool $binary = false): array
    {
        $root = $root !== null ? strtolower($root) : null;
        $up = $binary ? 'bin_parent' : 'referrer';
        $kids = $binary
            ? fn (array $n): array => array_values(array_filter([$n['bl'] ?? null, $n['br'] ?? null], fn ($c) => $c !== null))
            : fn (array $n): array => $n['children'];
        $below = $binary
            ? fn (array $n): int => (int) ($n['bin_nl'] ?? 0) + (int) ($n['bin_nr'] ?? 0)
            : fn (array $n): int => (int) $n['team_count'];
        if ($root !== null) {
            $front = isset($this->nodes[$root]) ? $kids($this->nodes[$root]) : [];
            $total = isset($this->nodes[$root]) ? $below($this->nodes[$root]) : 0;
        } else {
            $front = [];
            $total = 0;
            foreach ($this->nodes as $w => $n) {
                if (($n[$up] ?? null) === null && (! $binary || $kids($n) !== [])) {
                    $front[] = $w;
                    $total += 1 + $below($n);
                }
            }
            // ทีมใหญ่ก่อน — ถ้าถูกตัด ส่วนที่หายเป็นกระเป๋าเดี่ยว ๆ
            usort($front, fn ($a, $b) => $below($this->nodes[$b]) <=> $below($this->nodes[$a]) ?: strcmp($a, $b));
        }

        $out = [];
        $index = [];
        for ($d = 1; $d <= $depth && $front !== []; $d++) {
            $next = [];
            foreach ($front as $w) {
                $n = $this->nodes[$w];
                $parent = $d === 1 ? null : ($n[$up] ?? null);
                if (count($out) >= $max) {
                    if ($parent !== null && isset($index[$parent])) {
                        $out[$index[$parent]][8] += 1 + $below($n);
                    }

                    continue;
                }
                $index[$w] = count($out);
                $out[] = [$w, $parent, $d, (int) $n['rank'], round((float) $n['own_usdt'], 2), round((float) $n['team_usdt'], 2), $below($n), (bool) $n['held'], 0];
                foreach ($kids($n) as $c) {
                    $next[] = $c;
                }
            }
            $front = $next;
        }
        // วงสุดท้ายที่แสดง: สายล่างที่ลึกกว่านั้นพับไว้
        foreach ($front as $w) {
            $p = $this->nodes[$w][$up] ?? null;
            if ($p !== null && isset($index[$p])) {
                $out[$index[$p]][8] += 1 + $below($this->nodes[$w]);
            }
        }

        return ['root' => $root, 'nodes' => $out, 'total' => $total, 'shown' => count($out)];
    }
}

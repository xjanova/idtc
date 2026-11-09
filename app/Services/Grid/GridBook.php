<?php

namespace App\Services\Grid;

/**
 * ผลคำนวณรางวัล Grid ทั้งระบบ ณ เวลาหนึ่ง (GridLedger::compute) — ข้อมูลล้วน ไม่แตะฐานข้อมูล
 *
 * nodes[wallet]  กระเป๋าทุกใบที่ผูกผู้แนะนำ เป็นผู้แนะนำ หรือซื้อ presale
 * events         การซื้อแต่ละรายการ พร้อมรางวัลที่เกิด (วง 1..3 + ผู้ซื้อ) และสถานะ
 * weeks[k]       สรุปรายสัปดาห์ขาย · sprint[k] กอง Sprint ของสัปดาห์นั้น
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
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $events,
        public readonly array $weeks,
        public readonly array $sprint,
        public readonly array $totals,
        public readonly int $nowWeek,
        public readonly int $at,
    ) {}

    /** @return array<string, mixed> ข้อมูลล้วน (ใช้แคช) */
    public function toArray(): array
    {
        return [
            'nodes' => $this->nodes, 'events' => $this->events, 'weeks' => $this->weeks, 'sprint' => $this->sprint,
            'totals' => $this->totals, 'nowWeek' => $this->nowWeek, 'at' => $this->at,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self($d['nodes'], $d['events'], $d['weeks'], $d['sprint'], $d['totals'], (int) $d['nowWeek'], (int) $d['at']);
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

    /** @return list<string> สายบนจากผู้แนะนำตรงขึ้นไป */
    public function upline(string $wallet, int $max = 64): array
    {
        $out = [];
        $w = $this->nodes[strtolower($wallet)]['referrer'] ?? null;
        while ($w !== null && count($out) < $max && ! in_array($w, $out, true)) {
            $out[] = $w;
            $w = $this->nodes[$w]['referrer'] ?? null;
        }

        return $out;
    }

    /**
     * สายงานสำหรับผัง: ไล่ทีละวงจาก $root (null = ทั้งระบบ: กระเป๋าที่ไม่มีผู้แนะนำเป็นวง 1)
     * ไม่เกิน $depth วงและ $max กระเป๋า — กระเป๋าที่สายล่างถูกตัด บอกจำนวนที่ซ่อนไว้ใน fold
     *
     * node = [wallet, parent|null, วง, rank, own_usdt, team_usdt, team_count, held, fold]
     *
     * @return array{root: string|null, nodes: list<array{0: string, 1: string|null, 2: int, 3: int, 4: float, 5: float, 6: int, 7: bool, 8: int}>, total: int, shown: int}
     */
    public function slice(?string $root, int $depth = 6, int $max = 2500): array
    {
        $root = $root !== null ? strtolower($root) : null;
        if ($root !== null) {
            $front = $this->nodes[$root]['children'] ?? [];
            $total = (int) ($this->nodes[$root]['team_count'] ?? 0);
        } else {
            $front = [];
            $total = 0;
            foreach ($this->nodes as $w => $n) {
                if ($n['referrer'] === null) {
                    $front[] = $w;
                    $total += 1 + $n['team_count'];
                }
            }
            // ทีมใหญ่ก่อน — ถ้าถูกตัด ส่วนที่หายเป็นกระเป๋าเดี่ยว ๆ
            usort($front, fn ($a, $b) => $this->nodes[$b]['team_count'] <=> $this->nodes[$a]['team_count'] ?: strcmp($a, $b));
        }

        $out = [];
        $index = [];
        for ($d = 1; $d <= $depth && $front !== []; $d++) {
            $next = [];
            foreach ($front as $w) {
                $n = $this->nodes[$w];
                $parent = $d === 1 ? null : $n['referrer'];
                if (count($out) >= $max) {
                    if ($parent !== null && isset($index[$parent])) {
                        $out[$index[$parent]][8] += 1 + $n['team_count'];
                    }

                    continue;
                }
                $index[$w] = count($out);
                $out[] = [$w, $parent, $d, (int) $n['rank'], round((float) $n['own_usdt'], 2), round((float) $n['team_usdt'], 2), (int) $n['team_count'], (bool) $n['held'], 0];
                foreach ($n['children'] as $c) {
                    $next[] = $c;
                }
            }
            $front = $next;
        }
        // วงสุดท้ายที่แสดง: สายล่างที่ลึกกว่านั้นพับไว้
        foreach ($front as $w) {
            $p = $this->nodes[$w]['referrer'];
            if ($p !== null && isset($index[$p])) {
                $out[$index[$p]][8] += 1 + $this->nodes[$w]['team_count'];
            }
        }

        return ['root' => $root, 'nodes' => $out, 'total' => $total, 'shown' => count($out)];
    }
}

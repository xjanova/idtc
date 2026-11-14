<?php

namespace App\Services\Binary;

use App\Services\Grid\GridPlacer;

/**
 * ข้อมูลหน้า "ผังสายงาน Binary" (หลังบ้าน /admin/binary/map) — ต้นไม้สองขาจากบนลงล่าง แยกจากผังวงของ IDTC Grid
 *
 * อ่านจาก BinaryLedger::book() อย่างเดียว (ผังปัจจุบัน + ยอดฝาก USDT ของแผน Binary) ไม่แตะฐานข้อมูลเอง
 *
 * slice(): ตัดผังรอบกระเป๋า root ลงไป $depth ชั้น ไม่เกิน $limit กระเป๋า — ส่วนที่ลึกกว่านั้น "พับ" เป็นตัวเลขที่กระเป๋าต้นสาย
 *   nodes เรียงชั้นต่อชั้น (แม่มาก่อนลูกเสมอ) แถวละ
 *   [wallet, ลำดับของแม่ (-1 = root), ขา L/R, ชั้น, ฝากเอง, คนขาซ้าย, คนขาขวา, ยอดขาซ้าย, ยอดขาขวา, ยกมาซ้าย, ยกมาขวา, flags, พับไว้, เพื่อนตรงซ้าย, เพื่อนตรงขวา]
 *   จำนวนคนต่อขานับจากผังที่วาดจริง (ไม่ใช่ตัวเลขสำเร็จจากที่อื่น) → ตัวเลขบนผังตรงกับเส้นที่เห็นเสมอ
 */
final class BinaryMap
{
    /** ความลึกที่เลือกได้บนหน้าจอ (ชั้นใต้ root) */
    public const DEPTHS = [4, 6, 8, 10];

    public const MAX_DEPTH = 12;

    public const LIMIT = 3000;

    /** กระเป๋าที่ฝากถึงขั้นต่ำของแผน */
    public const ACTIVE = 1;

    /** ผ่านเงื่อนไขรับรางวัลจับคู่ */
    public const QUALIFIED = 2;

    /** ถูกพักรางวัล */
    public const HELD = 4;

    private const WALK = 100_000;

    /**
     * @param  array{nodes: array<string, array<string, mixed>>}  $book  BinaryLedger::book()
     * @param  string|null  $root  กระเป๋าที่ขอ (ตัวพิมพ์เล็ก) — null = บนสุดของผังที่ใหญ่ที่สุด
     * @param  float  $min  ฝากขั้นต่ำของแผน (USDT) — ใช้บอกว่ากระเป๋า "ฝากถึงขั้นต่ำ" หรือยัง
     * @return array{root: ?string, nodes: list<list<mixed>>, shown: int, total: int, crumbs: list<string>, edge: array{L: ?string, R: ?string}, tops: list<array{wallet: string, size: int}>, others: int, top: ?string}
     */
    public function slice(array $book, ?string $root, int $depth, float $min, int $limit = self::LIMIT): array
    {
        $nodes = $book['nodes'];
        $depth = max(1, min(self::MAX_DEPTH, $depth));
        $limit = max(1, $limit);

        // บนสุดของผังทุกผัง + ขนาด
        $tops = [];
        foreach ($nodes as $w => $n) {
            if (($n['parent'] ?? null) === null && $this->kids($nodes, $w) !== []) {
                $tops[$w] = count($this->subtree($nodes, $w));
            }
        }
        arsort($tops);
        if ($root === null) {
            $root = array_key_first($tops);
        }
        if ($root === null || ! isset($nodes[$root])) {
            return ['root' => null, 'nodes' => [], 'shown' => 0, 'total' => 0, 'crumbs' => [], 'edge' => ['L' => null, 'R' => null], 'tops' => $this->topList($tops, null), 'others' => count($tops), 'top' => null];
        }

        // ทุกกระเป๋าใต้ root (แม่มาก่อนลูก) → ขนาดทีมของแต่ละกระเป๋า นับย้อนจากท้าย
        $order = $this->subtree($nodes, $root);
        $size = [];
        for ($i = count($order) - 1; $i >= 0; $i--) {
            $w = $order[$i];
            $s = 1;
            foreach ($this->kids($nodes, $w) as $c) {
                $s += $size[$c] ?? 0;      // ลูกที่ไม่ถูกนับ (ข้อมูลวน) = 0
            }
            $size[$w] = $s;
        }

        // ตัดผัง: ชั้นต่อชั้น · ลูกของกระเป๋าหนึ่งแสดงครบทั้งสองขาหรือไม่แสดงเลย (พับ)
        $rows = [];
        $queue = [[$root, -1, null, 0]];
        $index = [$root => true];
        $count = 1;
        for ($i = 0; $i < count($queue); $i++) {
            [$w, $parent, $leg, $level] = $queue[$i];
            $n = $nodes[$w];
            $kids = array_filter($this->kids($nodes, $w), fn ($c) => isset($size[$c]) && ! isset($index[$c]));
            $open = $kids !== [] && $level < $depth && $count + count($kids) <= $limit;
            if ($open) {
                foreach ($kids as $kLeg => $c) {
                    $index[$c] = true;
                    $queue[] = [$c, $i, $kLeg, $level + 1];
                    $count++;
                }
            }
            $left = $kids[GridPlacer::LEFT] ?? null;
            $right = $kids[GridPlacer::RIGHT] ?? null;
            $usdt = (float) ($n['usdt'] ?? 0.0);
            $rows[] = [
                $w, $parent, $leg, $level, round($usdt, 2),
                $left !== null ? $size[$left] : 0, $right !== null ? $size[$right] : 0,
                round((float) ($n['l'] ?? 0.0), 2), round((float) ($n['r'] ?? 0.0), 2),
                round((float) ($n['cl'] ?? 0.0), 2), round((float) ($n['cr'] ?? 0.0), 2),
                ($usdt > 0 && $usdt + 1e-9 >= $min ? self::ACTIVE : 0) | (! empty($n['q']) ? self::QUALIFIED : 0) | (! empty($n['held']) ? self::HELD : 0),
                $open ? 0 : $size[$w] - 1,
                (int) ($n['dl'] ?? 0), (int) ($n['dr'] ?? 0),
            ];
        }

        // ทางขึ้นจาก root ถึงบนสุด (เรียงจากบนลงมา)
        $crumbs = [];
        $seen = [$root => true];
        $cur = $root;
        while (($p = $nodes[$cur]['parent'] ?? null) !== null && isset($nodes[$p]) && ! isset($seen[$p]) && count($crumbs) < self::WALK) {
            $seen[$p] = true;
            $crumbs[] = $p;
            $cur = $p;
        }
        $top = $crumbs !== [] ? $crumbs[count($crumbs) - 1] : $root;

        return [
            'root' => $root,
            'nodes' => $rows,
            'shown' => count($rows),
            'total' => $size[$root],
            'crumbs' => array_reverse($crumbs),
            'edge' => ['L' => $this->edge($nodes, $root, 'bl'), 'R' => $this->edge($nodes, $root, 'br')],
            'tops' => $this->topList($tops, $top),
            'others' => count($tops) - (isset($tops[$top]) ? 1 : 0),
            'top' => $top,
        ];
    }

    /**
     * ลูกในผัง (เฉพาะที่ชี้กลับมาหาแม่จริง)
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, string> ขา => กระเป๋า
     */
    private function kids(array $nodes, string $w): array
    {
        $out = [];
        foreach ([GridPlacer::LEFT => 'bl', GridPlacer::RIGHT => 'br'] as $leg => $key) {
            $c = $nodes[$w][$key] ?? null;
            if (is_string($c) && $c !== $w && isset($nodes[$c]) && ($nodes[$c]['parent'] ?? null) === $w) {
                $out[$leg] = $c;
            }
        }

        return $out;
    }

    /**
     * ทุกกระเป๋าใต้ $root รวมตัวเอง เรียงชั้นต่อชั้น — กระเป๋าที่เจอซ้ำ (ข้อมูลวน) ถูกข้าม
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function subtree(array $nodes, string $root): array
    {
        $order = [$root];
        $seen = [$root => true];
        for ($i = 0; $i < count($order); $i++) {
            foreach ($this->kids($nodes, $order[$i]) as $c) {
                if (! isset($seen[$c])) {
                    $seen[$c] = true;
                    $order[] = $c;
                }
            }
        }

        return $order;
    }

    /**
     * ล่างสุดของขอบซ้าย/ขวา (ไล่ลงขาเดียวกันจนสุด) — null = root ไม่มีขานั้น
     *
     * @param  array<string, array<string, mixed>>  $nodes
     */
    private function edge(array $nodes, string $root, string $key): ?string
    {
        $leg = $key === 'bl' ? GridPlacer::LEFT : GridPlacer::RIGHT;
        $cur = $root;
        $seen = [$root => true];
        for ($i = 0; $i < self::WALK; $i++) {
            $next = $this->kids($nodes, $cur)[$leg] ?? null;
            if ($next === null || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $cur = $next;
        }

        return $cur !== $root ? $cur : null;
    }

    /**
     * ผังแยกอื่น (ไม่รวมผังที่กำลังดู) ใหญ่สุดก่อน ไม่เกิน 12
     *
     * @param  array<string, int>  $tops
     * @return list<array{wallet: string, size: int}>
     */
    private function topList(array $tops, ?string $current): array
    {
        $out = [];
        foreach ($tops as $w => $size) {
            if ($w !== $current) {
                $out[] = ['wallet' => (string) $w, 'size' => $size];
            }
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }
}

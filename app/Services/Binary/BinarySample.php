<?php

namespace App\Services\Binary;

use App\Services\Grid\GridPlacer;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * ข้อมูลตัวอย่างของหน้า "ผังสายงาน Binary" (ปุ่ม "ตัวอย่าง" ในหลังบ้าน) — ให้เห็นหน้าตาผังก่อนมีสมาชิกจริง
 *
 * สร้างในหน่วยความจำทุกครั้ง ไม่เขียนฐานข้อมูล ไม่ปนกับสมุดบัญชีจริง (BinaryLedger) และไม่ถูกใช้คิดรางวัลที่ไหนเลย
 * สุ่มแบบกำหนดเมล็ด → เปิดกี่ครั้งก็ได้ผังเดิม (ลิงก์ ?root= ในโหมดตัวอย่างจึงใช้ได้)
 * book() คืนรูปเดียวกับ BinaryLedger::book()['nodes'] เฉพาะช่องที่ BinaryMap::slice() ใช้
 *
 * กติกาของตัวเลขตัวอย่าง (ย่อจากของจริง ช่วงเวลาเดียว): ยอดขา = ยอดฝากของทุกคนในขานั้น ไม่นับกระเป๋าที่ถูกพัก ·
 * ผ่านเงื่อนไข = ฝากถึงขั้นต่ำ + มีเพื่อนตรงที่ฝากถึงขั้นต่ำขาละ 1 คน · จับคู่ = ขาที่น้อยกว่า ส่วนเกินเป็นยอดยกมา
 */
final class BinarySample
{
    /** ชุดตัวอย่าง: key => [จำนวนกระเป๋าในผังหลัก, เมล็ดสุ่ม, ป้ายบนปุ่ม] */
    public const SETS = [
        'small' => [20, 11, 'ทีมเริ่มต้น 20 คน'],
        'mid' => [150, 5, 'ทีมกลาง 150 คน'],
        'big' => [900, 9, 'ทีมใหญ่ 900 คน'],
    ];

    public const DEFAULT = 'small';

    private const MAX_LEVEL = 10;

    /** ค่า ?sample= ที่ส่งมา → key ของชุดตัวอย่าง · ไม่รู้จัก/ไม่ได้ส่ง = null (แสดงข้อมูลจริง) */
    public static function key(mixed $v): ?string
    {
        return is_string($v) && isset(self::SETS[$v]) ? $v : null;
    }

    /**
     * @param  float  $min  ฝากขั้นต่ำของแผน (USDT) — ยอดฝากตัวอย่างเป็นจำนวนเท่าของค่านี้
     * @return array{nodes: array<string, array<string, mixed>>}
     */
    public function book(string $set, float $min): array
    {
        [$count, $seed] = self::SETS[$set] ?? self::SETS[self::DEFAULT];
        $unit = $min > 0 ? $min : 10.0;
        $rnd = new Randomizer(new Mt19937($seed));
        $pick = fn (array $a) => $a[$rnd->getInt(0, count($a) - 1)];
        $nodes = [];

        $add = function (?string $parent, ?string $leg, ?string $sponsor) use (&$nodes, $rnd): string {
            do {
                $w = '0x'.bin2hex($rnd->getBytes(20));
            } while (isset($nodes[$w]));
            $nodes[$w] = [
                'wallet' => $w, 'referrer' => $sponsor, 'parent' => $parent, 'leg' => $leg, 'bl' => null, 'br' => null,
                'usdt' => 0.0, 'held' => false, 'l' => 0.0, 'r' => 0.0, 'cl' => 0.0, 'cr' => 0.0, 'dl' => 0, 'dr' => 0, 'q' => false,
                'size' => 1, 'level' => $parent !== null ? $nodes[$parent]['level'] + 1 : 0,
            ];
            if ($parent !== null) {
                $nodes[$parent][$leg === GridPlacer::LEFT ? 'bl' : 'br'] = $w;
                for ($p = $parent; $p !== null; $p = $nodes[$p]['parent']) {
                    $nodes[$p]['size']++;
                }
            }

            return $w;
        };
        $size = function (?string $w) use (&$nodes): int {     // ต้องอ้างอิง $nodes ตัวจริง (arrow fn จะได้สำเนาตอนประกาศ)
            return $w !== null ? $nodes[$w]['size'] : 0;
        };
        // ช่องว่างแรกใต้ $from ไล่ทีละชั้น ซ้ายก่อนขวา
        $free = function (string $from) use (&$nodes): array {
            $queue = [$from];
            for ($i = 0; $i < count($queue); $i++) {
                $x = $nodes[$queue[$i]];
                if ($x['bl'] === null) {
                    return [$queue[$i], GridPlacer::LEFT];
                }
                if ($x['br'] === null) {
                    return [$queue[$i], GridPlacer::RIGHT];
                }
                $queue[] = $x['bl'];
                $queue[] = $x['br'];
            }

            return [$from, GridPlacer::LEFT];
        };
        // ที่วางของคนใหม่ในทีมผู้แนะนำ: ลงขาที่คนน้อยกว่าเป็นหลัก (วิธีวาง "ขาอ่อน") · $edge = ไล่ลงขอบซ้ายจนสุด (วิธีวาง "ขอบซ้าย")
        $spot = function (string $sponsor, bool $edge) use (&$nodes, $rnd, $size, $free): array {
            $cur = $sponsor;
            if ($edge) {
                while ($nodes[$cur]['bl'] !== null) {
                    $cur = $nodes[$cur]['bl'];
                }

                return [$cur, GridPlacer::LEFT];
            }
            while (true) {
                $x = $nodes[$cur];
                $weak = $size($x['bl']) <= $size($x['br']) ? GridPlacer::LEFT : GridPlacer::RIGHT;
                $leg = $rnd->nextFloat() < 0.72 ? $weak : ($rnd->nextFloat() < 0.5 ? GridPlacer::LEFT : GridPlacer::RIGHT);
                $kid = $leg === GridPlacer::LEFT ? $x['bl'] : $x['br'];
                if ($kid === null) {
                    return [$cur, $leg];
                }
                if ($nodes[$kid]['level'] >= self::MAX_LEVEL) {
                    return $free($sponsor);
                }
                $cur = $kid;
            }
        };

        // ผังหลัก: คนที่เข้ามาก่อนชวนได้มากกว่า
        $main = [$add(null, null, null)];
        for ($i = 1; $i < $count; $i++) {
            $sponsor = $main[(int) floor(($rnd->nextFloat() ** 1.8) * count($main))];
            $edge = count($main) > 6 && $sponsor === $main[2] && $rnd->nextFloat() < 0.55;
            [$parent, $leg] = $spot($sponsor, $edge);
            $main[] = $add($parent, $leg, $sponsor);
        }
        // ผังแยกที่ยังไม่ต่อกับผังหลัก + กระเป๋าเดี่ยวหนึ่งใบ
        $side = [$add(null, null, null)];
        for ($i = 1, $n = max(3, (int) round($count / 30)); $i < $n; $i++) {
            $sponsor = $pick($side);
            [$parent, $leg] = $spot($sponsor, false);
            $side[] = $add($parent, $leg, $sponsor);
        }
        $add(null, null, null);

        // ยอดฝากเอง: หัวทีมฝากมาก · บางคนยังไม่ฝาก · บางคนฝากไม่ถึงขั้นต่ำ
        $all = array_keys($nodes);
        foreach ($all as $i => $w) {
            $roll = $rnd->nextFloat();
            $nodes[$w]['usdt'] = match (true) {
                $i < 4 => $unit * $pick([100, 250, 500]),
                $roll < 0.15 => 0.0,
                $roll < 0.23 => $unit / 2,
                default => $unit * $pick([1, 1, 2, 5, 5, 10, 10, 20, 50, 100, 250]),
            };
        }
        if ($count > 30) {
            $nodes[$main[7]]['held'] = true;
            $nodes[$main[23]]['held'] = true;
        }

        // ยอดขา — ลูกถูกสร้างหลังแม่เสมอ จึงย้อนจากท้ายได้ลูกก่อนแม่
        $volume = [];
        for ($i = count($all) - 1; $i >= 0; $i--) {
            $n = &$nodes[$all[$i]];
            $n['l'] = $n['bl'] !== null ? $volume[$n['bl']] : 0.0;
            $n['r'] = $n['br'] !== null ? $volume[$n['br']] : 0.0;
            $volume[$all[$i]] = ($n['held'] ? 0.0 : $n['usdt']) + $n['l'] + $n['r'];
            unset($n);
        }
        // เพื่อนตรงที่ฝากถึงขั้นต่ำ แยกตามขาของผู้แนะนำ
        foreach ($all as $w) {
            $sponsor = $nodes[$w]['referrer'];
            if ($sponsor === null || $nodes[$w]['usdt'] < $unit) {
                continue;
            }
            $c = $w;
            while ($nodes[$c]['parent'] !== null && $nodes[$c]['parent'] !== $sponsor) {
                $c = $nodes[$c]['parent'];
            }
            if ($nodes[$c]['parent'] === $sponsor) {
                $nodes[$sponsor][$nodes[$c]['leg'] === GridPlacer::LEFT ? 'dl' : 'dr']++;
            }
        }
        foreach ($all as $w) {
            $n = &$nodes[$w];
            $n['q'] = $n['usdt'] >= $unit && $n['dl'] >= 1 && $n['dr'] >= 1;
            if ($n['q']) {
                $matched = min($n['l'], $n['r']);
                $n['cl'] = $n['l'] - $matched;
                $n['cr'] = $n['r'] - $matched;
            }
            unset($n['size'], $n['level'], $n);
        }

        return ['nodes' => $nodes];
    }
}

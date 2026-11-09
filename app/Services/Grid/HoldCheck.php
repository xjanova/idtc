<?php

namespace App\Services\Grid;

use App\Services\Chain\TransferIndexer;
use App\Support\Idtc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ผู้ซื้อถือเหรียญครบระยะจริงหรือไม่ — ไล่ยอดคงเหลือของแต่ละกระเป๋าจาก event Transfer ตามลำดับบนเชน
 *
 * กติกา: การซื้อแต่ละรายการ "ล็อก" จำนวน IDTC ที่ได้ไว้ hold_days นับจากเวลาซื้อ
 *  - โอนออก/ขายจนยอดคงเหลือต่ำกว่ายอดที่ยังล็อกอยู่ → ส่วนที่ขาดถูกตัดจากการซื้อ "ล่าสุด" ก่อน (ตัดแล้วไม่คืน)
 *  - เหรียญที่มีอยู่ก่อน หรือที่ครบระยะแล้ว เป็นกันชน: โอนส่วนนั้นออกได้โดยไม่กระทบการซื้อที่ยังล็อก
 *  - kept = สัดส่วนของการซื้อนั้นที่ยังนับรางวัล (1 = ถือครบ · 0 = ออกหมด)
 *  - ยอดติดลบ = ประวัติก่อนบล็อกเริ่มดึงไม่ครบ → ไม่ตัดอะไรของกระเป๋านั้น (gap) ให้แอดมินตรวจเอง
 *
 * ยังไม่ได้ตั้งสัญญาเหรียญ = ปิดการตรวจ (enabled false) → ใช้แค่เวลา (เหมือนระยะที่ 1)
 * ตั้งแล้ว = การซื้อจะพร้อมจ่ายได้เมื่อดึงการโอนครบถึงเวลาที่ครบระยะถือของมันแล้วเท่านั้น (covered)
 */
final class HoldCheck
{
    public function __construct(protected TransferIndexer $transfers) {}

    public function enabled(): bool
    {
        return Idtc::contract('token') !== null;
    }

    /** ดึงการโอนครบถึงเวลา (unix) — null = ยังไม่เคยดึง */
    public function coveredUntil(): ?int
    {
        return $this->transfers->coveredUntil();
    }

    /**
     * @param  list<array{id: int, buyer: string, idtc: float, block: int, log: int, at: int}>  $purchases
     * @return array{enabled: bool, covered: int|null, lots: array<int, array{kept: float, cut: float, cut_at: int|null}>, gaps: array<string, true>}
     */
    public function evaluate(array $purchases, int $holdSec): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false, 'covered' => null, 'lots' => [], 'gaps' => []];
        }
        $token = strtolower((string) Idtc::contract('token'));
        $buyers = array_values(array_unique(array_map(fn ($p) => strtolower($p['buyer']), $purchases)));
        $transfers = [];
        foreach (array_chunk($buyers, 400) as $part) {
            $rows = DB::table('token_transfers')
                ->where('contract', $token)
                ->where(fn ($q) => $q->whereIn('from', $part)->orWhereIn('to', $part))
                ->get(['id', 'from', 'to', 'amount', 'block_number', 'log_index', 'block_time']);
            foreach ($rows as $r) {
                $transfers[(int) $r->id] = [
                    'from' => $r->from, 'to' => $r->to, 'amount' => (float) $r->amount,
                    'block' => (int) $r->block_number, 'log' => (int) $r->log_index,
                    'at' => $r->block_time ? CarbonImmutable::parse($r->block_time)->getTimestamp() : 0,
                ];
            }
        }

        $res = self::run($purchases, array_values($transfers), $holdSec);

        return ['enabled' => true, 'covered' => $this->coveredUntil()] + $res;
    }

    /**
     * คำนวณล้วน (ไม่แตะฐานข้อมูล)
     *
     * @param  list<array{id: int, buyer: string, idtc: float, block: int, log: int, at: int}>  $purchases
     * @param  list<array{from: string, to: string, amount: float, block: int, log: int, at: int}>  $transfers
     * @return array{lots: array<int, array{kept: float, cut: float, cut_at: int|null}>, gaps: array<string, true>}
     */
    public static function run(array $purchases, array $transfers, int $holdSec): array
    {
        // เหตุการณ์ของแต่ละกระเป๋า: +เข้า −ออก และ "ล็อก" ของการซื้อ
        $events = [];
        foreach ($transfers as $t) {
            $from = strtolower($t['from']);
            $to = strtolower($t['to']);
            if ($from === $to || $t['amount'] <= 0) {
                continue;
            }
            $events[$to][] = ['b' => $t['block'], 'l' => $t['log'], 'o' => 0, 'at' => $t['at'], 'kind' => 'in', 'v' => $t['amount']];
            $events[$from][] = ['b' => $t['block'], 'l' => $t['log'], 'o' => 0, 'at' => $t['at'], 'kind' => 'out', 'v' => $t['amount']];
        }
        $lots = [];
        $byWallet = [];
        foreach ($purchases as $p) {
            $w = strtolower($p['buyer']);
            $lots[$p['id']] = ['kept' => 1.0, 'cut' => 0.0, 'cut_at' => null];
            $byWallet[$w] = true;
            // ล็อกหลังเหรียญเข้า (Transfer ของการซื้ออยู่ก่อน Purchased ในธุรกรรมเดียวกัน)
            $events[$w][] = ['b' => $p['block'], 'l' => $p['log'], 'o' => 1, 'at' => $p['at'], 'kind' => 'lock', 'v' => $p['idtc'], 'id' => $p['id']];
        }

        $gaps = [];
        foreach (array_keys($byWallet) as $w) {
            $list = $events[$w];
            usort($list, fn ($x, $y) => [$x['b'], $x['l'], $x['o']] <=> [$y['b'], $y['l'], $y['o']]);
            $bal = 0.0;
            $active = []; // id => [amount, kept, until] เรียงตามลำดับซื้อ
            $gap = false;
            foreach ($list as $e) {
                foreach ($active as $id => $a) {
                    if ($a[2] <= $e['at']) {
                        unset($active[$id]); // ครบระยะแล้ว: kept คงที่
                    }
                }
                if ($e['kind'] === 'in') {
                    $bal += $e['v'];
                } elseif ($e['kind'] === 'lock') {
                    $active[$e['id']] = [$e['v'], $e['v'], $e['at'] + $holdSec];
                } else {
                    $bal -= $e['v'];
                    $eps = 1e-9 * max(1.0, $e['v']);
                    if ($bal < -$eps) {
                        $gap = true;
                        break;
                    }
                    $locked = 0.0;
                    foreach ($active as $a) {
                        $locked += $a[1];
                    }
                    $short = $locked - max(0.0, $bal);
                    if ($short > $eps) {
                        foreach (array_reverse(array_keys($active)) as $id) {
                            $take = min($short, $active[$id][1]);
                            if ($take <= 0) {
                                continue;
                            }
                            $active[$id][1] -= $take;
                            $short -= $take;
                            $lots[$id]['cut'] += $take;
                            $lots[$id]['cut_at'] ??= $e['at'];
                            if ($short <= $eps) {
                                break;
                            }
                        }
                    }
                }
            }
            if ($gap) {
                $gaps[$w] = true;
                foreach ($purchases as $p) {
                    if (strtolower($p['buyer']) === $w) {
                        $lots[$p['id']] = ['kept' => 1.0, 'cut' => 0.0, 'cut_at' => null];
                    }
                }

                continue;
            }
        }

        foreach ($purchases as $p) {
            $amt = $p['idtc'];
            $lot = &$lots[$p['id']];
            $lot['kept'] = $amt > 0 ? max(0.0, min(1.0, 1 - $lot['cut'] / $amt)) : 1.0;
            if ($lot['kept'] > 1 - 1e-12) {
                $lot['kept'] = 1.0;
            }
            unset($lot);
        }

        return ['lots' => $lots, 'gaps' => $gaps];
    }
}

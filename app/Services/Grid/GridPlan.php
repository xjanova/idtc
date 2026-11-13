<?php

namespace App\Services\Grid;

use App\Models\GridPlanVersion;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * แผนรางวัล IDTC Grid — ค่าเริ่มต้นจาก config/idtc.php (grid.plan) และเวอร์ชันที่แอดมินแก้ (grid_plan_versions)
 *
 * สัปดาห์ขาย k = จำนวนสัปดาห์เต็มนับจาก sale.start (ก่อนเริ่มขาย = -1)
 * การซื้อในสัปดาห์ k ใช้อัตราของเวอร์ชันล่าสุดที่ from_week <= k เสมอ — แก้แผนแล้วรางวัลเก่าไม่เปลี่ยน
 * แผนใหม่มีผลสัปดาห์ถัดไปเท่านั้น (สัปดาห์ที่กำลังขายใช้อัตราที่ประกาศไว้แล้ว)
 */
final class GridPlan
{
    public const WEEK = 604800;

    /** @var list<array{id: int|null, from: int, plan: array<string, mixed>, note: string|null, by: string|null, at: int|null}>|null */
    protected ?array $versions = null;

    public function __construct(protected Settings $settings) {}

    public function enabled(): bool
    {
        $v = $this->settings->get('grid.enabled');

        return $v === null ? (bool) config('idtc.grid.enabled') : (bool) $v;
    }

    public static function saleStart(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('idtc.sale.start'));
    }

    public static function saleCap(): float
    {
        return (float) config('idtc.sale.cap');
    }

    /** สัปดาห์ขายของเวลานี้ · ก่อนเริ่มขาย = -1 */
    public static function weekAt(CarbonInterface|int $time): int
    {
        $ts = $time instanceof CarbonInterface ? $time->getTimestamp() : $time;
        $diff = $ts - self::saleStart()->getTimestamp();

        return $diff < 0 ? -1 : intdiv($diff, self::WEEK);
    }

    /** สัปดาห์ขายของการซื้อ — ซื้อก่อนเวลาเริ่มตามแผน (สัญญาเปิดก่อน) นับเป็นสัปดาห์แรก */
    public static function purchaseWeek(int $ts): int
    {
        return max(0, self::weekAt($ts));
    }

    public static function weekStart(int $week): CarbonImmutable
    {
        return self::saleStart()->addSeconds($week * self::WEEK);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return self::normalize((array) config('idtc.grid.plan', []));
    }

    /**
     * จัดรูปแผนให้ครบทุกช่องและชนิดถูก (ช่องที่ขาดใช้ค่าเริ่มต้น) — ไม่ตรวจความสมเหตุสมผล ดู errors()
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function normalize(array $p): array
    {
        $d = (array) config('idtc.grid.plan', []);
        $levels = array_values((array) ($p['levels'] ?? $d['levels'] ?? [0, 0, 0]));
        $boost = [];
        foreach ((array) ($p['boost'] ?? $d['boost'] ?? []) as $b) {
            if (is_array($b)) {
                $boost[] = ['from' => (int) ($b['from'] ?? 0), 'to' => (int) ($b['to'] ?? 0), 'add' => (float) ($b['add'] ?? 0)];
            }
        }
        $rank = (array) ($p['rank'] ?? []) + (array) ($d['rank'] ?? []);
        $bin = (array) ($p['binary'] ?? []) + (array) ($d['binary'] ?? []);

        return [
            'levels' => [(float) ($levels[0] ?? 0), (float) ($levels[1] ?? 0), (float) ($levels[2] ?? 0)],
            'buyer' => (float) ($p['buyer'] ?? $d['buyer'] ?? 0),
            'boost' => $boost,
            'sprint' => (float) ($p['sprint'] ?? $d['sprint'] ?? 0),
            'sprint_cap' => (float) ($p['sprint_cap'] ?? $d['sprint_cap'] ?? 1),
            'hold_days' => (int) ($p['hold_days'] ?? $d['hold_days'] ?? 0),
            'instant' => (float) ($p['instant'] ?? $d['instant'] ?? 1),
            'rank' => [
                'spark_own' => (float) ($rank['spark_own'] ?? 10),
                'cell_d' => (int) ($rank['cell_d'] ?? 2),
                'grid_d' => (int) ($rank['grid_d'] ?? 4),
                'grid_t' => (float) ($rank['grid_t'] ?? 1000),
                'core_d' => (int) ($rank['core_d'] ?? 10),
                'core_t' => (float) ($rank['core_t'] ?? 10000),
            ],
            'budget' => (string) (is_numeric($p['budget'] ?? null) ? $p['budget'] : ($d['budget'] ?? '0')),
            'binary' => [
                'pool' => (float) ($bin['pool'] ?? 0),
                'pair' => (float) ($bin['pair'] ?? 0.1),
                'cap' => (float) ($bin['cap'] ?? 0.2),
                'directs' => (int) ($bin['directs'] ?? 1),
            ],
        ];
    }

    /** อัตรารวมสูงสุดที่การซื้อหนึ่งรายการอาจต้องจ่าย (ทุกวง + โบนัสสูงสุด + ผู้ซื้อ + Sprint + กอง Binary) */
    public static function maxRate(array $p): float
    {
        $boost = 0.0;
        foreach ($p['boost'] as $b) {
            $boost = max($boost, (float) $b['add']);
        }

        return array_sum($p['levels']) + $boost + $p['buyer'] + $p['sprint'] + self::binaryPool($p);
    }

    /** กอง Binary ของแผนนี้ (สัดส่วนของ IDTC ที่ขายในสัปดาห์) · 0 = ปิด Binary */
    public static function binaryPool(array $p): float
    {
        return max(0.0, (float) ($p['binary']['pool'] ?? 0));
    }

    /** อัตราวง L (1..3) ของสัปดาห์ k ในแผนนี้ */
    public static function ringRate(array $p, int $ring, int $week): float
    {
        $rate = (float) $p['levels'][$ring - 1];
        if ($ring === 1) {
            foreach ($p['boost'] as $b) {
                if ($week >= $b['from'] && $week < $b['to']) {
                    $rate += $b['add'];
                    break;
                }
            }
        }

        return $rate;
    }

    /**
     * ตรวจความสมเหตุสมผลของแผน (ข้อความไทย — ใช้ในหลังบ้านเท่านั้น)
     *
     * @return array<string, string> ช่อง => ข้อความผิดพลาด
     */
    public static function errors(array $p): array
    {
        $e = [];
        $rate = fn (float $v, float $max) => $v >= 0 && $v <= $max && is_finite($v);
        foreach ($p['levels'] as $i => $v) {
            if (! $rate($v, 0.2)) {
                $e['levels.'.$i] = 'อัตราวง '.($i + 1).' ต้องอยู่ระหว่าง 0% ถึง 20%';
            }
        }
        if (! isset($e['levels.0'], $e['levels.1']) && $p['levels'][1] > $p['levels'][0] + 1e-12) {
            $e['levels.1'] = 'วง 2 ต้องไม่มากกว่าวง 1 (วงใกล้ได้มากกว่าวงไกล)';
        }
        if (! isset($e['levels.1'], $e['levels.2']) && $p['levels'][2] > $p['levels'][1] + 1e-12) {
            $e['levels.2'] = 'วง 3 ต้องไม่มากกว่าวง 2';
        }
        if (! $rate($p['buyer'], 0.1)) {
            $e['buyer'] = 'ส่วนของผู้ซื้อต้องอยู่ระหว่าง 0% ถึง 10%';
        }
        if (! $rate($p['sprint'], 0.1)) {
            $e['sprint'] = 'กอง Sprint ต้องอยู่ระหว่าง 0% ถึง 10%';
        }
        if (! ($p['sprint_cap'] >= 0.01 && $p['sprint_cap'] <= 1)) {
            $e['sprint_cap'] = 'เพดาน Sprint ต่อคนต้องอยู่ระหว่าง 1% ถึง 100%';
        }
        if ($p['hold_days'] < 0 || $p['hold_days'] > 90) {
            $e['hold_days'] = 'ระยะถือต้องอยู่ระหว่าง 0 ถึง 90 วัน';
        }
        if (! ($p['instant'] >= 0 && $p['instant'] <= 1)) {
            $e['instant'] = 'ส่วนที่รับทันทีต้องอยู่ระหว่าง 0% ถึง 100%';
        }
        if (count($p['boost']) > 6) {
            $e['boost'] = 'โบนัสช่วงเปิดตัวมีได้ไม่เกิน 6 ช่วง';
        }
        $last = 0;
        foreach ($p['boost'] as $i => $b) {
            if ($b['from'] < 0 || $b['to'] <= $b['from'] || $b['to'] > 60) {
                $e['boost.'.$i] = 'ช่วงโบนัสที่ '.($i + 1).': สัปดาห์ต้องเริ่มก่อนจบ และอยู่ในช่วงสัปดาห์ 1–60';
            } elseif ($b['from'] < $last) {
                $e['boost.'.$i] = 'ช่วงโบนัสที่ '.($i + 1).' ซ้อนกับช่วงก่อนหน้า';
            } elseif (! $rate($b['add'], 0.1)) {
                $e['boost.'.$i] = 'โบนัสช่วงที่ '.($i + 1).' ต้องอยู่ระหว่าง 0% ถึง 10%';
            }
            $last = max($last, $b['to']);
        }
        $r = $p['rank'];
        if ($r['spark_own'] < 0 || $r['grid_t'] < 0 || $r['core_t'] < 0 || $r['cell_d'] < 0 || $r['grid_d'] < 0 || $r['core_d'] < 0) {
            $e['rank'] = 'เงื่อนไขระดับต้องไม่ติดลบ';
        } elseif ($r['grid_d'] < $r['cell_d'] || $r['core_d'] < $r['grid_d'] || $r['core_t'] < $r['grid_t']) {
            $e['rank'] = 'เงื่อนไขระดับสูงต้องไม่ต่ำกว่าระดับก่อนหน้า (เซลล์ ≤ กริด ≤ คอร์)';
        }
        $b = $p['binary'];
        if (! $rate($b['pool'], 0.1)) {
            $e['binary.pool'] = 'กอง Binary ต้องอยู่ระหว่าง 0% ถึง 10% (0 = ปิด Binary)';
        }
        if (! $rate($b['pair'], 0.5) || ($b['pool'] > 0 && $b['pair'] <= 0)) {
            $e['binary.pair'] = 'อัตราจับคู่ต้องมากกว่า 0% และไม่เกิน 50% ของยอดขาอ่อน';
        }
        if (! ($b['cap'] >= 0.01 && $b['cap'] <= 1)) {
            $e['binary.cap'] = 'เพดาน Binary ต่อคนต้องอยู่ระหว่าง 1% ถึง 100% ของกอง';
        }
        if ($b['directs'] < 0 || $b['directs'] > 10) {
            $e['binary.directs'] = 'เพื่อนตรงต่อขาต้องอยู่ระหว่าง 0 ถึง 10 คน';
        }
        $budget = (float) $p['budget'];
        $ops = self::operationsAllocation();
        if (! ($budget > 0) || ($ops > 0 && $budget > $ops)) {
            $e['budget'] = 'งบต้องมากกว่า 0 และไม่เกินก้อนดำเนินงาน/การตลาด ('.number_format($ops).' IDTC)';
        }

        return $e;
    }

    public static function operationsAllocation(): float
    {
        foreach ((array) config('idtc.allocation', []) as $a) {
            if (($a['key'] ?? null) === 'operations') {
                return (float) $a['amount'];
            }
        }

        return 0.0;
    }

    /**
     * ทุกเวอร์ชัน เรียงตามสัปดาห์ที่มีผล — ยังไม่เคยแก้ = เวอร์ชันเดียวจาก config ตั้งแต่สัปดาห์ 0
     *
     * @return list<array{id: int|null, from: int, plan: array<string, mixed>, note: string|null, by: string|null, at: int|null}>
     */
    public function versions(): array
    {
        if ($this->versions !== null) {
            return $this->versions;
        }
        $rows = [];
        try {
            $rows = GridPlanVersion::query()->orderBy('from_week')->orderBy('id')->get()->all();
        } catch (Throwable) {
            // ยังไม่ได้ migrate — ใช้แผนจาก config
        }
        $list = [];
        if (! $rows || $rows[0]->from_week > 0) {
            $list[] = ['id' => null, 'from' => 0, 'plan' => self::defaults(), 'note' => 'แผนเริ่มต้น (config/idtc.php)', 'by' => null, 'at' => null];
        }
        foreach ($rows as $row) {
            $list[] = [
                'id' => $row->id,
                'from' => $row->from_week,
                'plan' => self::normalize($row->plan),
                'note' => $row->note,
                'by' => $row->created_by,
                'at' => $row->created_at?->getTimestamp(),
            ];
        }

        return $this->versions = $list;
    }

    public function forget(): void
    {
        $this->versions = null;
    }

    /** @return array<string, mixed> แผนที่ใช้กับสัปดาห์ขาย k */
    public function at(int $week): array
    {
        $plan = null;
        foreach ($this->versions() as $v) {
            if ($v['from'] <= max(0, $week)) {
                $plan = $v['plan'];
            }
        }

        return $plan ?? $this->versions()[0]['plan'];
    }

    /** @return array<string, mixed> แผนที่ใช้อยู่ตอนนี้ (เงื่อนไขระดับ ระยะถือ งบ ใช้ค่านี้) */
    public function current(?CarbonInterface $now = null): array
    {
        return $this->at(self::weekAt($now ?? CarbonImmutable::now()));
    }

    /** เวอร์ชันที่ตั้งไว้แล้วแต่ยังไม่ถึงสัปดาห์ที่มีผล */
    public function pending(?CarbonInterface $now = null): ?array
    {
        $week = self::weekAt($now ?? CarbonImmutable::now());
        $found = null;
        foreach ($this->versions() as $v) {
            if ($v['id'] !== null && $v['from'] > max(0, $week)) {
                $found = $v;
            }
        }

        return $found;
    }

    /** สัปดาห์แรกที่แผนใหม่มีผลได้ (สัปดาห์ถัดไป · ก่อนเริ่มขาย = สัปดาห์แรก) */
    public static function nextEffectiveWeek(?CarbonInterface $now = null): int
    {
        $week = self::weekAt($now ?? CarbonImmutable::now());

        return $week < 0 ? 0 : $week + 1;
    }
}

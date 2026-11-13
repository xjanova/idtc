<?php

namespace App\Services\Binary;

use App\Models\BinaryPlanVersion;
use App\Services\Chain\Address;
use App\Services\Grid\GridPlan;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * แผน Binary โปรโมชั่น (idtc.online/binary) — แยกจาก presale และ IDTC Grid
 *
 * สองส่วน:
 *  1. ค่าที่มีผลทันที (settings binary.*): เปิด/ปิด · กระเป๋ารับ USDT · โควตา IDTC และก้อนที่มา · ปลายทาง USDT ที่เหลือ · วันสิ้นสุด
 *  2. อัตราตามสัปดาห์ (ตาราง binary_plan_versions): ราคา · ขั้นต่ำ · ส่วนเข้ากองรางวัล · อัตราจับคู่ · เพดานต่อคน · เพื่อนตรงต่อขา
 *     แก้แล้วมีผลสัปดาห์ถัดไป — การฝากของสัปดาห์ที่กำลังเปิดใช้อัตราที่ประกาศไว้แล้ว (ปฏิทินสัปดาห์เดียวกับ presale: GridPlan::weekAt)
 *
 * ไม่จ่ายเกิน: รางวัลจับคู่ของสัปดาห์จ่ายจาก "กอง" = pool × USDT ที่ฝากสัปดาห์นั้นเท่านั้น (BinaryLedger)
 * แผนเปิดรับได้เมื่อ: เปิดไว้ + ตั้งกระเป๋า + ตั้งโควตา IDTC > 0 (ready())
 */
final class BinaryPlan
{
    public const REST = ['pool' => 'เข้าพูลสภาพคล่อง IDTC/USDT', 'treasury' => 'เข้ากระเป๋าโครงการ'];

    /** @var list<array{id: int|null, from: int, plan: array<string, mixed>, note: string|null, by: string|null, at: int|null}>|null */
    protected ?array $versions = null;

    public function __construct(protected Settings $settings) {}

    // ───────────── ค่าที่มีผลทันที ─────────────

    public function enabled(): bool
    {
        $v = $this->settings->get('binary.enabled');

        return $v === null ? (bool) config('idtc.binary.enabled') : (bool) $v;
    }

    /** กระเป๋าของโครงการที่รับ USDT (ตัวพิมพ์เล็ก) · null = ยังไม่ตั้ง */
    public function wallet(): ?string
    {
        $w = $this->settings->get('binary.wallet');

        return is_string($w) && Address::isValid($w) && ! Address::isZero($w) ? strtolower($w) : null;
    }

    /** บล็อกแรกที่เริ่มอ่านการโอนเข้ากระเป๋านี้ (บล็อกล่าสุดตอนตั้งกระเป๋า) */
    public function startBlock(): ?int
    {
        $b = $this->settings->get('binary.start_block');

        return is_numeric($b) && (int) $b >= 0 ? (int) $b : null;
    }

    /** โควตา IDTC ที่ขายผ่านแผนนี้ได้ (หน่วยเต็ม) · 0 = ยังไม่ตั้ง */
    public function quota(): float
    {
        $q = $this->settings->get('binary.quota');

        return is_numeric($q) ? max(0.0, (float) $q) : 0.0;
    }

    /** key ของก้อนใน config idtc.allocation ที่ IDTC ของแผนนี้มาจาก · null = ยังไม่ระบุ */
    public function source(): ?string
    {
        $s = $this->settings->get('binary.source');

        return is_string($s) && self::allocation($s) !== null ? $s : null;
    }

    /** @return array<string, mixed>|null */
    public static function allocation(string $key): ?array
    {
        foreach ((array) config('idtc.allocation', []) as $a) {
            if (($a['key'] ?? null) === $key) {
                return $a;
            }
        }

        return null;
    }

    /** USDT ส่วนที่ไม่ได้เข้ากองรางวัลไปไหน (pool | treasury) — แสดงบนหน้า /binary ตามจริง */
    public function rest(): string
    {
        $r = $this->settings->get('binary.rest');

        return is_string($r) && isset(self::REST[$r]) ? $r : 'pool';
    }

    public function endsAt(): ?CarbonImmutable
    {
        $e = $this->settings->get('binary.ends');
        try {
            return is_string($e) && $e !== '' ? CarbonImmutable::parse($e) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** เปิดรับฝากได้จริงไหม (เปิด + มีกระเป๋า + มีโควตา) */
    public function ready(): bool
    {
        return $this->enabled() && $this->wallet() !== null && $this->quota() > 0;
    }

    /** @return list<string> สิ่งที่ยังขาดก่อนเปิดรับได้ (ข้อความไทย — หลังบ้าน) */
    public function missing(): array
    {
        $m = [];
        if ($this->wallet() === null) {
            $m[] = 'ยังไม่ได้ตั้งกระเป๋า Binary ที่รับ USDT';
        }
        if ($this->quota() <= 0) {
            $m[] = 'ยังไม่ได้ตั้งโควตา IDTC ของแผน';
        }
        if ($this->source() === null) {
            $m[] = 'ยังไม่ได้ระบุว่า IDTC ของแผนมาจากก้อนไหน';
        }

        return $m;
    }

    // ───────────── อัตราตามสัปดาห์ ─────────────

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return self::normalize((array) config('idtc.binary.plan', []));
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{price: string, min_usdt: float, pool: float, pair: float, cap: float, directs: int}
     */
    public static function normalize(array $p): array
    {
        $d = (array) config('idtc.binary.plan', []);
        $price = $p['price'] ?? $d['price'] ?? config('idtc.sale.price_usd');

        return [
            'price' => is_numeric($price) ? (string) $price : '0',
            'min_usdt' => (float) ($p['min_usdt'] ?? $d['min_usdt'] ?? 10),
            'pool' => (float) ($p['pool'] ?? $d['pool'] ?? 0),
            'pair' => (float) ($p['pair'] ?? $d['pair'] ?? 0),
            'cap' => (float) ($p['cap'] ?? $d['cap'] ?? 1),
            'directs' => (int) ($p['directs'] ?? $d['directs'] ?? 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, string> ช่อง => ข้อความผิดพลาด (ไทย)
     */
    public static function errors(array $p): array
    {
        $e = [];
        $price = (float) $p['price'];
        if (! ($price > 0) || $price > 1000 || ! is_finite($price)) {
            $e['price'] = 'ราคาต้องมากกว่า 0 (USDT ต่อ 1 IDTC)';
        }
        if (! ($p['min_usdt'] >= 1 && $p['min_usdt'] <= 1_000_000)) {
            $e['min_usdt'] = 'ขั้นต่ำต่อครั้งต้องอยู่ระหว่าง 1 ถึง 1,000,000 USDT';
        }
        // กองรางวัลเกินครึ่งของเงินที่เข้ามา = เงินส่วนใหญ่ของผู้ซื้อกลายเป็นค่าชวน — ไม่ให้ตั้ง
        if (! ($p['pool'] >= 0 && $p['pool'] <= 0.5)) {
            $e['pool'] = 'ส่วนเข้ากองรางวัลต้องอยู่ระหว่าง 0% ถึง 50% ของ USDT ที่ฝาก';
        }
        if (! ($p['pair'] > 0 && $p['pair'] <= 0.5)) {
            $e['pair'] = 'อัตราจับคู่ต้องมากกว่า 0% และไม่เกิน 50% ของยอดขาอ่อน';
        }
        if (! ($p['cap'] >= 0.01 && $p['cap'] <= 1)) {
            $e['cap'] = 'เพดานต่อคนต้องอยู่ระหว่าง 1% ถึง 100% ของกองสัปดาห์';
        }
        if ($p['directs'] < 0 || $p['directs'] > 10) {
            $e['directs'] = 'เพื่อนตรงต่อขาต้องอยู่ระหว่าง 0 ถึง 10 คน';
        }

        return $e;
    }

    /** @return list<array{id: int|null, from: int, plan: array<string, mixed>, note: string|null, by: string|null, at: int|null}> */
    public function versions(): array
    {
        if ($this->versions !== null) {
            return $this->versions;
        }
        $rows = [];
        try {
            $rows = BinaryPlanVersion::query()->orderBy('from_week')->orderBy('id')->get()->all();
        } catch (Throwable) {
            // ยังไม่ได้ migrate — ใช้ค่าจาก config
        }
        $list = [];
        if (! $rows || $rows[0]->from_week > 0) {
            $list[] = ['id' => null, 'from' => 0, 'plan' => self::defaults(), 'note' => 'ค่าเริ่มต้น (config/idtc.php)', 'by' => null, 'at' => null];
        }
        foreach ($rows as $row) {
            $list[] = [
                'id' => $row->id, 'from' => $row->from_week, 'plan' => self::normalize((array) $row->plan),
                'note' => $row->note, 'by' => $row->created_by, 'at' => $row->created_at?->getTimestamp(),
            ];
        }

        return $this->versions = $list;
    }

    public function forget(): void
    {
        $this->versions = null;
    }

    /** @return array<string, mixed> อัตราของสัปดาห์ k */
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

    /** @return array<string, mixed> */
    public function current(?CarbonInterface $now = null): array
    {
        return $this->at(GridPlan::weekAt($now ?? CarbonImmutable::now()));
    }

    /** เวอร์ชันที่บันทึกแล้วแต่ยังไม่ถึงสัปดาห์ที่มีผล */
    public function pending(?CarbonInterface $now = null): ?array
    {
        $week = GridPlan::weekAt($now ?? CarbonImmutable::now());
        $found = null;
        foreach ($this->versions() as $v) {
            if ($v['id'] !== null && $v['from'] > max(0, $week)) {
                $found = $v;
            }
        }

        return $found;
    }
}

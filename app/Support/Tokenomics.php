<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * ชื่อและคำอธิบายของแผนเหรียญ (การจัดสรร + ก้อนล็อก) ในภาษาของหน้าที่เปิดอยู่
 *
 * ตัวเลขทุกตัวมาจาก config/idtc.php เหมือนเดิม — คลาสนี้แปลเฉพาะข้อความ (lang/{th,en}/plan.php)
 * ถ้าไม่มีคำแปลของ key ไหน ใช้ข้อความใน config ตามเดิม
 */
class Tokenomics
{
    /** @return list<array<string, mixed>> */
    public static function allocation(): array
    {
        $price = (string) config('idtc.sale.price_usd');

        return array_map(function (array $item) use ($price) {
            $key = $item['key'] ?? null;
            if (is_string($key) && Lang::has("plan.alloc.$key.name")) {
                $item['name'] = __("plan.alloc.$key.name");
                $item['note'] = __("plan.alloc.$key.note", ['price' => $price]);
            }

            return $item;
        }, array_values((array) config('idtc.allocation', [])));
    }

    /** ชื่อก้อนล็อกจากระยะล็อก (เดือน) — สูตรเดียวกับ trancheName() ในหน้าความโปร่งใส (JS) */
    public static function trancheName(int $cliffMonths): string
    {
        return $cliffMonths > 0 && $cliffMonths % 12 === 0
            ? __('plan.tranche_years', ['n' => intdiv($cliffMonths, 12)])
            : __('plan.tranche_months', ['n' => $cliffMonths]);
    }

    /** @return list<array{name: string, amount: string, cliff: int, release: int}> */
    public static function tranches(): array
    {
        return array_map(fn (array $t) => [
            'name' => static::trancheName((int) $t['cliff']),
            'amount' => (string) $t['amount'],
            'cliff' => (int) $t['cliff'],
            'release' => (int) $t['release'],
        ], array_values((array) config('idtc.vesting.tranches', [])));
    }
}

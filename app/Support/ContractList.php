<?php

namespace App\Support;

/**
 * รายชื่อสัญญาทุกตัวที่เกี่ยวกับ IDTC สำหรับแสดงผล (หน้า presale, ความโปร่งใส, ไวท์เปเปอร์) — ข้อความตามภาษาของหน้า
 *
 * ที่อยู่มาจาก Idtc::contracts() เสมอ — รวมค่าที่แอดมินแก้ทับจากหลังบ้าน
 */
class ContractList
{
    /**
     * @param  list<string>|null  $only  เลือกเฉพาะบางคีย์ (ตามลำดับที่ส่งมา)
     * @return list<array{key: string, name: string, kind: string, role: string, address: ?string, url: ?string, deployed: bool}>
     */
    public static function rows(?array $only = null): array
    {
        $contracts = Idtc::contracts();
        $meta = static::meta();
        $keys = $only ?? array_keys($meta);

        $rows = [];
        foreach ($keys as $key) {
            if (! isset($meta[$key])) {
                continue;
            }
            [$name, $kind, $role, $optional] = $meta[$key] + [3 => false];
            $address = $contracts[$key] ?? null;
            // คู่พูลแสดงเฉพาะเมื่อสร้างแล้ว — ก่อนหน้านั้นไม่มีอะไรให้ตรวจ
            if ($optional && $address === null && $only === null) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'name' => $name,
                'kind' => $kind,
                'role' => $role,
                'address' => $address,
                'url' => Idtc::explorerAddress($address),
                'deployed' => $address !== null,
            ];
        }

        return $rows;
    }

    /** ตารางสัญญาแบบ Markdown (GFM) สำหรับแทน {{contracts_table}} ในไวท์เปเปอร์ */
    public static function markdownTable(): string
    {
        $lines = ['| '.__('plan.table.contract').' | '.__('plan.table.role').' | '.__('plan.table.address').' |', '|---|---|---|'];
        foreach (static::rows() as $row) {
            $cell = $row['address'] ? '['.$row['address'].']('.$row['url'].')' : __('site.pending');
            $lines[] = '| **'.$row['name'].'** | `'.$row['kind'].'` · '.$row['role'].' | '.$cell.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * ชื่อและหน้าที่ของสัญญาในภาษาของหน้าที่เปิดอยู่ (lang/{th,en}/plan.php → contracts.*)
     * ตัวเลขในคำอธิบายคำนวณจาก config/idtc.php ทุกครั้ง
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3?: bool}>
     */
    protected static function meta(): array
    {
        $tranches = (array) config('idtc.vesting.tranches', []);
        $locked = array_sum(array_map(fn ($t) => (float) $t['amount'], $tranches));
        $sale = (array) config('idtc.sale', []);
        $rate = ((float) ($sale['rate_num'] ?? 0) / max(1.0, (float) ($sale['rate_den'] ?? 1))) * 10 ** (int) ($sale['pay_decimals'] ?? 6) / 10 ** (int) config('idtc.token.decimals', 18);
        $text = fn (string $key, string $field, array $replace = []) => __("plan.contracts.$key.$field", $replace);
        $row = fn (string $key, string $kind, array $replace = [], bool $optional = false) => [$text($key, 'name'), $kind, $text($key, 'role', $replace), $optional];

        return [
            'token' => $row('token', 'FactoryERC20V2'),
            'vesting' => $row('vesting', 'TrancheVesting', ['amount' => SiteLocale::big($locked), 'count' => count($tranches)]),
            'presale' => $row('presale', 'FixedPriceSale', ['rate' => number_format($rate, 2)]),
            'usdt' => $row('usdt', 'USDT_TPIX'),
            'wtpix' => $row('wtpix', 'WTPIX'),
            'dex_factory' => $row('dex_factory', 'TPIXDEXFactory'),
            'dex_router' => $row('dex_router', 'TPIXDEXRouter02'),
            'pair_usdt' => $row('pair_usdt', 'TPIX DEX Pair', [], true),
            'pair_tpix' => $row('pair_tpix', 'TPIX DEX Pair', [], true),
        ];
    }
}

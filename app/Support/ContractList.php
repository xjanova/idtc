<?php

namespace App\Support;

/**
 * รายชื่อสัญญาทุกตัวที่เกี่ยวกับ IDTC สำหรับแสดงผล (หน้า presale, ความโปร่งใส, ไวท์เปเปอร์)
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
        $lines = ['| สัญญา | หน้าที่ | ที่อยู่บนเชน TPIX |', '|---|---|---|'];
        foreach (static::rows() as $row) {
            $cell = $row['address'] ? '['.$row['address'].']('.$row['url'].')' : 'รอ deploy';
            $lines[] = '| **'.$row['name'].'** | `'.$row['kind'].'` · '.$row['role'].' | '.$cell.' |';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3?: bool}> */
    protected static function meta(): array
    {
        $locked = array_sum(array_map(fn ($t) => (float) $t['amount'], (array) config('idtc.vesting.tranches', [])));
        $sale = (array) config('idtc.sale', []);
        $rate = ((float) ($sale['rate_num'] ?? 0) / max(1.0, (float) ($sale['rate_den'] ?? 1))) * 10 ** (int) ($sale['pay_decimals'] ?? 6) / 10 ** (int) config('idtc.token.decimals', 18);

        return [
            'token' => ['เหรียญ IDTC', 'FactoryERC20V2', 'ERC-20 · 18 ทศนิยม · ไม่มี mint, pause หรือ blacklist'],
            'vesting' => ['สัญญาล็อก 70%', 'TrancheVesting', 'ล็อก '.number_format($locked / 1e6).' ล้าน IDTC '.count((array) config('idtc.vesting.tranches', [])).' ก้อน · ไม่มีเจ้าของ'],
            'presale' => ['Presale', 'FixedPriceSale', 'ขายราคาคงที่ 1 USDT = '.number_format($rate, 2).' IDTC · USDT เข้ากระเป๋าโครงการทันที'],
            'usdt' => ['USDT บนเชน TPIX', 'USDT_TPIX', 'USDT 6 ทศนิยม · หนุน 1:1 กับ USDT บน BNB Chain'],
            'wtpix' => ['Wrapped TPIX', 'WTPIX', 'TPIX ในรูป ERC-20 สำหรับพูลบน TPIX DEX'],
            'dex_factory' => ['TPIX DEX Factory', 'TPIXDEXFactory', 'สร้างพูลแลกเปลี่ยนแบบ Uniswap V2'],
            'dex_router' => ['TPIX DEX Router', 'TPIXDEXRouter02', 'แลกเปลี่ยนและเพิ่มสภาพคล่อง'],
            'pair_usdt' => ['พูล IDTC/USDT', 'TPIX DEX Pair', 'สภาพคล่องคู่ IDTC/USDT', true],
            'pair_tpix' => ['พูล IDTC/TPIX', 'TPIX DEX Pair', 'สภาพคล่องคู่ IDTC/WTPIX', true],
        ];
    }
}

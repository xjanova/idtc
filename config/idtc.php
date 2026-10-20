<?php

/*
|--------------------------------------------------------------------------
| IDTC — ค่ากลางของเหรียญและเชน
|--------------------------------------------------------------------------
|
| ทุกหน้าที่แสดงตัวเลขเหรียญ อ่านจากไฟล์นี้ที่เดียว (ผ่าน App\Support\Idtc)
| ตัวเลขจริงบนเชนอ่านจากสัญญาเสมอ ไฟล์นี้เก็บแค่ "แผน" และที่อยู่สัญญา
|
| ที่อยู่สัญญา: ค่าในไฟล์นี้เป็นค่าเริ่มต้น แอดมินแก้ทับได้จากหลังบ้าน
| (ตาราง settings คีย์ contracts.*) — ค่าว่าง = ยังไม่ deploy หน้าเว็บแสดงสถานะ "รอ deploy"
|
*/

return [

    'chain' => [
        'id' => 4289,
        'hex' => '0x10c1',
        'name' => 'TPIX Chain',
        // rpc1 ไม่ใช่ rpc — rpc.tpix.online มีกฎ Cloudflare ตอบ 403 ให้ client ที่ไม่ใช่เบราว์เซอร์
        // รวมถึงกระเป๋าบางตัวบนมือถือ ทำให้ยอดไม่ขึ้น (TPIX-Coin deployed-contracts.json _rpcNote)
        'rpc' => env('IDTC_RPC_URL', 'https://rpc1.tpix.online'),
        'rpc_public' => 'https://rpc1.tpix.online',
        'explorer' => 'https://explorer.tpix.online',
        'native' => ['name' => 'TPIX', 'symbol' => 'TPIX', 'decimals' => 18],
        'gas_price' => 0,
    ],

    'token' => [
        'symbol' => 'IDTC',
        'name' => 'Invest Energy-Development Coin',
        'decimals' => 18,
        'total_supply' => '1100000000000',
        'logo' => '/images/brand/idtc-256.png',
    ],

    'contracts' => [
        'token' => env('IDTC_TOKEN_ADDRESS'),
        'vesting' => env('IDTC_VESTING_ADDRESS'),
        'presale' => env('IDTC_PRESALE_ADDRESS'),
        'usdt' => env('IDTC_USDT_ADDRESS', '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1'),
        'wtpix' => env('IDTC_WTPIX_ADDRESS', '0x0A3dB168aF6b85e61D81CA78C608BDadAC832DFF'),
        'dex_factory' => env('IDTC_DEX_FACTORY', '0x94b1254acE355559611890D4EB2d07332eD1fB8A'),
        'dex_router' => env('IDTC_DEX_ROUTER', '0x8aF94D36Ea0C14C40CDaff5bddE017269A9ccc04'),
        'pair_usdt' => env('IDTC_PAIR_USDT'),
        'pair_tpix' => env('IDTC_PAIR_TPIX'),
    ],

    // presale: ราคาฝังในสัญญา FixedPriceSale — ค่านี้ใช้แสดงผลก่อนสัญญาขึ้นเชน และใช้ตรวจว่าสัญญาตรงแผน
    'sale' => [
        'price_usd' => '0.0000015',
        'price_thb_approx' => '0.00005',
        'pay_symbol' => 'USDT',
        'pay_decimals' => 6,
        // tokensOut = payAmount(หน่วย 1e-6 USDT) × num ÷ den → 1 USDT = 666,666.67 IDTC
        'rate_num' => '2000000000000000000',
        'rate_den' => '3',
        'cap' => '100000000000',
        'min_purchase_usdt' => '1',
    ],

    // ล็อก 70% แบบ A: ครบล็อกแล้วทยอยปลด 12 งวด (TrancheVesting)
    'vesting' => [
        'period_seconds' => 2629800, // เดือนเฉลี่ย = 365.25 วัน / 12
        'tranches' => [
            ['name' => 'ล็อก 1 ปี', 'amount' => '220000000000', 'cliff' => 12, 'release' => 12],
            ['name' => 'ล็อก 2 ปี', 'amount' => '220000000000', 'cliff' => 24, 'release' => 12],
            ['name' => 'ล็อก 3 ปี', 'amount' => '165000000000', 'cliff' => 36, 'release' => 12],
            ['name' => 'ล็อก 4 ปี', 'amount' => '165000000000', 'cliff' => 48, 'release' => 12],
        ],
    ],

    // การจัดสรร 1.1T (ล็อก 70% + หมุนเวียน 30%) — เจ้าของเลือกแบบ "สมดุล" 2026-10-11
    // name/note คือข้อความภาษาไทย (หลังบ้านใช้) · หน้าเว็บแปลตาม key จาก lang/{th,en}/plan.php
    'allocation' => [
        ['key' => 'lock_1y', 'group' => 'locked', 'name' => 'ล็อก 1 ปี', 'note' => 'ปลดเดือนที่ 13–24', 'amount' => '220000000000', 'color' => '#1e40af'],
        ['key' => 'lock_2y', 'group' => 'locked', 'name' => 'ล็อก 2 ปี', 'note' => 'ปลดเดือนที่ 25–36', 'amount' => '220000000000', 'color' => '#2563eb'],
        ['key' => 'lock_3y', 'group' => 'locked', 'name' => 'ล็อก 3 ปี', 'note' => 'ปลดเดือนที่ 37–48', 'amount' => '165000000000', 'color' => '#3b82f6'],
        ['key' => 'lock_4y', 'group' => 'locked', 'name' => 'ล็อก 4 ปี', 'note' => 'ปลดเดือนที่ 49–60', 'amount' => '165000000000', 'color' => '#60a5fa'],
        ['key' => 'presale', 'group' => 'liquid', 'name' => 'Presale', 'note' => 'ขายที่ $0.0000015 ด้วย USDT', 'amount' => '100000000000', 'color' => '#a3e635'],
        ['key' => 'operations', 'group' => 'liquid', 'name' => 'ดำเนินงาน การตลาด และ listing', 'note' => 'กระเป๋าโครงการ', 'amount' => '100000000000', 'color' => '#10b981'],
        ['key' => 'reserve', 'group' => 'liquid', 'name' => 'สำรองสภาพคล่องและเติมพูล', 'note' => 'เติมความลึกพูลเท่านั้น ไม่ใช้พยุงราคา', 'amount' => '80000000000', 'color' => '#2dd4bf'],
        ['key' => 'liquidity', 'group' => 'liquid', 'name' => 'สภาพคล่องเริ่มต้น', 'note' => 'พูล IDTC/USDT และ IDTC/TPIX', 'amount' => '50000000000', 'color' => '#e6f23a'],
    ],

    // กระเป๋าที่เข้าหลังบ้านได้ (เซ็นข้อความด้วยกระเป๋า) — คั่นด้วยจุลภาค · ตัวแรกคือเจ้าของ
    'admin_wallets' => array_values(array_filter(array_map('trim', explode(',', (string) env('IDTC_ADMIN_WALLETS', ''))))),

];

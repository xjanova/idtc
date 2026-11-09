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
        // PoolFeeder — เจ้าของถาวรของสัญญา presale และกระเป๋ารับเงินขาย: เอา USDT ไปเติมพูล IDTC/USDT แล้วล็อก LP
        'feeder' => env('IDTC_FEEDER_ADDRESS'),
        'usdt' => env('IDTC_USDT_ADDRESS', '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1'),
        'wtpix' => env('IDTC_WTPIX_ADDRESS', '0x0A3dB168aF6b85e61D81CA78C608BDadAC832DFF'),
        'dex_factory' => env('IDTC_DEX_FACTORY', '0x94b1254acE355559611890D4EB2d07332eD1fB8A'),
        'dex_router' => env('IDTC_DEX_ROUTER', '0x8aF94D36Ea0C14C40CDaff5bddE017269A9ccc04'),
        'pair_usdt' => env('IDTC_PAIR_USDT'),
        'pair_tpix' => env('IDTC_PAIR_TPIX'),
        // GridRewards — รอบจ่ายรางวัล IDTC Grid (Merkle) · TPIX-Coin contracts/src/launch/GridRewards.sol
        'rewards' => env('IDTC_GRID_REWARDS_ADDRESS'),
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
        'max_per_wallet' => '0', // หน่วยเต็ม IDTC · 0 = ไม่จำกัด
        // กำหนดการตามแผน (เจ้าของตัดสิน 2026-11-06) — เมื่อสัญญาขึ้นเชน หน้าเว็บนับถอยหลังจากเวลาในสัญญา
        'start' => '2026-12-01T10:00:00+07:00',
        'end' => '2027-03-01T10:00:00+07:00',
    ],

    // เปิดขายผ่าน TPIX Launchpad (LaunchpadFactory บนเชน TPIX) — ค่าเดียวกับ TPIX-Coin contracts/scripts/launch-plans/idtc.json
    'launchpad' => [
        // จ่าย USDT บน BNB Chain ครั้งเดียว ระบบส่ง IDTC เข้ากระเป๋าบนเชน TPIX ให้
        'url' => 'https://tpix.online/launchpad/IDTC',
        'pool_share_bps' => 10000,   // เงินขายเข้าพูล IDTC/USDT 100%
        'band_bps' => 500,           // ±5% (เจ้าของเลือก 2026-11-06) เติมพูลได้เมื่อราคาในพูลห่างจากราคาขายไม่เกินค่านี้ · 0 ในสัญญา = ค่าปริยายของโรงงาน ±3%
        'feeder_reserve' => '100000000000', // IDTC ที่ล็อกไว้จับคู่กับเงินขายเข้าพูล
        'seed_usdt' => '10',         // พูลเปิดพร้อมสัญญาขาย ด้วย USDT จากทีมงาน
        'lp_unlock' => '2028-02-29T10:00:00+07:00',
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
    // ส่วนหมุนเวียนแบ่งตามเชนจริงของ launchpad (เจ้าของเลือก 2026-11-06): 100B จับคู่พูลอยู่ในสัญญา PoolFeeder
    // name/note คือข้อความภาษาไทย (หลังบ้านใช้) · หน้าเว็บแปลตาม key จาก lang/{th,en}/plan.php
    'allocation' => [
        ['key' => 'lock_1y', 'group' => 'locked', 'name' => 'ล็อก 1 ปี', 'note' => 'ปลดเดือนที่ 13–24', 'amount' => '220000000000', 'color' => '#1e40af'],
        ['key' => 'lock_2y', 'group' => 'locked', 'name' => 'ล็อก 2 ปี', 'note' => 'ปลดเดือนที่ 25–36', 'amount' => '220000000000', 'color' => '#2563eb'],
        ['key' => 'lock_3y', 'group' => 'locked', 'name' => 'ล็อก 3 ปี', 'note' => 'ปลดเดือนที่ 37–48', 'amount' => '165000000000', 'color' => '#3b82f6'],
        ['key' => 'lock_4y', 'group' => 'locked', 'name' => 'ล็อก 4 ปี', 'note' => 'ปลดเดือนที่ 49–60', 'amount' => '165000000000', 'color' => '#60a5fa'],
        ['key' => 'presale', 'group' => 'liquid', 'name' => 'Presale', 'note' => 'ขายที่ $0.0000015 ด้วย USDT', 'amount' => '100000000000', 'color' => '#a3e635'],
        ['key' => 'operations', 'group' => 'liquid', 'name' => 'ดำเนินงาน การตลาด และ listing', 'note' => 'กระเป๋าโครงการ', 'amount' => '100000000000', 'color' => '#10b981'],
        ['key' => 'pool', 'group' => 'liquid', 'name' => 'จับคู่เงินขายเข้าพูล', 'note' => 'ล็อกในสัญญา PoolFeeder ถึง 29 ก.พ. 2571', 'amount' => '100000000000', 'color' => '#2dd4bf'],
        ['key' => 'reserve', 'group' => 'liquid', 'name' => 'สำรอง', 'note' => 'กระเป๋าโครงการ', 'amount' => '30000000000', 'color' => '#e6f23a'],
    ],

    // IDTC Grid — รางวัลแนะนำเพื่อนซื้อ presale (สามวง งบคงที่) · ต้นแบบและเหตุผล: branch design/network-grid (design/network/README.md)
    // เปิด/ปิดจริงที่หลังบ้าน (settings grid.enabled) — ค่าเริ่มต้นปิด จนกว่าที่ปรึกษากฎหมายตรวจแผน
    // แผนด้านล่างคือเวอร์ชันแรก · แอดมินแก้ได้จากหลังบ้าน (ตาราง grid_plan_versions) แผนใหม่มีผลสัปดาห์ขายถัดไปเสมอ
    'grid' => [
        'enabled' => (bool) env('IDTC_GRID_ENABLED', false),
        'plan' => [
            'levels' => [0.05, 0.03, 0.01],  // วง 1..3 = สัดส่วนของ IDTC ที่เพื่อนซื้อ
            'buyer' => 0.01,                 // ผู้ซื้อที่มาจากลิงก์ได้เพิ่มเท่านี้
            'boost' => [                     // วง 1 ได้เพิ่มช่วงเปิดตัว ตามสัปดาห์ขาย [from, to)
                ['from' => 0, 'to' => 3, 'add' => 0.02],
                ['from' => 3, 'to' => 6, 'add' => 0.01],
            ],
            'sprint' => 0.02,                // กองรายสัปดาห์ = สัดส่วนของ IDTC ที่ขายได้สัปดาห์นั้น
            'sprint_cap' => 0.2,             // คนเดียวรับได้ไม่เกินสัดส่วนนี้ของกองสัปดาห์
            'hold_days' => 14,               // ผู้ซื้อต้องถือครบก่อนการซื้อนั้นนับรางวัล
            'instant' => 0.5,                // ส่วนที่รับได้ทันทีเมื่อถึงรอบจ่าย ที่เหลือปลดหลังปิดการขาย
            'rank' => [
                'spark_own' => 10,           // ซื้อเองอย่างน้อย (USDT) = สปาร์ก ปลดวง 1 · เพื่อนตรงที่นับต้องซื้อถึงเท่านี้ด้วย
                'cell_d' => 2,               // เพื่อนตรงที่ซื้อแล้ว = เซลล์ ปลดวง 2
                'grid_d' => 4, 'grid_t' => 1000,    // เพื่อนตรง + ยอดทีม (USDT) = กริด ปลดวง 3 + Sprint ×1
                'core_d' => 10, 'core_t' => 10000,  // = คอร์ Sprint ×2
            ],
            'budget' => '15000000000',       // IDTC ที่กันไว้จากก้อน "ดำเนินงาน การตลาด และ listing" (100B)
        ],
    ],

    // กระเป๋าที่เข้าหลังบ้านได้ (เซ็นข้อความด้วยกระเป๋า) — คั่นด้วยจุลภาค · ตัวแรกคือเจ้าของ
    'admin_wallets' => array_values(array_filter(array_map('trim', explode(',', (string) env('IDTC_ADMIN_WALLETS', ''))))),

];

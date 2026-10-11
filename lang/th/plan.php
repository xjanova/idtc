<?php

// ชื่อในแผนเหรียญและสัญญา — ตัวเลขมาจาก config/idtc.php (:price, :amount, :count, :rate)

return [
    'tranche_years' => 'ล็อก :n ปี',
    'tranche_months' => 'ล็อก :n เดือน',

    'group' => [
        'locked' => 'ล็อก 70% · ปลดตามตาราง',
        'liquid' => 'หมุนเวียน 30% · ใช้ได้ตั้งแต่วันแรก',
    ],

    'alloc' => [
        'lock_1y' => ['name' => 'ล็อก 1 ปี', 'note' => 'ปลดเดือนที่ 13–24'],
        'lock_2y' => ['name' => 'ล็อก 2 ปี', 'note' => 'ปลดเดือนที่ 25–36'],
        'lock_3y' => ['name' => 'ล็อก 3 ปี', 'note' => 'ปลดเดือนที่ 37–48'],
        'lock_4y' => ['name' => 'ล็อก 4 ปี', 'note' => 'ปลดเดือนที่ 49–60'],
        'presale' => ['name' => 'Presale', 'note' => 'ขายที่ $:price ด้วย USDT'],
        'operations' => ['name' => 'ดำเนินงาน การตลาด และ listing', 'note' => 'กระเป๋าโครงการ'],
        'reserve' => ['name' => 'สำรองดูแลราคาและเติมพูล', 'note' => 'ใช้ตามกติกาที่ประกาศ'],
        'liquidity' => ['name' => 'สภาพคล่องเริ่มต้น', 'note' => 'พูล IDTC/USDT และ IDTC/TPIX'],
    ],

    'table' => [
        'contract' => 'สัญญา',
        'role' => 'หน้าที่',
        'address' => 'ที่อยู่บนเชน TPIX',
    ],

    'contracts' => [
        'token' => ['name' => 'เหรียญ IDTC', 'role' => 'ERC-20 · 18 ทศนิยม · ไม่มี mint, pause หรือ blacklist'],
        'vesting' => ['name' => 'สัญญาล็อก 70%', 'role' => 'ล็อก :amount IDTC :count ก้อน · ไม่มีเจ้าของ'],
        'presale' => ['name' => 'Presale', 'role' => 'ขายราคาคงที่ 1 USDT = :rate IDTC · USDT เข้ากระเป๋าโครงการทันที'],
        'usdt' => ['name' => 'USDT บนเชน TPIX', 'role' => 'USDT 6 ทศนิยม · หนุน 1:1 กับ USDT บน BNB Chain'],
        'wtpix' => ['name' => 'Wrapped TPIX', 'role' => 'TPIX ในรูป ERC-20 สำหรับพูลบน TPIX DEX'],
        'dex_factory' => ['name' => 'TPIX DEX Factory', 'role' => 'สร้างพูลแลกเปลี่ยนแบบ Uniswap V2'],
        'dex_router' => ['name' => 'TPIX DEX Router', 'role' => 'แลกเปลี่ยนและเพิ่มสภาพคล่อง'],
        'pair_usdt' => ['name' => 'พูล IDTC/USDT', 'role' => 'สภาพคล่องคู่ IDTC/USDT'],
        'pair_tpix' => ['name' => 'พูล IDTC/TPIX', 'role' => 'สภาพคล่องคู่ IDTC/WTPIX'],
    ],
];

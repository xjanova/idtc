<?php

// ข้อความกลางของหน้าสาธารณะ (layout, เมนู, footer) — คู่ภาษาอังกฤษอยู่ที่ lang/en/site.php (key ต้องตรงกัน)

return [
    'name' => 'IDTC Energy Capital',
    'title' => 'IDTC Energy Capital — เหรียญเพื่อการพัฒนาพลังงานบนเชน TPIX',
    'description' => 'IDTC เหรียญเพื่อการพัฒนาพลังงานบนเชน TPIX ล็อก 70% ในสัญญาที่ไม่มีเจ้าของ ราคา presale ฝังในสัญญา $:price จ่ายด้วย USDT ค่าแก๊ส 0',

    'og_image' => 'images/brand/og.jpg',

    'nav' => [
        'home' => 'ภาพรวม',
        'presale' => 'Presale',
        'transparency' => 'ความโปร่งใส',
        'whitepaper' => 'ไวท์เปเปอร์',
        'add-network' => 'เพิ่มเชน',
        'main' => 'เมนูหลัก',
        'mobile' => 'เมนูมือถือ',
        'menu' => 'เมนู',
    ],

    'add_network' => 'เพิ่มเชน + เหรียญ',
    'add_network_full' => 'เพิ่มเชน + เหรียญ IDTC',
    'connect' => 'เชื่อมกระเป๋า',

    'lang' => [
        'label' => 'เลือกภาษา',
        'th' => 'ภาษาไทย',
        'en' => 'English',
    ],

    // คำแนะนำให้สลับเป็นภาษาอังกฤษ — แสดงเฉพาะหน้าไทย ข้อความจึงดึงจาก lang/en/site.php (hint.*)
    'hint' => [
        'text' => 'This page is in Thai.',
        'switch' => 'Switch to English?',
        'dismiss' => 'Dismiss',
    ],

    'footer' => [
        'tagline' => 'เหรียญเพื่อการพัฒนาพลังงานบนเชน TPIX ล็อก 70% ตรวจสอบได้บนเชน',
        'token' => 'เหรียญ',
        'docs' => 'เอกสาร',
        'chain' => 'เชน TPIX',
        'faq' => 'คำถามที่พบบ่อย',
        'how_to_buy' => 'วิธีซื้อ',
        'disclaimer' => 'ไม่ใช่คำแนะนำการลงทุน · สินทรัพย์ดิจิทัลมีความเสี่ยง',
    ],

    'illus' => 'ภาพประกอบ',
    'illus_alt' => 'ภาพประกอบ: :what',
    'pending' => 'รอ deploy',
    'explorer_link' => 'ดูบน explorer ↗',
    'explorer_tx' => 'ดูรายการบน explorer ↗',
    'view_tx' => 'ดูธุรกรรม ↗',
    'retry' => 'ลองใหม่',
    'cancel' => 'ยกเลิก',

    // จำนวนเหรียญก้อนใหญ่: ไทยนับเป็นล้าน (10^6)
    'big' => [
        'exp' => '6',
        'unit' => 'ล้าน',
    ],
];

@extends('layouts.site')

@php
    use App\Support\Idtc;
    $total = (float) config('idtc.token.total_supply');
    $slides = [
        [
            'img' => 'hero-1', 'alt' => 'ภาพประกอบ: ฟาร์มโซลาร์และสถานีไฟฟ้ายามค่ำ',
            'eyebrow' => 'Presale บนเชน TPIX · ค่าแก๊ส 0', 'eyebrowClass' => '',
            'title' => 'ลงทุนในการพัฒนาพลังงาน', 'grad' => 'ด้วยเหรียญที่ตรวจสอบได้ทุกบรรทัด',
            'lead' => 'IDTC คือเหรียญของโครงการ IDTC Energy Capital บนเชน TPIX <b>เหรียญ 70% ล็อกในสัญญาที่ไม่มีใครถอนก่อนกำหนดได้</b> ราคา presale ฝังในสัญญา และทุกการซื้อขายเปิดดูได้บน explorer',
            'cta' => ['presale', 'ซื้อ IDTC ใน Presale'], 'cta2' => ['whitepaper', 'อ่านไวท์เปเปอร์'],
        ],
        [
            'img' => 'hero-2', 'alt' => 'ภาพประกอบ: ฟาร์มกังหันลมกลางทะเลยามรุ่งสาง',
            'eyebrow' => 'ล็อก 770,000 ล้าน IDTC · 5 ปี', 'eyebrowClass' => '',
            'title' => 'ล็อก 70% ในสัญญาที่ไม่มีเจ้าของ', 'grad' => 'ไม่มีวันปลดก้อนใหญ่',
            'lead' => 'แบ่งเป็น 4 ก้อน ล็อก 1–4 ปี แต่ละก้อน<b>ทยอยปลดรายเดือนตลอดปีถัดไป</b> ปลดมากสุดเดือนละ 1.67% ของ supply และใครก็ตรวจยอดคงเหลือได้ทุกวินาที',
            'cta' => ['transparency', 'ดูสัญญาล็อกสด'], 'cta2' => ['home', 'ตารางปลดล็อก', '#unlock'],
        ],
        [
            'img' => 'hero-3', 'alt' => 'ภาพประกอบ: โรงไฟฟ้าโซลาร์กลางทะเลทรายยามเช้า',
            'eyebrow' => 'เอเชีย · ตะวันออกกลาง', 'eyebrowClass' => 'gold',
            'title' => 'ตลาดพลังงานโลก', 'grad' => '3.3 ล้านล้านดอลลาร์ต่อปี',
            'lead' => 'ปี 2025 โลกลงทุนด้านพลังงานราว 3.3 ล้านล้านดอลลาร์ (IEA) <b>แต่คนส่วนใหญ่เข้าไม่ถึง</b> IDTC ตั้งเป้าเปิดทางให้นักลงทุนทุกขนาด เริ่มจากโซลาร์ ลม และระบบกักเก็บพลังงาน',
            'cta' => ['home', 'เป้าหมายโครงการ', '#about'], 'cta2' => ['whitepaper', 'อ่านไวท์เปเปอร์'],
        ],
        [
            'img' => 'hero-4', 'alt' => 'ภาพประกอบ: เมืองยามค่ำที่ใช้พลังงานสะอาด',
            'eyebrow' => 'จ่าย USDT · ได้เหรียญทันที', 'eyebrowClass' => '',
            'title' => 'ซื้อด้วย USDT', 'grad' => 'เพิ่มเชนได้ในคลิกเดียว',
            'lead' => 'ราคาเดียวกันสำหรับทุกคนที่ <b>$0.0000015 ต่อ IDTC</b> มี USDT บน BNB Chain ก็ส่งผ่านสะพานมาเชน TPIX ได้ 1:1 และเพิ่มเชนกับเหรียญลงกระเป๋าได้ทั้งบนคอมและมือถือ',
            'cta' => ['presale', 'ไปที่ Presale'], 'cta2' => ['add-network', 'เพิ่มเชน + เหรียญ'],
        ],
    ];
    $link = fn (array $c) => route($c[0]).($c[2] ?? '');
@endphp

@push('preload')
    <link rel="preload" as="image" href="{{ asset('images/art/hero-1.webp') }}" media="(min-width: 761px)">
    <link rel="preload" as="image" href="{{ asset('images/art/hero-1-m.webp') }}" media="(max-width: 760px)">
@endpush

@section('content')
    {{-- ═════════ HERO SLIDER ═════════ --}}
    <section class="hero" x-data="heroSlider({{ count($slides) }})" @mouseenter="hold(true)" @mouseleave="hold(false)" @focusin="hold(true)" @focusout="hold(false)" @touchstart.passive="touchStart($event)" @touchend.passive="touchEnd($event)" :class="{ 'is-paused': paused }" aria-roledescription="carousel" aria-label="ภาพเด่น">
        <div class="slides" aria-hidden="true">
            @foreach ($slides as $i => $s)
                <div class="slide-bg {{ $i === 0 ? 'is-on' : '' }}" :class="{ 'is-on': index === {{ $i }} }"
                     style="background-image: image-set(url('{{ asset('images/art/'.$s['img'].'.webp') }}') 1x)"
                     data-mobile="{{ asset('images/art/'.$s['img'].'-m.webp') }}"></div>
            @endforeach
        </div>
        <canvas id="flow" aria-hidden="true"></canvas>

        <div class="hero-body">
            <div class="wrap">
                <div class="hero-copy">
                    @foreach ($slides as $i => $s)
                        <div class="slide-copy {{ $i === 0 ? 'is-on' : '' }}" :class="{ 'is-on': index === {{ $i }} }" role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} จาก {{ count($slides) }}" :aria-hidden="(index !== {{ $i }}).toString()">
                            <p class="eyebrow {{ $s['eyebrowClass'] }}"><span class="pulse"></span>{{ $s['eyebrow'] }}</p>
                            @if ($i === 0)
                                <h1>{{ $s['title'] }} <span class="grad">{{ $s['grad'] }}</span></h1>
                            @else
                                <h2 class="hero-h">{{ $s['title'] }} <span class="grad">{{ $s['grad'] }}</span></h2>
                            @endif
                            <p class="hero-lead">{!! $s['lead'] !!}</p>
                            <div class="cta-row">
                                <a class="btn btn-primary" href="{{ $link($s['cta']) }}" :tabindex="index === {{ $i }} ? 0 : -1">
                                    <svg viewBox="0 0 24 24"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>{{ $s['cta'][1] }}
                                </a>
                                <a class="btn btn-ghost" href="{{ $link($s['cta2']) }}" :tabindex="index === {{ $i }} ? 0 : -1">{{ $s['cta2'][1] }}</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="hero-dock">
            <div class="wrap">
                <div class="dock-card" x-data="presaleStats">
                    <div>
                        <div class="price-amt">${{ config('idtc.sale.price_usd') }}</div>
                        <div class="price-sub">ต่อ IDTC · 1 USDT = 666,666.67 IDTC</div>
                    </div>
                    <div>
                        <div class="meter-head">
                            <span x-text="live ? 'ขายแล้วใน presale' : 'Presale เปิดเร็ว ๆ นี้'">Presale เปิดเร็ว ๆ นี้</span>
                            <b class="num" x-text="live ? fmt(sold) + ' IDTC' : '0 IDTC'">0 IDTC</b>
                        </div>
                        <div class="bar"><i :style="`--w:${pct}%`"></i></div>
                        <div class="meter-foot">
                            <span x-text="pct.toFixed(2) + '% ของ 100,000 ล้าน IDTC'">0.00% ของ 100,000 ล้าน IDTC</span>
                            <span x-text="live ? 'ได้แล้ว $' + fmt(raised, 2) : 'รอเปิดสัญญา presale'">รอเปิดสัญญา presale</span>
                        </div>
                    </div>
                </div>
                <div class="slider-ctrl">
                    <button class="arrow" type="button" @click="prev()" aria-label="ภาพก่อนหน้า"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></button>
                    <div class="dots" role="tablist" aria-label="เลือกภาพ">
                        @foreach ($slides as $i => $s)
                            <button class="dot" type="button" role="tab" :class="{ 'is-on': index === {{ $i }}, 'is-done': index > {{ $i }} }" :aria-selected="(index === {{ $i }}).toString()" @click="go({{ $i }})" aria-label="ภาพที่ {{ $i + 1 }}" :style="`--dur:${duration}ms`" :key="'d{{ $i }}-' + cycle"><i></i></button>
                        @endforeach
                    </div>
                    <button class="arrow" type="button" @click="next()" aria-label="ภาพถัดไป"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>
                    <span class="slide-label" x-text="(index + 1) + ' / {{ count($slides) }}'">1 / {{ count($slides) }}</span>
                </div>
            </div>
        </div>
    </section>

    {{-- ═════════ STATS ═════════ --}}
    <section class="stats" aria-label="ตัวเลขสำคัญ">
        <div class="wrap">
            <div class="stat"><div class="stat-label">จำนวนเหรียญทั้งหมด</div><div class="stat-value"><span data-count="1100000000000" data-short>1.1T</span></div></div>
            <div class="stat"><div class="stat-label">ล็อกในสัญญา</div><div class="stat-value"><span data-count="770000000000" data-short>770B</span><small>70%</small></div></div>
            <div class="stat"><div class="stat-label">มูลค่ารวมที่ราคา presale</div><div class="stat-value">$<span data-count="1650000">1,650,000</span></div></div>
            <div class="stat"><div class="stat-label">ค่าแก๊สบนเชน TPIX</div><div class="stat-value">0<small>TPIX</small></div></div>
        </div>
    </section>

    {{-- ═════════ ABOUT ═════════ --}}
    <section class="section" id="about">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">IDTC Energy Capital</p>
                <h2 class="h2">เหรียญที่ตั้งใจทำเพื่อ <span class="grad">การพัฒนาพลังงาน</span></h2>
                <p class="lead">เป้าหมายของโครงการคือระดมทุนจากนักลงทุนทุกขนาดเพื่อพัฒนาโครงการพลังงานในเอเชียและตะวันออกกลาง ทุกโครงการที่จะใช้เงินต้องประกาศพร้อมเอกสารให้ตรวจสอบได้ก่อนเสมอ</p>
            </div>
            <div class="sectors">
                @foreach ([['solar', 'พลังงานแสงอาทิตย์', 'ฟาร์มโซลาร์และโซลาร์รูฟท็อป ซึ่งต้นทุนต่อหน่วยลดลงเร็วที่สุดในภาคพลังงาน', 'แผงโซลาร์ยามแดดสุดท้าย'], ['wind', 'พลังงานลม', 'กังหันลมในพื้นที่ที่มีลมสม่ำเสมอ เดินเครื่องได้ทั้งกลางวันและกลางคืน', 'กังหันลมบนสันเขายามค่ำ'], ['storage', 'กักเก็บพลังงานและโครงข่าย', 'แบตเตอรี่ระดับโครงข่ายที่เก็บไฟจากแดดและลมไว้ใช้ตอนที่ต้องการจริง', 'ระบบกักเก็บพลังงานแบตเตอรี่']] as [$img, $h, $p, $alt])
                    <article class="sector reveal">
                        <figure><img src="{{ asset('images/art/'.$img.'.webp') }}" alt="ภาพประกอบ: {{ $alt }}" loading="lazy" width="1000" height="563"><span class="tag-illus">ภาพประกอบ</span></figure>
                        <div class="sector-body"><h3>{{ $h }}</h3><p>{{ $p }}</p></div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═════════ TRUST ═════════ --}}
    <section class="section section-alt" id="trust">
        <div class="wrap guard">
            <div class="reveal">
                <p class="kicker">ความน่าเชื่อถือที่พิสูจน์ได้</p>
                <h2 class="h2">สิ่งที่สัญญาของ IDTC <span class="grad">ทำไม่ได้</span> แม้แต่ทีมงานเอง</h2>
                <p class="lead" style="margin-top:14px">คำสัญญาบนกระดาษเปลี่ยนได้ แต่โค้ดบนเชนเปลี่ยนไม่ได้ ทุกข้อด้านล่างตรวจสอบได้จากซอร์สโค้ดที่ verified บน explorer</p>
                <ul class="guard-list">
                    @foreach ([
                        ['สร้างเหรียญเพิ่มไม่ได้', 'จำนวน 1.1 ล้านล้านเหรียญคงที่ตลอดไป ไม่มีฟังก์ชัน mint'],
                        ['หยุดการโอนหรือแบล็กลิสต์กระเป๋าไม่ได้', 'ไม่มีปุ่ม pause ไม่มีรายชื่อดำ เหรียญของคุณเป็นของคุณ'],
                        ['ถอนเหรียญที่ล็อกก่อนกำหนดไม่ได้', 'สัญญาล็อกไม่มีเจ้าของ ปลดตามตารางเวลาเท่านั้น'],
                        ['ขึ้นราคา presale กลางรอบไม่ได้', 'ราคาฝังตอนสร้างสัญญา ไม่มีฟังก์ชันแก้ราคา'],
                        ['ยึดเงินค่าซื้อไว้ในสัญญาไม่ได้', 'USDT เข้ากระเป๋าโครงการทันที และคุณได้เหรียญในธุรกรรมเดียวกัน'],
                    ] as [$b, $s])
                        <li><span class="ic"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></span><div><b>{{ $b }}</b><span>{{ $s }}</span></div></li>
                    @endforeach
                </ul>
            </div>
            <figure class="vault reveal">
                <img src="{{ asset('images/art/lock.webp') }}" alt="ภาพประกอบ: ตู้นิรภัยแก้วแบ่ง 4 ช่วงเวลา" loading="lazy" width="900" height="900">
                <div class="vault-badge"><b class="num"><span data-count="770000000000" data-short>770B</span></b><span>IDTC ล็อกในสัญญา · ปลดครบใน 60 เดือน</span></div>
                <span class="tag-illus">ภาพประกอบ</span>
            </figure>
        </div>
    </section>

    {{-- ═════════ TOKENOMICS ═════════ --}}
    <section class="section" id="tokenomics">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">Tokenomics</p>
                <h2 class="h2">1.1 ล้านล้านเหรียญ <span class="grad">ล็อกไว้ 70%</span> หมุนเวียนจริง 30%</h2>
            </div>
            <div class="tokenomics" x-data="donut(@js(config('idtc.allocation')))">
                <div class="donut-wrap reveal">
                    <svg viewBox="0 0 200 200" x-ref="svg" aria-label="แผนภูมิการจัดสรรเหรียญ"></svg>
                    <div class="donut-center"><b x-text="centerValue">1.1T</b><span x-text="centerLabel">IDTC ทั้งหมด</span></div>
                </div>
                <div class="table-scroll reveal">
                    <table class="alloc">
                        <thead><tr><th>ส่วน</th><th>สัดส่วน</th><th>จำนวน</th></tr></thead>
                        <tbody>
                            @foreach (collect(config('idtc.allocation'))->groupBy('group') as $group => $items)
                                <tr class="group"><td colspan="3">{{ $group === 'locked' ? 'ล็อก 70% · ปลดตามตาราง' : 'หมุนเวียน 30% · ใช้ได้ตั้งแต่วันแรก' }}</td></tr>
                                @foreach ($items as $it)
                                    @php $idx = array_search($it, config('idtc.allocation'), true); @endphp
                                    <tr @mouseenter="highlight({{ $idx }})" @mouseleave="highlight(null)">
                                        <td><span class="sw" style="background:{{ $it['color'] }}"></span>{{ $it['name'] }}<small>{{ $it['note'] }}</small></td>
                                        <td>{{ number_format($it['amount'] / $total * 100, 2) }}%</td>
                                        <td>{{ number_format($it['amount'] / 1e9) }}B</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    {{-- ═════════ UNLOCK ═════════ --}}
    <section class="section section-alt" id="unlock">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">ตารางปลดล็อก</p>
                <h2 class="h2">ไม่มีวันไหนที่ปลดล็อก <span class="grad">ก้อนใหญ่</span></h2>
                <p class="lead">แต่ละก้อนล็อกเต็มจำนวนตามระยะเวลา แล้วทยอยปลดเท่า ๆ กัน 12 เดือน เหรียญหมุนเวียนจึงเพิ่มแบบค่อยเป็นค่อยไป ปลดมากสุดเดือนละ 1.67% ของ supply</p>
            </div>
            <div class="chart-card reveal" x-data="unlockChart(@js(config('idtc.vesting.tranches')))">
                <div class="chart-top">
                    <div class="legend"><span><i style="background:linear-gradient(90deg,#a3e635,#10b981)"></i>เหรียญหมุนเวียนสะสม</span><span><i style="background:rgba(59,130,246,.6)"></i>ยังล็อกอยู่</span></div>
                    <span class="pill-wait">เดือนที่ 0 = วันเปิดตลาด</span>
                </div>
                <div class="chart-scroll"><svg class="chart-svg" x-ref="svg" viewBox="0 0 900 330" aria-label="กราฟเหรียญหมุนเวียน 60 เดือน"></svg></div>
                <div class="notes3">
                    <div class="note"><b>330,000 ล้าน</b>หมุนเวียนตั้งแต่วันแรก (30%)</div>
                    <div class="note"><b>18,333 ล้าน / เดือน</b>ปลดสูงสุดต่อเดือน (เดือน 13–36)</div>
                    <div class="note"><b>เดือนที่ 60</b>ปลดครบ 1.1 ล้านล้านเหรียญ</div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═════════ HOW TO BUY ═════════ --}}
    <section class="section" id="buy">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">วิธีซื้อ</p>
                <h2 class="h2">ซื้อ IDTC ได้ 3 ทาง <span class="grad">ด้วย USDT</span></h2>
            </div>
            <figure class="routes-hero reveal">
                <img src="{{ asset('images/art/bridge.webp') }}" alt="ภาพประกอบ: สะพานแสงเชื่อมสองเครือข่าย" loading="lazy" width="1400" height="788">
                <div class="overlay"><span class="chain-pill bsc">USDT บน BNB Chain</span><span class="chain-pill tpix">IDTC บนเชน TPIX</span></div>
            </figure>
            <div class="routes">
                <article class="route route-best reveal"><span class="flag">ราคาคงที่ ไม่มี slippage</span><span class="step">1</span><h3>Presale ราคาคงที่</h3><p>จ่าย USDT บนเชน TPIX แล้วได้ IDTC ทันทีที่ $0.0000015 ไม่ต้องรอเคลม</p><span class="meta">เหมาะกับคนที่ซื้อครั้งแรก</span></article>
                <article class="route reveal"><span class="step">2</span><h3>แลกบน TPIX DEX</h3><p>คู่ IDTC/USDT และ IDTC/TPIX ซื้อขายได้ตลอด 24 ชั่วโมง ราคาเป็นไปตามสภาพคล่องในพูล</p><span class="meta">ค่าธรรมเนียมพูล 0.3% · ค่าแก๊ส 0</span></article>
                <article class="route reveal"><span class="step">3</span><h3>มี USDT บนเชนอื่น</h3><p>ส่ง USDT จาก BNB Chain ผ่านสะพานของ TPIX แล้วได้ USDT บนเชน TPIX 1:1 จากนั้นซื้อด้วยวิธีที่ 1 หรือ 2</p><span class="meta">รองรับ BNB Chain ก่อน เชนอื่นตามมา</span></article>
            </div>
            @include('partials.add-network')
        </div>
    </section>

    {{-- ═════════ ROADMAP ═════════ --}}
    <section class="section section-alt" id="roadmap">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">Roadmap</p><h2 class="h2">ทำเสร็จแล้วอะไรบ้าง <span class="grad">และกำลังทำอะไร</span></h2></div>
            <ol class="roadmap">
                <li class="phase reveal"><span class="st st-done">✓ เสร็จแล้ว</span><h3>วางรากฐาน</h3><ul><li>เปิดตัวโครงการและเว็บไซต์ (เม.ย. 2569)</li><li>เชน TPIX ของตัวเอง ค่าแก๊ส 0</li><li>TPIX DEX และ Token Factory</li></ul></li>
                <li class="phase phase-now reveal"><span class="st st-now">● กำลังทำ</span><h3>เปิดตัวบนเชน TPIX</h3><ul><li>สร้าง IDTC ผ่าน Token Factory</li><li>สัญญาล็อก 70% และ presale USDT</li><li>พูล IDTC/USDT และ IDTC/TPIX</li></ul></li>
                <li class="phase reveal"><span class="st st-next">ถัดไป</span><h3>ขยายการเข้าถึง</h3><ul><li>สะพาน USDT จาก BNB Chain แบบอัตโนมัติ</li><li>ยื่นลงทะเบียน CoinGecko และ CoinMarketCap</li><li>wIDTC บน BNB Chain และ PancakeSwap</li></ul></li>
            </ol>
        </div>
    </section>

    {{-- ═════════ WHITEPAPER ═════════ --}}
    <section class="section" id="whitepaper">
        <div class="wrap wp">
            <div class="wp-cover reveal">
                <img src="{{ asset('images/art/cover.webp') }}" alt="" loading="lazy" width="800" height="1200">
                <div class="t"><img src="{{ asset('images/brand/idtc-128.png') }}" alt="" width="46" height="46"><b>IDTC<br>Whitepaper</b><span>Version 2.0 · 2569</span></div>
            </div>
            <div class="reveal">
                <p class="kicker">ไวท์เปเปอร์ ฉบับที่ 2</p>
                <h2 class="h2">เขียนใหม่ทั้งฉบับ ทุกตัวเลข <span class="grad">ชี้กลับไปที่สัญญาได้</span></h2>
                <ol class="toc">
                    @foreach (['บทสรุปสำหรับผู้บริหาร', 'ปัญหาและโอกาสในภาคพลังงาน', 'ทำไมต้องเชน TPIX', 'Tokenomics และการจัดสรร', 'สัญญาล็อกและตารางปลด', 'Presale และสภาพคล่อง', 'ธรรมาภิบาลและการใช้เงิน', 'Roadmap', 'ความเสี่ยงและข้อกฎหมาย', 'ที่อยู่สัญญาทั้งหมด'] as $n => $t)
                        <li><b>{{ str_pad($n + 1, 2, '0', STR_PAD_LEFT) }}</b>{{ $t }}</li>
                    @endforeach
                </ol>
                <div class="cta-row"><a class="btn btn-primary" href="{{ route('whitepaper') }}">อ่านไวท์เปเปอร์</a></div>
            </div>
        </div>
    </section>

    {{-- ═════════ FAQ ═════════ --}}
    <section class="section section-alt" id="faq">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">คำถามที่พบบ่อย</p><h2 class="h2">ก่อนซื้อ ควรรู้อะไรบ้าง</h2></div>
            <div class="faq reveal">
                <details open><summary>ทำไม IDTC ย้ายจาก Polygon มาเชน TPIX</summary><p>เชน TPIX ไม่เก็บค่าแก๊ส ผู้ถือจึงซื้อ ขาย และโอนได้โดยไม่ต้องมีเหรียญอื่นติดกระเป๋า สัญญา presale เดิมบน Polygon ยังไม่มีผู้ซื้อเลย การย้ายครั้งนี้จึงไม่กระทบใคร</p></details>
                <details><summary>เหรียญที่ล็อกไว้ ใครถอนได้บ้าง</summary><p>ไม่มีใครถอนก่อนกำหนดได้ สัญญาล็อกไม่มีเจ้าของ ปลดตามเวลาไปที่กระเป๋าที่กำหนดไว้เท่านั้น และทุกคนเปิดดูยอดคงเหลือกับงวดถัดไปได้ในหน้าความโปร่งใส</p></details>
                <details><summary>ราคา presale เปลี่ยนได้ไหม</summary><p>ไม่ได้ อัตรา 1 USDT = 666,666.67 IDTC ฝังไว้ตอนสร้างสัญญา ทีมงานหยุดการขายชั่วคราวได้ แต่ขึ้นหรือลดราคาไม่ได้</p></details>
                <details><summary>ต้องใช้อะไรในการซื้อ</summary><p>กระเป๋าที่รองรับ EVM เช่น MetaMask และ USDT บนเชน TPIX ถ้ามี USDT บน BNB Chain ให้ส่งผ่านสะพานก่อน ไม่ต้องมีเหรียญ TPIX เพื่อจ่ายค่าแก๊ส</p></details>
                <details><summary>เงินที่ระดมได้ นำไปใช้อย่างไร</summary><p>USDT จาก presale เข้ากระเป๋าโครงการโดยตรง ส่วนหนึ่งใช้เติมสภาพคล่องในพูล ที่เหลือใช้ตามแผนที่ประกาศในไวท์เปเปอร์ การเคลื่อนไหวของกระเป๋าโครงการตรวจดูได้บน explorer</p></details>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <div class="risk reveal">
                <svg viewBox="0 0 24 24"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                <div><h3>คำเตือนความเสี่ยง</h3><p>IDTC เป็นสินทรัพย์ดิจิทัล ไม่ใช่เงินฝาก และไม่มีการรับประกันผลตอบแทน ราคาอาจผันผวนรุนแรงหรือลดลงจนไม่มีมูลค่า โปรดลงทุนเท่าที่รับความเสียหายได้ และศึกษากฎหมายในประเทศของคุณก่อน ในประเทศไทย การเสนอขายโทเคนดิจิทัลต่อประชาชนอยู่ภายใต้ พ.ร.ก. การประกอบธุรกิจสินทรัพย์ดิจิทัล พ.ศ. 2561</p></div>
            </div>
        </div>
    </section>

    <section class="cta-band">
        <div class="wrap reveal">
            <h2>เริ่มต้นที่ <span class="grad">${{ config('idtc.sale.price_usd') }}</span> ต่อเหรียญ</h2>
            <p>ราคาเดียวกันสำหรับทุกคนตลอดรอบ presale และได้เหรียญทันทีในธุรกรรมเดียว</p>
            <div class="cta-row"><a class="btn btn-primary" href="{{ route('presale') }}">ไปที่ Presale</a><button class="btn btn-ghost" type="button" @click="addNetwork()">เพิ่มเชน + เหรียญ</button></div>
        </div>
    </section>
@endsection

@php
    use App\Support\Idtc;
    $title = trim(($title ?? '') !== '' ? $title.' · IDTC Energy Capital' : 'IDTC Energy Capital — เหรียญเพื่อการพัฒนาพลังงานบนเชน TPIX');
    $description = $description ?? 'IDTC เหรียญเพื่อการพัฒนาพลังงานบนเชน TPIX ล็อก 70% ในสัญญาที่ไม่มีเจ้าของ ราคา presale ฝังในสัญญา $0.0000015 จ่ายด้วย USDT ค่าแก๊ส 0';
    $nav = [
        ['route' => 'home', 'label' => 'ภาพรวม'],
        ['route' => 'presale', 'label' => 'Presale'],
        ['route' => 'transparency', 'label' => 'ความโปร่งใส'],
        ['route' => 'whitepaper', 'label' => 'ไวท์เปเปอร์'],
        ['route' => 'add-network', 'label' => 'เพิ่มเชน'],
    ];
@endphp
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#04080a">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="IDTC Energy Capital">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ asset('images/brand/og.jpg') }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/idtc-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/idtc-192.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700&family=Noto+Sans+Thai:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap">
    @stack('preload')
    <script>window.IDTC = @json(Idtc::publicConfig());</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body x-data="site" @keydown.escape.window="menu = false" @if (! empty($autoAdd)) data-auto-add="1" @endif>
    @if (! empty($announcement))
        <div class="announce" role="note"><div class="wrap"><span>{{ $announcement }}</span></div></div>
    @endif

    <header class="nav">
        <div class="wrap">
            <a class="brand" href="{{ route('home') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Energy Capital</small></span></a>
            <nav class="nav-links" aria-label="เมนูหลัก">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}" @class(['is-on' => request()->routeIs($item['route'])])>{{ $item['label'] }}</a>
                @endforeach
            </nav>
            <div class="nav-cta">
                <button class="btn btn-ghost btn-sm" type="button" @click="addNetwork()">เพิ่มเชน + เหรียญ</button>
                <button class="btn btn-primary btn-sm" type="button" @click="connect()" x-text="account ? short(account) : 'เชื่อมกระเป๋า'">เชื่อมกระเป๋า</button>
                <button class="nav-burger" type="button" @click="menu = !menu" :aria-expanded="menu.toString()" aria-controls="navDrawer" aria-label="เมนู">
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
        <nav class="nav-drawer" id="navDrawer" x-show="menu" x-cloak x-transition.opacity aria-label="เมนูมือถือ">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}">{{ $item['label'] }}</a>
            @endforeach
            <a href="#" @click.prevent="addNetwork(); menu = false">เพิ่มเชน + เหรียญ IDTC</a>
        </nav>
        <p class="wrap toast" x-show="notice" x-cloak :class="noticeKind" x-text="notice" role="status" style="margin:0;padding-bottom:8px"></p>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="footer">
        <div class="wrap">
            <div class="cols">
                <div>
                    <a class="brand" href="{{ route('home') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Energy Capital</small></span></a>
                    <p style="margin-top:14px;max-width:34ch">เหรียญเพื่อการพัฒนาพลังงานบนเชน TPIX ล็อก 70% ตรวจสอบได้บนเชน</p>
                </div>
                <div><h4>เหรียญ</h4><ul>
                    <li><a href="{{ route('presale') }}">Presale</a></li>
                    <li><a href="{{ route('home') }}#tokenomics">Tokenomics</a></li>
                    <li><a href="{{ route('transparency') }}">ความโปร่งใส</a></li>
                </ul></div>
                <div><h4>เอกสาร</h4><ul>
                    <li><a href="{{ route('whitepaper') }}">ไวท์เปเปอร์</a></li>
                    <li><a href="{{ route('home') }}#faq">คำถามที่พบบ่อย</a></li>
                    <li><a href="{{ route('home') }}#buy">วิธีซื้อ</a></li>
                </ul></div>
                <div><h4>เชน TPIX</h4><ul>
                    <li><a href="{{ config('idtc.chain.explorer') }}" target="_blank" rel="noopener">Explorer</a></li>
                    <li><a href="https://tpix.online" target="_blank" rel="noopener">TPIX DEX</a></li>
                    <li><span class="mono" style="font-size:12.5px">Chain ID {{ config('idtc.chain.id') }}</span></li>
                </ul></div>
            </div>
            <div class="bottom"><span>© {{ now()->year + 543 }} IDTC Energy Capital</span><span>ไม่ใช่คำแนะนำการลงทุน · สินทรัพย์ดิจิทัลมีความเสี่ยง</span></div>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>

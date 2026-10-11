@php
    use App\Support\Idtc;
    use App\Support\SiteLocale;
    $locale = SiteLocale::current();
    $title = trim(($title ?? '') !== '' ? $title.' · '.__('site.name') : __('site.title'));
    $description = $description ?? __('site.description', ['price' => config('idtc.sale.price_usd')]);
    $alternates = SiteLocale::alternates();
    $canonical = SiteLocale::page() !== null ? $alternates[$locale] : url()->current();
    $nav = ['home', 'presale', 'transparency', 'whitepaper', 'add-network'];
    $langs = ['th' => 'TH', 'en' => 'EN'];
@endphp
<!doctype html>
<html lang="{{ SiteLocale::htmlLang() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#04080a">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if (SiteLocale::page() !== null)
        @foreach ($alternates as $hreflang => $href)
            <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
        @endforeach
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ __('site.name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ SiteLocale::ogLocale() }}">
    @foreach (array_keys(SiteLocale::SUPPORTED) as $other)
        @if ($other !== $locale)
            <meta property="og:locale:alternate" content="{{ SiteLocale::ogLocale($other) }}">
        @endif
    @endforeach
    <meta property="og:image" content="{{ asset(__('site.og_image')) }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
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
            <a class="brand" href="{{ lroute('home') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Energy Capital</small></span></a>
            <nav class="nav-links" aria-label="{{ __('site.nav.main') }}">
                @foreach ($nav as $page)
                    <a href="{{ lroute($page) }}" @class(['is-on' => request()->routeIs(SiteLocale::routeName($page))])>{{ __('site.nav.'.$page) }}</a>
                @endforeach
            </nav>
            <div class="nav-cta">
                <div class="lang-switch" role="group" aria-label="{{ __('site.lang.label') }}" data-testid="lang-switch">
                    @foreach ($langs as $code => $label)
                        <a href="{{ $alternates[$code] }}" hreflang="{{ $code }}" aria-label="{{ __('site.lang.'.$code) }}" title="{{ __('site.lang.'.$code) }}" @class(['is-on' => $code === $locale]) @if ($code === $locale) aria-current="true" @endif @click="pickLang('{{ $code }}', $event)">{{ $label }}</a>
                        @unless ($loop->last)<span aria-hidden="true">|</span>@endunless
                    @endforeach
                </div>
                <button class="btn btn-ghost btn-sm" type="button" @click="addNetwork()">{{ __('site.add_network') }}</button>
                <button class="btn btn-primary btn-sm" type="button" @click="connect()" x-text="account ? short(account) : @js(__('site.connect'))">{{ __('site.connect') }}</button>
                <button class="nav-burger" type="button" @click="menu = !menu" :aria-expanded="menu.toString()" aria-controls="navDrawer" aria-label="{{ __('site.nav.menu') }}">
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
        <nav class="nav-drawer" id="navDrawer" x-show="menu" x-cloak x-transition.opacity aria-label="{{ __('site.nav.mobile') }}">
            @foreach ($nav as $page)
                <a href="{{ lroute($page) }}">{{ __('site.nav.'.$page) }}</a>
            @endforeach
            <a href="#" @click.prevent="addNetwork(); menu = false">{{ __('site.add_network_full') }}</a>
            <div class="lang-switch lang-switch--drawer" role="group" aria-label="{{ __('site.lang.label') }}">
                @foreach ($langs as $code => $label)
                    <a href="{{ $alternates[$code] }}" hreflang="{{ $code }}" aria-label="{{ __('site.lang.'.$code) }}" @class(['is-on' => $code === $locale]) @if ($code === $locale) aria-current="true" @endif @click="pickLang('{{ $code }}', $event)">{{ $label }}</a>
                    @unless ($loop->last)<span aria-hidden="true">|</span>@endunless
                @endforeach
            </div>
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
                    <a class="brand" href="{{ lroute('home') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Energy Capital</small></span></a>
                    <p style="margin-top:14px;max-width:34ch">{{ __('site.footer.tagline') }}</p>
                </div>
                <div><h4>{{ __('site.footer.token') }}</h4><ul>
                    <li><a href="{{ lroute('presale') }}">{{ __('site.nav.presale') }}</a></li>
                    <li><a href="{{ lroute('home') }}#tokenomics">Tokenomics</a></li>
                    <li><a href="{{ lroute('transparency') }}">{{ __('site.nav.transparency') }}</a></li>
                </ul></div>
                <div><h4>{{ __('site.footer.docs') }}</h4><ul>
                    <li><a href="{{ lroute('whitepaper') }}">{{ __('site.nav.whitepaper') }}</a></li>
                    <li><a href="{{ lroute('home') }}#faq">{{ __('site.footer.faq') }}</a></li>
                    <li><a href="{{ lroute('home') }}#buy">{{ __('site.footer.how_to_buy') }}</a></li>
                </ul></div>
                <div><h4>{{ __('site.footer.chain') }}</h4><ul>
                    <li><a href="{{ config('idtc.chain.explorer') }}" target="_blank" rel="noopener">Explorer</a></li>
                    <li><a href="https://tpix.online" target="_blank" rel="noopener">TPIX DEX</a></li>
                    <li><span class="mono" style="font-size:12.5px">Chain ID {{ config('idtc.chain.id') }}</span></li>
                </ul></div>
            </div>
            <div class="bottom"><span>© {{ SiteLocale::year() }} IDTC Energy Capital</span><span>{{ __('site.footer.disclaimer') }}</span></div>
        </div>
    </footer>

    @if ($locale === SiteLocale::DEFAULT)
        {{-- ผู้เข้าชมที่เบราว์เซอร์ไม่ได้ตั้งภาษาไทย: ชวนสลับเป็นภาษาอังกฤษ (ไม่ redirect เอง) · ข้อความเป็นภาษาอังกฤษ --}}
        <div class="lang-hint" x-data="langHint" x-show="show" x-cloak x-transition.opacity role="note" lang="en" data-testid="lang-hint">
            <span>{{ __('site.hint.text', [], 'en') }}</span>
            <a href="{{ $alternates['en'] }}" hreflang="en" @click="pick()">{{ __('site.hint.switch', [], 'en') }}</a>
            <button type="button" class="lang-hint__x" @click="dismiss()" aria-label="{{ __('site.hint.dismiss', [], 'en') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
    @endif
    @stack('scripts')
</body>
</html>

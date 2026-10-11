@extends('layouts.site')

@php
    use App\Support\SiteLocale;
    use App\Support\Tokenomics;
    $total = (float) config('idtc.token.total_supply');
    $price = config('idtc.sale.price_usd');
    $sale = config('idtc.sale');
    // IDTC ต่อ 1 USDT = 10^payDecimals × num ÷ den ÷ 10^18 (สูตรเดียวกับสัญญา)
    $rate = number_format(((float) $sale['rate_num'] / (float) $sale['rate_den']) * 10 ** (int) $sale['pay_decimals'] / 10 ** (int) config('idtc.token.decimals'), 2);
    $allocation = Tokenomics::allocation();
    $slideMeta = [
        ['img' => 'hero-1', 'eyebrowClass' => '', 'to' => ['presale'], 'to2' => ['whitepaper']],
        ['img' => 'hero-2', 'eyebrowClass' => '', 'to' => ['transparency'], 'to2' => ['home', '#unlock']],
        ['img' => 'hero-3', 'eyebrowClass' => 'gold', 'to' => ['home', '#about'], 'to2' => ['whitepaper']],
        ['img' => 'hero-4', 'eyebrowClass' => '', 'to' => ['presale'], 'to2' => ['add-network']],
    ];
    $slides = [];
    foreach (__('home.hero.slides', ['price' => $price]) as $i => $copy) {
        $slides[] = $slideMeta[$i] + $copy;
    }
    $link = fn (array $c) => lroute($c[0]).($c[1] ?? '');
@endphp

@push('preload')
    <link rel="preload" as="image" href="{{ asset('images/art/hero-1.webp') }}" media="(min-width: 761px)">
    <link rel="preload" as="image" href="{{ asset('images/art/hero-1-m.webp') }}" media="(max-width: 760px)">
@endpush

@section('content')
    {{-- ═════════ HERO SLIDER ═════════ --}}
    <section class="hero" x-data="heroSlider({{ count($slides) }})" @focusin="$event.target.matches(':focus-visible') && hold(true)" @focusout="hold(false)" @touchstart.passive="touchStart($event)" @touchend.passive="touchEnd($event)" :class="{ 'is-paused': paused }" aria-roledescription="carousel" aria-label="{{ __('home.hero.aria') }}">
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
                        <div class="slide-copy {{ $i === 0 ? 'is-on' : '' }}" :class="{ 'is-on': index === {{ $i }} }" role="group" aria-roledescription="slide" aria-label="{{ __('home.hero.slide_of', ['n' => $i + 1, 'total' => count($slides)]) }}" :aria-hidden="(index !== {{ $i }}).toString()">
                            <p class="eyebrow {{ $s['eyebrowClass'] }}"><span class="pulse"></span>{{ $s['eyebrow'] }}</p>
                            @if ($i === 0)
                                <h1>{{ $s['title'] }} <span class="grad">{{ $s['grad'] }}</span></h1>
                            @else
                                <h2 class="hero-h">{{ $s['title'] }} <span class="grad">{{ $s['grad'] }}</span></h2>
                            @endif
                            <p class="hero-lead">{!! $s['lead'] !!}</p>
                            <div class="cta-row">
                                <a class="btn btn-primary" href="{{ $link($s['to']) }}" :tabindex="index === {{ $i }} ? 0 : -1">
                                    <svg viewBox="0 0 24 24"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>{{ $s['cta'] }}
                                </a>
                                <a class="btn btn-ghost" href="{{ $link($s['to2']) }}" :tabindex="index === {{ $i }} ? 0 : -1">{{ $s['cta2'] }}</a>
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
                        <div class="price-amt">${{ $price }}</div>
                        <div class="price-sub">{{ __('home.dock.per', ['rate' => $rate]) }}</div>
                    </div>
                    <div>
                        <div class="meter-head">
                            <span x-text="live ? @js(__('home.dock.sold')) : @js(__('home.dock.soon'))">{{ __('home.dock.soon') }}</span>
                            <b class="num" x-text="live ? fmt(sold) + ' IDTC' : '0 IDTC'">0 IDTC</b>
                        </div>
                        <div class="bar"><i :style="`--w:${pct}%`"></i></div>
                        <div class="meter-foot">
                            <span x-text="pct.toFixed(2) + @js(__('home.dock.pct_of', ['cap' => SiteLocale::big($sale['cap'])]))">0.00{{ __('home.dock.pct_of', ['cap' => SiteLocale::big($sale['cap'])]) }}</span>
                            <span x-text="live ? @js(__('home.dock.raised')) + fmt(raised, 2) : @js(__('home.dock.waiting'))">{{ __('home.dock.waiting') }}</span>
                        </div>
                    </div>
                </div>
                <div class="slider-ctrl">
                    <button class="arrow" type="button" @click="prev()" aria-label="{{ __('home.hero.prev') }}"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></button>
                    <div class="dots" role="tablist" aria-label="{{ __('home.hero.pick') }}">
                        @foreach ($slides as $i => $s)
                            <button class="dot" type="button" role="tab" :class="{ 'is-on': index === {{ $i }}, 'is-done': index > {{ $i }} }" :aria-selected="(index === {{ $i }}).toString()" @click="go({{ $i }})" aria-label="{{ __('home.hero.dot', ['n' => $i + 1]) }}" :style="`--dur:${duration}ms`" :key="'d{{ $i }}-' + cycle"><i></i></button>
                        @endforeach
                    </div>
                    <button class="arrow" type="button" @click="next()" aria-label="{{ __('home.hero.next') }}"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>
                    <span class="slide-label" x-text="(index + 1) + ' / {{ count($slides) }}'">1 / {{ count($slides) }}</span>
                </div>
            </div>
        </div>
    </section>

    {{-- ═════════ STATS ═════════ --}}
    <section class="stats" aria-label="{{ __('home.stats.aria') }}">
        <div class="wrap">
            <div class="stat"><div class="stat-label">{{ __('home.stats.supply') }}</div><div class="stat-value"><span data-count="1100000000000" data-short>1.1T</span></div></div>
            <div class="stat"><div class="stat-label">{{ __('home.stats.locked') }}</div><div class="stat-value"><span data-count="770000000000" data-short>770B</span><small>70%</small></div></div>
            <div class="stat"><div class="stat-label">{{ __('home.stats.value') }}</div><div class="stat-value">$<span data-count="1650000">1,650,000</span></div></div>
            <div class="stat"><div class="stat-label">{{ __('home.stats.gas') }}</div><div class="stat-value">0<small>TPIX</small></div></div>
        </div>
    </section>

    {{-- ═════════ EXPLAINER VIDEO ═════════ --}}
    <section class="section video-sec" id="video">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">{{ __('home.video.kicker') }}</p>
                <h2 class="h2">{!! __('home.video.title') !!}</h2>
                <p class="lead" data-testid="video-lead">{{ __('home.video.lead') }}</p>
            </div>
            <figure class="video-frame reveal" x-data="explainer">
                <video x-ref="v" controls playsinline preload="none" poster="{{ asset('images/video/poster.webp') }}" width="1920" height="1080"
                       @play="playing = true" @pause="playing = false" @ended="playing = false; done = true">
                    <source src="{{ asset('media/idtc-explainer-1080p.mp4') }}" type="video/mp4">
                </video>
                <button class="video-play" type="button" x-show="!playing" @click="play()" :aria-label="done ? @js(__('home.video.replay')) : @js(__('home.video.play'))" aria-label="{{ __('home.video.play') }}">
                    <span class="video-play__ring"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></svg></span>
                    <span class="video-play__label" x-text="done ? @js(__('home.video.again')) : @js(__('home.video.watch'))">{{ __('home.video.watch') }}</span>
                </button>
                <figcaption>{{ __('home.video.caption') }}</figcaption>
            </figure>
        </div>
    </section>

    {{-- ═════════ ABOUT ═════════ --}}
    <section class="section" id="about">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">IDTC Energy Capital</p>
                <h2 class="h2">{!! __('home.about.title') !!}</h2>
                <p class="lead">{{ __('home.about.lead') }}</p>
            </div>
            <div class="sectors">
                @foreach (__('home.about.sectors') as $img => $sector)
                    <article class="sector reveal">
                        <figure><img src="{{ asset('images/art/'.$img.'.webp') }}" alt="{{ __('site.illus_alt', ['what' => $sector['alt']]) }}" loading="lazy" width="1000" height="563"><span class="tag-illus">{{ __('site.illus') }}</span></figure>
                        <div class="sector-body"><h3>{{ $sector['title'] }}</h3><p>{{ $sector['text'] }}</p></div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═════════ TRUST ═════════ --}}
    <section class="section section-alt" id="trust">
        <div class="wrap guard">
            <div class="reveal">
                <p class="kicker">{{ __('home.trust.kicker') }}</p>
                <h2 class="h2">{!! __('home.trust.title') !!}</h2>
                <p class="lead" style="margin-top:14px">{{ __('home.trust.lead') }}</p>
                <ul class="guard-list">
                    @foreach (__('home.trust.items') as [$b, $s])
                        <li><span class="ic"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></span><div><b>{{ $b }}</b><span>{{ $s }}</span></div></li>
                    @endforeach
                </ul>
            </div>
            <figure class="vault reveal">
                <img src="{{ asset('images/art/lock.webp') }}" alt="{{ __('site.illus_alt', ['what' => __('home.trust.vault_alt')]) }}" loading="lazy" width="900" height="900">
                <div class="vault-badge"><b class="num"><span data-count="770000000000" data-short>770B</span></b><span>{{ __('home.trust.vault_badge') }}</span></div>
                <span class="tag-illus">{{ __('site.illus') }}</span>
            </figure>
        </div>
    </section>

    {{-- ═════════ TOKENOMICS ═════════ --}}
    <section class="section" id="tokenomics">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">Tokenomics</p>
                <h2 class="h2">{!! __('home.tokenomics.title') !!}</h2>
            </div>
            <div class="tokenomics" x-data="donut(@js($allocation))">
                <div class="donut-wrap reveal">
                    <svg viewBox="0 0 200 200" x-ref="svg" aria-label="{{ __('home.tokenomics.chart') }}"></svg>
                    <div class="donut-center"><b x-text="centerValue">1.1T</b><span x-text="centerLabel">{{ __('home.tokenomics.total') }}</span></div>
                </div>
                <div class="table-scroll reveal">
                    <table class="alloc">
                        <thead><tr><th>{{ __('home.tokenomics.col_part') }}</th><th>{{ __('home.tokenomics.col_share') }}</th><th>{{ __('home.tokenomics.col_amount') }}</th></tr></thead>
                        <tbody>
                            @foreach (collect($allocation)->groupBy('group') as $group => $items)
                                <tr class="group"><td colspan="3">{{ __('plan.group.'.$group) }}</td></tr>
                                @foreach ($items as $it)
                                    @php $idx = array_search($it, $allocation, true); @endphp
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
                <p class="kicker">{{ __('home.unlock.kicker') }}</p>
                <h2 class="h2">{!! __('home.unlock.title') !!}</h2>
                <p class="lead">{{ __('home.unlock.lead') }}</p>
            </div>
            <div class="chart-card reveal" x-data="unlockChart(@js(Tokenomics::tranches()))">
                <div class="chart-top">
                    <div class="legend"><span><i style="background:linear-gradient(90deg,#a3e635,#10b981)"></i>{{ __('home.unlock.legend_circ') }}</span><span><i style="background:rgba(59,130,246,.6)"></i>{{ __('home.unlock.legend_locked') }}</span></div>
                    <span class="pill-wait">{{ __('home.unlock.month_zero') }}</span>
                </div>
                <div class="chart-scroll"><svg class="chart-svg" x-ref="svg" viewBox="0 0 900 330" aria-label="{{ __('home.unlock.chart') }}"></svg></div>
                <div class="notes3">
                    @foreach (__('home.unlock.notes') as [$b, $s])
                        <div class="note"><b>{{ $b }}</b>{{ $s }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ═════════ HOW TO BUY ═════════ --}}
    <section class="section" id="buy">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">{{ __('home.buy.kicker') }}</p>
                <h2 class="h2">{!! __('home.buy.title') !!}</h2>
            </div>
            <figure class="routes-hero reveal">
                <img src="{{ asset('images/art/bridge.webp') }}" alt="{{ __('site.illus_alt', ['what' => __('home.buy.bridge_alt')]) }}" loading="lazy" width="1400" height="788">
                <div class="overlay"><span class="chain-pill bsc">{{ __('home.buy.pill_bsc') }}</span><span class="chain-pill tpix">{{ __('home.buy.pill_tpix') }}</span></div>
            </figure>
            <div class="routes">
                @foreach (__('home.buy.routes', ['price' => $price]) as $i => $route)
                    <article @class(['route', 'reveal', 'route-best' => $i === 0])>@if ($i === 0)<span class="flag">{{ __('home.buy.best') }}</span>@endif<span class="step">{{ $i + 1 }}</span><h3>{{ $route['title'] }}</h3><p>{{ $route['text'] }}</p><span class="meta">{{ $route['meta'] }}</span></article>
                @endforeach
            </div>
            @include('partials.add-network')
        </div>
    </section>

    {{-- ═════════ ROADMAP ═════════ --}}
    <section class="section section-alt" id="roadmap">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">Roadmap</p><h2 class="h2">{!! __('home.roadmap.title') !!}</h2></div>
            <ol class="roadmap">
                @foreach (__('home.roadmap.phases') as $i => $phase)
                    <li @class(['phase', 'reveal', 'phase-now' => $i === 1])><span class="st {{ ['st-done', 'st-now', 'st-next'][$i] ?? 'st-next' }}">{{ $phase['status'] }}</span><h3>{{ $phase['title'] }}</h3><ul>@foreach ($phase['items'] as $item)<li>{{ $item }}</li>@endforeach</ul></li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═════════ WHITEPAPER ═════════ --}}
    <section class="section" id="whitepaper">
        <div class="wrap wp">
            <div class="wp-cover reveal">
                <img src="{{ asset('images/art/cover.webp') }}" alt="" loading="lazy" width="800" height="1200">
                <div class="t"><img src="{{ asset('images/brand/idtc-128.png') }}" alt="" width="46" height="46"><b>IDTC<br>Whitepaper</b><span>{{ __('home.wp.version') }}</span></div>
            </div>
            <div class="reveal">
                <p class="kicker">{{ __('home.wp.kicker') }}</p>
                <h2 class="h2">{!! __('home.wp.title') !!}</h2>
                <ol class="toc">
                    @foreach (__('home.wp.toc') as $n => $t)
                        <li><b>{{ str_pad($n + 1, 2, '0', STR_PAD_LEFT) }}</b>{{ $t }}</li>
                    @endforeach
                </ol>
                <div class="cta-row"><a class="btn btn-primary" href="{{ lroute('whitepaper') }}">{{ __('home.wp.cta') }}</a></div>
            </div>
        </div>
    </section>

    {{-- ═════════ FAQ ═════════ --}}
    <section class="section section-alt" id="faq">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('home.faq.kicker') }}</p><h2 class="h2">{{ __('home.faq.title') }}</h2></div>
            <div class="faq reveal">
                @foreach (__('home.faq.items', ['rate' => $rate]) as $i => [$q, $a])
                    <details @if ($i === 0) open @endif><summary>{{ $q }}</summary><p>{{ $a }}</p></details>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section">
        <div class="wrap">
            <div class="risk reveal">
                <svg viewBox="0 0 24 24"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                <div><h3>{{ __('home.risk.title') }}</h3><p>{{ __('home.risk.text') }}</p></div>
            </div>
        </div>
    </section>

    <section class="cta-band">
        <div class="wrap reveal">
            <h2>{!! __('home.cta.title', ['price' => e($price)]) !!}</h2>
            <p>{{ __('home.cta.text') }}</p>
            <div class="cta-row"><a class="btn btn-primary" href="{{ lroute('presale') }}">{{ __('home.cta.presale') }}</a><button class="btn btn-ghost" type="button" @click="addNetwork()">{{ __('site.add_network') }}</button></div>
        </div>
    </section>
@endsection

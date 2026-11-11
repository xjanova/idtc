@extends('layouts.site')

@php
    use App\Support\Launch;
    use App\Support\SiteLocale;
    // ตัวเลขทุกตัวมาจากแผนที่ใช้อยู่ ($plan) / ค่าการขาย — ไม่พิมพ์ตัวเลขลงข้อความ
    $r = $plan['rank'];
    $hold = $plan['hold_days'];
    $budget = SiteLocale::big($plan['budget']);
    $ranks = [
        'spark' => __('grid.rank_req.spark', ['own' => number_format($r['spark_own'])]),
        'cell' => __('grid.rank_req.cell', ['d' => $r['cell_d']]),
        'grid' => __('grid.rank_req.grid', ['d' => $r['grid_d'], 't' => number_format($r['grid_t'])]),
        'core' => __('grid.rank_req.core', ['d' => $r['core_d'], 't' => number_format($r['core_t'])]),
    ];
    $rate = number_format($perUsdt, 2);
    $calc = [
        'perUsdt' => $perUsdt,
        'levels' => array_map('floatval', $plan['levels']),
        'buyer' => (float) $plan['buyer'],
        'phases' => array_map(fn ($p) => ['add' => $p['add']], $phases),
        'locale' => SiteLocale::SUPPORTED[SiteLocale::current()]['date'],
    ];
    $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/></svg>';
    $fairIcons = [
        'budget' => '<path d="M4 7h16v12H4z"/><path d="M4 11h16M8 15h3"/>',
        'hold' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'chain' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'nocomp' => '<path d="M12 19V6M6.5 11.5 12 6l5.5 5.5"/><path d="M5 5l14 14"/>',
        'bind' => '<path d="M9.5 14.5 14.5 9.5"/><path d="M11 6.5l1.8-1.8a4 4 0 0 1 5.7 5.7L16.7 12M13 17.5l-1.8 1.8a4 4 0 0 1-5.7-5.7L7.3 12"/>',
        'rate' => '<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/>',
    ];
    $faqArgs = [
        'own' => number_format($r['spark_own']),
        'budget' => $budget,
        'hold' => $hold,
        'instant' => $pct($plan['instant']),
        'end' => Launch::date('end'),
    ];
@endphp

@section('content')
    {{-- ═════════ HERO ═════════ --}}
    <section class="rw-hero">
        <div class="wrap rw-hero__grid">
            <div class="rw-hero__copy">
                <p class="kicker">{{ __('rewards.kicker') }}</p>
                <h1>{!! __('rewards.h1') !!}</h1>
                <p class="lead">{{ __('rewards.lead') }}</p>
                <div class="rw-cta">
                    @if ($open)
                        <a class="btn btn-primary" href="{{ lroute('grid') }}" data-testid="rewards-open">{{ __('rewards.cta_open') }}<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
                    @else
                        <a class="btn btn-primary" href="{{ lroute('presale') }}">{{ __('rewards.cta_presale') }}<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
                    @endif
                    <a class="btn btn-ghost" href="#video"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></svg>{{ __('rewards.cta_video') }}</a>
                </div>
                @unless ($open)
                    <p class="rw-soon" data-testid="rewards-soon"><span class="pill-wait">{{ __('rewards.cta_soon') }}</span></p>
                @endunless
            </div>
            <div class="rw-orbit" aria-hidden="true">
                <svg viewBox="0 0 400 400">
                    <defs>
                        <radialGradient id="rwCore" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#a3e635"/><stop offset="1" stop-color="#10b981"/></radialGradient>
                    </defs>
                    <circle class="rw-orbit__ring r3" cx="200" cy="200" r="180"/>
                    <circle class="rw-orbit__ring r2" cx="200" cy="200" r="128"/>
                    <circle class="rw-orbit__ring r1" cx="200" cy="200" r="78"/>
                    <g class="rw-orbit__spin s3">@foreach ([0, 40, 80, 120, 160, 200, 240, 280, 320] as $a)<circle r="5" cx="{{ 200 + 180 * cos(deg2rad($a)) }}" cy="{{ 200 + 180 * sin(deg2rad($a)) }}"/>@endforeach</g>
                    <g class="rw-orbit__spin s2">@foreach ([20, 92, 164, 236, 308] as $a)<circle r="6.5" cx="{{ 200 + 128 * cos(deg2rad($a)) }}" cy="{{ 200 + 128 * sin(deg2rad($a)) }}"/>@endforeach</g>
                    <g class="rw-orbit__spin s1">@foreach ([0, 120, 240] as $a)<circle r="8" cx="{{ 200 + 78 * cos(deg2rad($a)) }}" cy="{{ 200 + 78 * sin(deg2rad($a)) }}"/>@endforeach</g>
                    <circle cx="200" cy="200" r="34" fill="url(#rwCore)"/>
                    <text x="200" y="206" text-anchor="middle" class="rw-orbit__you">{{ __('rewards.rings.you') }}</text>
                    <text x="200" y="128" text-anchor="middle" class="rw-orbit__pct">{{ $pct($plan['levels'][0]) }}%</text>
                    <text x="200" y="78" text-anchor="middle" class="rw-orbit__pct">{{ $pct($plan['levels'][1]) }}%</text>
                    <text x="200" y="28" text-anchor="middle" class="rw-orbit__pct">{{ $pct($plan['levels'][2]) }}%</text>
                </svg>
            </div>
        </div>
        <div class="wrap">
            <div class="rw-stats" data-testid="rewards-stats">
                <div><span>{{ __('rewards.stats.budget') }}</span><b>{{ $budget }} <small>IDTC</small></b></div>
                <div><span>{{ __('rewards.stats.rings') }}</span><b>{{ $pct($plan['levels'][0]) }} · {{ $pct($plan['levels'][1]) }} · {{ $pct($plan['levels'][2]) }}<small>%</small></b></div>
                <div><span>{{ __('rewards.stats.buyer') }}</span><b>+{{ $pct($plan['buyer']) }}<small>%</small></b></div>
                <div><span>{{ __('rewards.stats.hold') }}</span><b>{{ $hold }} <small>{{ __('rewards.stats.days') }}</small></b></div>
            </div>
        </div>
    </section>

    {{-- ═════════ VIDEO ═════════ --}}
    <section class="section video-sec" id="video">
        <div class="wrap">
            <div class="section-head reveal">
                <p class="kicker">{{ __('rewards.video.kicker') }}</p>
                <h2 class="h2">{!! __('rewards.video.title') !!}</h2>
                <p class="lead">{{ __('rewards.video.lead') }}</p>
            </div>
            <figure class="video-frame reveal" x-data="explainer">
                <video x-ref="v" controls playsinline preload="none" poster="{{ asset('images/video/grid-plan-poster.webp') }}" width="1920" height="1080"
                       @play="playing = true" @pause="playing = false" @ended="playing = false; done = true">
                    <source src="{{ asset('media/idtc-grid-plan-1080p.mp4') }}" type="video/mp4">
                    <track kind="captions" src="{{ asset('media/idtc-grid-plan-en.vtt') }}" srclang="en" label="{{ __('rewards.video.track') }}">
                </video>
                <button class="video-play" type="button" x-show="!playing" @click="play()" :aria-label="done ? @js(__('home.video.replay')) : @js(__('rewards.video.play'))" aria-label="{{ __('rewards.video.play') }}">
                    <span class="video-play__ring"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z"/></svg></span>
                    <span class="video-play__label" x-text="done ? @js(__('home.video.again')) : @js(__('rewards.video.watch'))">{{ __('rewards.video.watch') }}</span>
                </button>
                <figcaption>{{ __('rewards.video.caption') }}</figcaption>
            </figure>
        </div>
    </section>

    {{-- ═════════ STEPS ═════════ --}}
    <section class="section section-alt">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('rewards.steps.kicker') }}</p><h2 class="h2">{!! __('rewards.steps.title') !!}</h2></div>
            <ol class="rw-steps reveal">
                @foreach (__('rewards.steps.items', ['hold' => $hold]) as $i => [$h, $t])
                    <li><span class="rw-steps__n">{{ $i + 1 }}</span><h3>{{ $h }}</h3><p>{{ $t }}</p></li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═════════ RINGS ═════════ --}}
    <section class="section" id="rings">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('rewards.rings.kicker') }}</p><h2 class="h2">{!! __('rewards.rings.title') !!}</h2><p class="lead">{{ __('rewards.rings.lead') }}</p></div>
            <div class="rw-two">
                <div class="rw-rings reveal" data-testid="rewards-rings">
                    @foreach ([1, 2, 3] as $n)
                        <div class="rw-ring" data-ring="{{ $n }}">
                            <span class="rw-ring__tag">{{ __('rewards.rings.ring', ['n' => $n]) }}</span>
                            <span class="rw-ring__who">{{ __('rewards.rings.ring'.$n) }}</span>
                            <b class="rw-ring__pct">{{ $pct($plan['levels'][$n - 1]) }}%</b>
                        </div>
                    @endforeach
                    <div class="rw-ring is-buyer">
                        <span class="rw-ring__who">{{ __('rewards.rings.buyer') }}</span>
                        <b class="rw-ring__pct">+{{ $pct($plan['buyer']) }}%</b>
                    </div>
                    <p class="muted-s">% {{ __('grid.how.of_buy') }}</p>
                </div>
                <div class="panel rw-boost reveal">
                    <h3>{{ __('rewards.rings.boost_title') }}</h3>
                    <p class="muted-p">{{ __('rewards.rings.boost_lead') }}</p>
                    <ol class="rw-weeks">
                        @foreach ($phases as $p)
                            <li @class(['is-base' => $p['add'] == 0])>
                                <div><b>{{ $p['label'] }}</b>@if ($p['dates'])<small>{{ $p['dates'] }}</small>@endif</div>
                                <span class="rw-weeks__rate">{{ $pct($p['rate']) }}%@if ($p['add'] > 0)<small>+{{ $pct($p['add']) }}</small>@endif</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </section>

    {{-- ═════════ CALCULATOR ═════════ --}}
    <section class="section section-alt" id="calc">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('rewards.calc.kicker') }}</p><h2 class="h2">{!! __('rewards.calc.title') !!}</h2><p class="lead">{{ __('rewards.calc.lead') }}</p></div>
            <div class="panel rw-calc reveal" x-data="rewardCalc(@js($calc))">
                <div class="rw-calc__in">
                    <label>
                        <span>{{ __('rewards.calc.usdt') }}</span>
                        <input type="number" inputmode="decimal" min="0" step="1" x-model="usdt" value="100" data-testid="calc-usdt">
                    </label>
                    <label>
                        <span>{{ __('rewards.calc.week') }}</span>
                        <select x-model.number="phase">
                            @foreach ($phases as $i => $p)
                                <option value="{{ $i }}">{{ $p['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="rw-calc__bought"><span>{{ __('rewards.calc.bought') }}</span><b><span x-text="fmt(bought)">{{ number_format(100 * $perUsdt) }}</span> IDTC</b></div>
                </div>
                <div class="table-scroll">
                    <table class="rw-calc__tbl">
                        <thead><tr><th>{{ __('rewards.calc.col_who') }}</th><th class="r">{{ __('rewards.calc.col_rate') }}</th><th class="r">{{ __('rewards.calc.col_idtc') }}</th></tr></thead>
                        <tbody>
                            @foreach ([1, 2, 3] as $n)
                                <tr><td>{{ __('rewards.calc.if_ring', ['n' => $n]) }}</td><td class="r num" x-text="pct(rate({{ $n }})) + '%'">{{ $pct($plan['levels'][$n - 1] + ($n === 1 ? $phases[0]['add'] : 0)) }}%</td><td class="r num"><b x-text="fmt(bought * rate({{ $n }}))"></b></td></tr>
                            @endforeach
                            <tr class="is-buyer"><td>{{ __('rewards.calc.buyer') }}</td><td class="r num">{{ $pct($plan['buyer']) }}%</td><td class="r num"><b x-text="fmt(bought * cfg.buyer)"></b></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="muted-s">{{ __('rewards.calc.rate_note', ['rate' => $rate]) }}</p>
                <p class="plan-note warn">{!! $icon !!}<span>{{ __('rewards.calc.note', ['hold' => $hold]) }}</span></p>
            </div>
        </div>
    </section>

    {{-- ═════════ RANKS ═════════ --}}
    <section class="section">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('rewards.ranks.kicker') }}</p><h2 class="h2">{!! __('rewards.ranks.title') !!}</h2><p class="lead">{{ __('rewards.ranks.lead', ['own' => number_format($r['spark_own'])]) }}</p></div>
            <ol class="rw-ladder reveal" data-testid="rewards-ranks">
                @foreach ($ranks as $key => $req)
                    <li data-rank="{{ $key }}">
                        <span class="rw-ladder__step">{{ $loop->iteration }}</span>
                        <h3>{{ __('grid.ranks.'.$key) }}</h3>
                        <dl><dt>{{ __('rewards.ranks.need') }}</dt><dd>{{ $req }}</dd><dt>{{ __('rewards.ranks.get') }}</dt><dd>{{ __('grid.rank_get.'.$key) }}</dd></dl>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ═════════ SPRINT ═════════ --}}
    <section class="section section-alt">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('rewards.sprint.kicker') }}</p><h2 class="h2">{!! __('rewards.sprint.title') !!}</h2></div>
            <div class="rw-sprint reveal">
                <div class="panel"><b class="rw-big">{{ $pct($plan['sprint']) }}%</b><p>{{ __('rewards.sprint.pool') }}</p></div>
                <div class="panel"><b class="rw-big">×1 · ×2</b><p>{{ __('rewards.sprint.who') }}</p></div>
                <div class="panel"><b class="rw-big">{{ $pct($plan['sprint_cap']) }}%</b><p>{{ __('rewards.sprint.cap') }}</p><p class="muted-s">{{ __('rewards.sprint.rest') }}</p></div>
            </div>
        </div>
    </section>

    {{-- ═════════ FAIRNESS ═════════ --}}
    <section class="section">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('rewards.fair.kicker') }}</p><h2 class="h2">{!! __('rewards.fair.title') !!}</h2></div>
            <div class="rw-fair reveal">
                @foreach (__('rewards.fair.items', ['budget' => $budget, 'share' => $allocShare ?? '—', 'alloc' => SiteLocale::big($alloc), 'hold' => $hold]) as $key => [$h, $t])
                    <div class="panel"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $fairIcons[$key] !!}</svg><h3>{{ $h }}</h3><p>{{ $t }}</p></div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═════════ MONEY FLOW + PAYOUT ═════════ --}}
    <section class="section section-alt">
        <div class="wrap rw-two">
            <div class="reveal">
                <p class="kicker">{{ __('rewards.money.kicker') }}</p>
                <h2 class="h2" style="margin-bottom:22px">{!! __('rewards.money.title') !!}</h2>
                <div class="rw-flow">
                    <div class="rw-flow__row"><span>{{ __('rewards.money.sale') }}</span><b>{{ Launch::poolSharePercent() }}%</b><em>{{ __('rewards.money.pool') }}</em></div>
                    <div class="rw-flow__row"><span>{{ __('rewards.money.lock') }}</span><b>{{ Launch::date('lp_unlock') }}</b></div>
                    <div class="rw-flow__row is-budget"><span>{{ __('rewards.money.budget') }}</span><b>{{ __('rewards.money.budget_of', ['budget' => $budget]) }}</b></div>
                </div>
                <a class="rw-link" href="{{ lroute('transparency') }}">{{ __('rewards.money.link') }} →</a>
            </div>
            <div class="reveal">
                <p class="kicker">{{ __('rewards.payout.kicker') }}</p>
                <h2 class="h2" style="margin-bottom:14px">{!! __('rewards.payout.title') !!}</h2>
                <p class="muted-p">{{ __('rewards.payout.lead') }}</p>
                <div class="rw-split" role="img" aria-label="{{ $pct($plan['instant']) }}% / {{ $pct(1 - $plan['instant']) }}%">
                    <div style="flex: {{ $plan['instant'] }}"><b>{{ $pct($plan['instant']) }}%</b><span>{{ __('rewards.payout.instant') }}</span></div>
                    <div style="flex: {{ 1 - $plan['instant'] }}"><b>{{ $pct(1 - $plan['instant']) }}%</b><span>{{ __('rewards.payout.later') }} · {{ __('rewards.payout.later_from', ['date' => Launch::date('end')]) }}</span></div>
                </div>
                <p class="plan-note">{!! $icon !!}<span>{{ __('rewards.payout.keys') }}</span></p>
            </div>
        </div>
    </section>

    {{-- ═════════ FAQ ═════════ --}}
    <section class="section">
        <div class="wrap">
            <div class="section-head reveal"><p class="kicker">{{ __('rewards.faq.kicker') }}</p><h2 class="h2">{{ __('rewards.faq.title') }}</h2></div>
            <div class="faq reveal">
                @foreach (__('rewards.faq.items', $faqArgs) as $i => [$q, $a])
                    <details @if ($i === 0) open @endif><summary>{{ $q }}</summary><p>{{ $a }}</p></details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ═════════ RISK ═════════ --}}
    <section class="section">
        <div class="wrap">
            <div class="risk reveal" data-testid="rewards-risk">
                <svg viewBox="0 0 24 24"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                <div><h3>{{ __('grid.risk.title') }}</h3><p>{{ __('grid.risk.text') }}</p></div>
            </div>
            <div class="rw-cta rw-cta--end">
                @if ($open)
                    <a class="btn btn-primary" href="{{ lroute('grid') }}">{{ __('rewards.cta_open') }}</a>
                @else
                    <a class="btn btn-primary" href="{{ lroute('presale') }}">{{ __('rewards.cta_presale') }}</a>
                @endif
                <a class="btn btn-ghost" href="{{ lroute('whitepaper') }}">{{ __('site.nav.whitepaper') }}</a>
            </div>
        </div>
    </section>
@endsection

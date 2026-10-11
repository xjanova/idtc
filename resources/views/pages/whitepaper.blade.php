@extends('layouts.site')

@php
    $num = fn (string $n) => $n !== '' ? str_pad($n, 2, '0', STR_PAD_LEFT) : '—';
@endphp

@push('preload')
    <link rel="preload" as="image" href="{{ asset('images/art/cover.webp') }}" media="(min-width: 761px)">
@endpush

@section('content')
    @if ($doc)
        <header class="wp-hero">
            <div class="wrap wp-hero-in">
                <div>
                    <div class="wp-brandline">
                        <img src="{{ asset('images/brand/idtc-128.png') }}" alt="" width="52" height="52">
                        <p class="kicker">{{ __('whitepaper.kicker') }}</p>
                    </div>
                    <h1>{{ $doc['title'] }}</h1>
                    <p class="lead">{{ __('whitepaper.lead') }}</p>
                    <div class="cta-row">
                        <button class="btn btn-primary" type="button" onclick="window.print()">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><path d="M6 14h12v7H6z"/></svg>{{ __('whitepaper.print') }}
                        </button>
                        <a class="btn btn-ghost" href="{{ lroute('transparency') }}">{{ __('whitepaper.contracts') }}</a>
                    </div>
                </div>
                <div class="wp-cover">
                    <img src="{{ asset('images/art/cover.webp') }}" alt="{{ __('site.illus_alt', ['what' => __('whitepaper.cover_alt')]) }}" width="800" height="1200">
                    <div class="t"><img src="{{ asset('images/brand/idtc-128.png') }}" alt="" width="46" height="46"><b>IDTC<br>Whitepaper</b><span>{{ __('home.wp.version') }}</span></div>
                    <span class="tag-illus">{{ __('site.illus') }}</span>
                </div>
            </div>
        </header>

        <div class="wrap wp-layout" x-data="wpToc">
            <aside class="wp-toc" aria-label="{{ __('whitepaper.toc') }}">
                <p class="wp-toc-title">{{ __('whitepaper.toc') }}</p>
                <ol>
                    @foreach ($doc['toc'] as $item)
                        <li><a href="#{{ $item['id'] }}" :class="{ 'is-on': active === '{{ $item['id'] }}' }" :aria-current="active === '{{ $item['id'] }}' ? 'true' : null"><b>{{ $num($item['num']) }}</b><span>{{ $item['text'] }}</span></a></li>
                    @endforeach
                </ol>
            </aside>

            <details class="wp-toc-m">
                <summary>{{ __('whitepaper.toc') }} <span class="muted-s" x-text="active ? '· ' + (document.getElementById(active)?.textContent || '') : ''"></span></summary>
                <ol>
                    @foreach ($doc['toc'] as $item)
                        <li><a href="#{{ $item['id'] }}" @click="$el.closest('details').open = false" :class="{ 'is-on': active === '{{ $item['id'] }}' }"><b>{{ $num($item['num']) }}</b><span>{{ $item['text'] }}</span></a></li>
                    @endforeach
                </ol>
            </details>

            <article class="wp-body" lang="{{ \App\Support\SiteLocale::htmlLang() }}">
                {!! $doc['html'] !!}
            </article>
        </div>
    @else
        <div class="page-head">
            <div class="wrap">
                <p class="kicker">{{ __('whitepaper.missing.kicker') }}</p>
                <h1>{!! __('whitepaper.missing.h1') !!}</h1>
                <p class="lead">{{ __('whitepaper.missing.lead') }}</p>
            </div>
        </div>
        <div class="wrap" style="padding-block: 32px 90px">
            <div class="panel wp-missing" data-testid="whitepaper-missing">
                <h3>{{ __('whitepaper.missing.title') }}</h3>
                <p class="muted-p">{{ __('whitepaper.missing.text') }}</p>
                <div class="row-btns"><a class="btn btn-primary btn-sm" href="{{ lroute('transparency') }}">{{ __('whitepaper.contracts') }}</a><a class="btn btn-ghost btn-sm" href="{{ lroute('home') }}#tokenomics">Tokenomics</a></div>
            </div>
        </div>
    @endif
@endsection

@extends('layouts.site')

@php
    use App\Support\ContractList;
    use App\Support\Idtc;
    use App\Support\SiteLocale;
    use App\Support\Tokenomics;

    $vestAddr = Idtc::contract('vesting');
    $live = $vestAddr !== null;
    $period = (int) config('idtc.vesting.period_seconds');
    $tranches = Tokenomics::tranches();
    $plan = ['period' => $period, 'tranches' => $tranches];
    $total = array_sum(array_map(fn ($t) => (float) $t['amount'], $tranches));
    $cells = max(60, ...array_map(fn ($t) => (int) $t['cliff'] + (int) $t['release'], $tranches ?: [['cliff' => 0, 'release' => 0]]));
    $years = (int) ceil($cells / 12);
    $first = collect($tranches)->sortBy('cliff')->first();
    $unit = __('site.big.unit').' IDTC';
    $rows = ContractList::rows();
    $explorerLink = '<a href="'.e(config('idtc.chain.explorer')).'" target="_blank" rel="noopener">explorer.tpix.online</a>';
@endphp

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">{{ __('transparency.title') }}</p>
            <h1>{!! __('transparency.h1') !!}</h1>
            <p class="lead">{{ __('transparency.lead') }}</p>
        </div>
    </div>

    <div class="wrap lock-page" x-data="lockDashboard(@js($plan))" data-vesting-state="{{ $live ? 'live' : 'pending-deploy' }}">
        @unless ($live)
            <div class="plan-banner" role="note">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <div>
                    <b>{{ __('transparency.banner_title') }}</b>
                    <p>{{ __('transparency.banner_text') }}</p>
                </div>
            </div>
        @endunless
        <p class="plan-note warn" x-show="loadError" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span><span x-text="loadError"></span> · <button type="button" class="link-btn" @click="load()">{{ __('site.retry') }}</button></span></p>
        <p class="plan-note warn" x-show="tokenMismatch" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span>{{ __('transparency.token_mismatch') }}</span></p>

        {{-- ═════════ ยอดรวม ═════════ --}}
        <section class="kpis" aria-label="{{ __('transparency.kpi.aria') }}">
            <div class="kpi-tile">
                <span>{{ __('transparency.kpi.scheduled') }} {{ $live ? '' : __('transparency.kpi.plan') }}</span>
                <b><span x-text="million(total)">{{ SiteLocale::bigValue($total) }}</span> <small>{{ $unit }}</small></b>
                <em class="mono" x-text="full(total, 0) + ' IDTC'">{{ number_format($total) }} IDTC</em>
            </div>
            <div class="kpi-tile">
                <span>{{ __('transparency.kpi.released') }}</span>
                <b><span x-text="million(released)">0</span> <small>{{ $unit }}</small></b>
                @php $firstPlan = __('transparency.kpi.first_plan', ['n' => $first ? $first['cliff'] + 1 : '—']); @endphp
                <em x-text="live ? $t('lock.pct_released', { pct: pctReleased.toFixed(2) }) : deployed ? '…' : @js($firstPlan)">{{ $firstPlan }}</em>
            </div>
            <div class="kpi-tile">
                <span>{{ __('transparency.kpi.locked') }}</span>
                <b><span x-text="million(locked)">{{ SiteLocale::bigValue($total) }}</span> <small>{{ $unit }}</small></b>
                <em>{{ __('transparency.kpi.locked_note') }}</em>
            </div>
            <div class="kpi-tile">
                <span>{{ __('transparency.kpi.balance') }}</span>
                <b x-show="deployed"><span x-text="million(balance)">…</span> <small>{{ $unit }}</small></b>
                <b x-show="!deployed" class="kpi-wait">{{ __('site.pending') }}</b>
                <em>
                    <span class="pill-ok" x-show="funded === true" x-cloak>{{ __('transparency.kpi.funded') }}</span>
                    <span class="pill-warn" x-show="funded === false" x-cloak>{{ __('transparency.kpi.underfunded') }}</span>
                    @if ($live)
                        <span class="pill-wait" x-show="funded === null">{{ __('transparency.kpi.checking') }}</span>
                    @else
                        {{ __('transparency.kpi.balance_pending') }}
                    @endif
                </em>
            </div>
        </section>

        <section class="panel lock-meta" aria-label="{{ __('transparency.meta.aria') }}">
            <div>
                <span>{{ __('transparency.meta.contract') }}</span>
                @if ($live)
                    <a class="addr" href="{{ Idtc::explorerAddress($vestAddr) }}" target="_blank" rel="noopener">{{ $vestAddr }} ↗</a>
                @else
                    <b><span class="pill-wait">{{ __('site.pending') }}</span></b>
                @endif
            </div>
            <div>
                <span>{{ __('transparency.meta.beneficiary') }}</span>
                <a class="addr" x-show="beneficiary" x-cloak :href="beneficiary ? explorerAddress(beneficiary) : '#'" target="_blank" rel="noopener" x-text="beneficiary ? beneficiary + ' ↗' : ''"></a>
                <b x-show="!beneficiary">{{ $live ? '…' : __('transparency.meta.set_at_deploy') }}</b>
            </div>
            <div>
                <span>{{ __('transparency.meta.start') }}</span>
                <b x-text="live ? localDate(start, true) : @js(__('transparency.meta.start_plan'))">{{ $live ? '…' : __('transparency.meta.start_plan') }}</b>
            </div>
            <div>
                <span>{{ __('transparency.meta.period') }}</span>
                <b><span class="num" x-text="period.toLocaleString('en-US')">{{ number_format($period) }}</span> {{ __('transparency.meta.period_suffix') }}</b>
            </div>
        </section>

        {{-- ═════════ งวดถัดไป + ปุ่มปลด ═════════ --}}
        <section class="panel next-panel" aria-label="{{ __('transparency.next.aria') }}">
            <div class="next-info">
                <p class="kicker">{{ __('transparency.next.kicker') }}</p>
                <h3 x-text="nextLabel">@if ($first){{ __('js.lock.next_plan', ['name' => $first['name'], 'n' => $first['cliff'] + 1]) }}@endif</h3>
                <p class="muted-p" x-show="!live || nextAt">{{ __('transparency.next.release_pre') }} <b class="num" x-text="full(live ? nextAmount : nextAmountPlan, 0)">{{ $first ? number_format($first['amount'] / $first['release']) : '—' }}</b> {{ __('transparency.next.release_post') }}</p>
                <p class="muted-s" x-show="live && nextAt" x-cloak x-text="$t('lock.due_at', { date: localDate(nextAt, true) })"></p>
            </div>
            <div>
                <div class="countdown" aria-label="{{ __('transparency.next.countdown') }}">
                    <div><b x-text="cd ? cd.d.toLocaleString('en-US') : '—'">—</b><span>{{ __('transparency.next.days') }}</span></div>
                    <div><b x-text="cd ? pad(cd.h) : '—'">—</b><span>{{ __('transparency.next.hours') }}</span></div>
                    <div><b x-text="cd ? pad(cd.m) : '—'">—</b><span>{{ __('transparency.next.minutes') }}</span></div>
                    <div><b x-text="cd ? pad(cd.s) : '—'">—</b><span>{{ __('transparency.next.seconds') }}</span></div>
                </div>
                @unless ($live)
                    <p class="muted-s cd-note">{{ __('transparency.next.countdown_pending') }}</p>
                @endunless
            </div>

            @if ($live)
                <div class="release-box" x-cloak>
                    <div class="release-row">
                        <div>
                            <span class="muted-s">{{ __('transparency.release.due') }}</span>
                            <b class="num release-amt"><span x-text="full(releasable)">…</span> IDTC</b>
                        </div>
                        <button class="btn btn-primary" type="button" @click="askRelease()" :disabled="!canRelease || confirming">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2"/></svg>{{ __('transparency.release.button') }}
                        </button>
                    </div>
                    <p class="muted-s" x-show="loaded && releasable === 0n && !success">{{ __('transparency.release.nothing') }}</p>

                    <div class="confirm-box" x-show="confirming" x-cloak role="group" aria-label="{{ __('transparency.release.confirm_aria') }}">
                        <p>{{ __('transparency.release.confirm_1') }} <code>release()</code> {{ __('transparency.release.confirm_2') }} <b class="num" x-text="full(releasable)"></b> {{ __('transparency.release.confirm_3') }} <a :href="beneficiary ? explorerAddress(beneficiary) : '#'" target="_blank" rel="noopener" class="mono" x-text="short(beneficiary)"></a> {{ __('transparency.release.confirm_4') }}</p>
                        <div class="row-btns">
                            <button class="btn btn-primary btn-sm" type="button" @click="release()" :disabled="busy">{{ __('transparency.release.confirm') }}</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="cancelRelease()" :disabled="busy && phase === 'wait'">{{ __('site.cancel') }}</button>
                        </div>
                        <div class="flow-line" x-show="busy && phaseText" role="status">
                            <span class="spin" aria-hidden="true"></span><span x-text="phaseText"></span>
                            <a x-show="errorTx && phase === 'wait'" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">{{ __('site.explorer_link') }}</a>
                        </div>
                    </div>

                    <div class="flow-msg err" x-show="error && !busy" x-cloak role="alert">
                        <span x-text="error"></span>
                        <a x-show="errorTx" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">{{ __('site.explorer_tx') }}</a>
                    </div>
                    <div class="success" x-show="success" x-cloak role="status">
                        <span class="success-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></span>
                        <div>
                            <b>{{ __('transparency.release.done') }}</b>
                            <p><span class="num" x-text="success && success.amount !== null ? full(success.amount) + ' IDTC' : $t('lock.tokens')"></span> {{ __('transparency.release.done_to') }}</p>
                            <div class="row-btns"><a class="btn btn-ghost btn-sm" :href="success ? explorerTx(success.hash) : '#'" target="_blank" rel="noopener">{{ __('site.view_tx') }}</a></div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        {{-- ═════════ 4 ก้อนล็อก ═════════ --}}
        <div class="section-mini">
            <h2 class="h3">{{ __('transparency.schedule.title') }}</h2>
            <div class="legend-row">
                <span><i class="lg lg-lock"></i>{{ __('transparency.schedule.lock') }}</span>
                <span><i class="lg lg-rel"></i>{{ __('transparency.schedule.rel') }}</span>
                <span><i class="lg lg-due"></i>{{ __('transparency.schedule.due') }}</span>
                <span x-show="live" x-cloak><i class="lg lg-now"></i>{{ __('transparency.schedule.now') }}</span>
            </div>
        </div>

        @unless ($live)
            <section class="lock-grid" aria-label="{{ __('transparency.schedule.plan_aria') }}">
                @foreach ($tranches as $t)
                    <article class="tranche">
                        <div class="tranche-top"><h3>{{ $t['name'] }}</h3><b>{{ SiteLocale::bigValue($t['amount']) }} <small>{{ $unit }}</small></b></div>
                        <div class="meta"><span>{{ __('transparency.schedule.lock_line', ['cliff' => $t['cliff'], 'release' => $t['release']]) }}</span><span>{{ __('transparency.schedule.first', ['n' => $t['cliff'] + 1]) }}</span></div>
                        <div class="timeline" style="--cells: {{ $cells }}" role="img" aria-label="{{ __('transparency.schedule.timeline', ['cells' => $cells, 'cliff' => $t['cliff'], 'release' => $t['release']]) }}">
                            @for ($m = 0; $m < $cells; $m++)<i class="{{ $m < $t['cliff'] ? 'lock' : ($m < $t['cliff'] + $t['release'] ? 'due' : '') }}"></i>@endfor
                        </div>
                        <div class="tl-axis" style="--years: {{ $years }}">@for ($y = 1; $y <= $years; $y++)<span>{{ __('transparency.schedule.year', ['n' => $y]) }}</span>@endfor</div>
                        <div class="meta"><span>{{ __('transparency.schedule.last', ['n' => $t['cliff'] + $t['release']]) }}</span><span class="num">{{ __('transparency.schedule.per', ['v' => number_format($t['amount'] / $t['release'], 2)]) }}</span></div>
                    </article>
                @endforeach
            </section>
        @else
            <section class="lock-grid" aria-label="{{ __('transparency.schedule.live_aria') }}">
                <template x-for="(t, i) in tranches" :key="i">
                    <article class="tranche">
                        <div class="tranche-top"><h3 x-text="t.name"></h3><b><span x-text="million(t.amount)"></span> <small>{{ $unit }}</small></b></div>
                        <div class="meta"><span x-text="$t('lock.vested', { n: vestedCount(t), total: t.release })"></span><span x-text="$t('lock.first', { when: firstUnlock(t) })"></span></div>
                        <div class="timeline" :style="`--cells:${cells}`" x-html="cellsFor(t)" role="img" :aria-label="$t('lock.timeline', { cells, cliff: t.cliff, done: vestedCount(t), total: t.release })"></div>
                        <div class="tl-axis" :style="`--years:${Math.ceil(cells / 12)}`"><template x-for="y in Math.ceil(cells / 12)" :key="y"><span x-text="$t('lock.year', { n: y })"></span></template></div>
                        <div class="meta"><span x-text="$t('lock.last', { when: lastUnlock(t) })"></span><span class="num" x-text="$t('lock.per', { v: full(perInstallment(t)) })"></span></div>
                    </article>
                </template>
            </section>
        @endif

        {{-- ═════════ สัญญาทั้งหมด ═════════ --}}
        <section class="panel contracts-panel" aria-label="{{ __('transparency.contracts.aria') }}">
            <h3>{{ __('transparency.contracts.title') }} <span class="muted-s">{{ __('transparency.contracts.verified_note') }}</span></h3>
            <div class="table-scroll">
                <table class="contracts">
                    <thead><tr><th>{{ __('transparency.contracts.col_contract') }}</th><th>{{ __('transparency.contracts.col_role') }}</th><th>{{ __('transparency.contracts.col_address') }}</th><th>{{ __('transparency.contracts.col_status') }}</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $c)
                            <tr data-contract="{{ $c['key'] }}">
                                <td data-label="{{ __('transparency.contracts.col_contract') }}"><b>{{ $c['name'] }}</b><small class="mono">{{ $c['kind'] }}</small></td>
                                <td data-label="{{ __('transparency.contracts.col_role') }}">{{ $c['role'] }}</td>
                                <td data-label="{{ __('transparency.contracts.col_address') }}">
                                    @if ($c['address'])
                                        <span class="addr-row" x-data="{ copied: false }">
                                            <a class="addr" href="{{ $c['url'] }}" target="_blank" rel="noopener">{{ $c['address'] }}</a>
                                            <button type="button" class="copy-btn" @click="navigator.clipboard?.writeText('{{ $c['address'] }}').then(() => { copied = true; setTimeout(() => copied = false, 1500) })" :aria-label="copied ? @js(__('transparency.contracts.copied')) : @js(__('transparency.contracts.copy'))" title="{{ __('transparency.contracts.copy') }}">
                                                <svg x-show="!copied" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                                <svg x-show="copied" x-cloak viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                            </button>
                                        </span>
                                    @else
                                        <span class="addr">{{ __('site.pending') }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('transparency.contracts.col_status') }}" @class(['is-pending' => ! $c['address']])>
                                    @if ($c['address'])
                                        <span x-data="verifyBadge('{{ $c['address'] }}')"><a href="{{ $c['url'] }}" target="_blank" rel="noopener" class="vpill" data-v="unknown" :data-v="state" x-text="label">{{ __('js.verify.unknown') }}</a></span>
                                    @else
                                        <span class="pill-wait">{{ __('site.pending') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted-s" style="margin-top:14px">{!! __('transparency.contracts.foot', ['explorer' => $explorerLink, 'id' => e(config('idtc.chain.id'))]) !!}</p>
        </section>
    </div>
@endsection

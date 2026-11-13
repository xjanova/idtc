@extends('layouts.site')

@php
    use App\Support\SiteLocale;
    // ตัวเลขทุกตัวมาจากแผนที่ตั้งในหลังบ้าน ($plan, $totals) — ไม่พิมพ์ตัวเลขลงข้อความ
    $pct = fn ($v) => rtrim(rtrim(number_format($v * 100, 2), '0'), '.');
    $num = fn ($v) => number_format((float) $v);
    $restPct = max(0, 1 - $plan['pool']);
    $soldPct = $totals['quota'] > 0 ? min(100, $totals['idtc'] / $totals['quota'] * 100) : 0;
    $binJs = [
        'ref' => $ref,
        'side' => $side,
        'routes' => [
            'me' => SiteLocale::route('binary.me'),
            'nonce' => SiteLocale::route('binary.nonce'),
            'verify' => SiteLocale::route('binary.verify'),
            'logout' => SiteLocale::route('binary.logout'),
            'side' => SiteLocale::route('binary.side'),
        ],
        'csrf' => csrf_token(),
    ];
    $statuses = __('binary.history');
    $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/></svg>';
@endphp

@section('content')
    <div class="page-head bin-head">
        <div class="wrap">
            <p class="kicker">{{ __('binary.kicker') }}</p>
            <h1>{!! __('binary.h1') !!}</h1>
            <p class="lead">{{ __('binary.lead') }}</p>
            <p class="bin-badges">
                <span class="bin-badge">{{ __('binary.badge') }}</span>
                @if ($ends)<span class="bin-badge alt">{{ __('binary.ends', ['date' => $ends->setTimezone('Asia/Bangkok')->locale(SiteLocale::current())->isoFormat('D MMM YYYY')]) }}</span>@endif
            </p>
        </div>
    </div>

    <div class="wrap grid-page bin-page" x-data="binaryMember(@js($binJs))">
        @if ($preview)
            <p class="plan-note warn" data-testid="binary-preview">{!! $icon !!}<span>{{ __('binary.preview') }}</span></p>
        @endif
        @unless ($ready)
            <p class="plan-note warn" data-testid="binary-not-ready">{!! $icon !!}<span>{{ __('binary.not_ready') }}</span></p>
        @endunless

        {{-- ═════════ สมาชิก ═════════ --}}
        <section class="panel grid-me" aria-label="{{ __('binary.me.aria') }}">
            <p class="plan-note warn" x-show="error" x-cloak role="alert">{!! $icon !!}<span x-text="error"></span></p>
            <p class="plan-note" x-show="phase" x-cloak role="status">{!! $icon !!}<span x-text="phase"></span></p>

            {{-- ยังไม่เข้าระบบ --}}
            <div x-show="!me" class="grid-connect">
                <template x-if="ref && !refIsMe">
                    <div class="grid-invite">
                        <span>{{ __('binary.me.invited') }}</span>
                        <b class="mono" x-text="ref"></b>
                        <p>{{ __('binary.me.bind_text') }}</p>
                        @if ($side)<p class="muted small">{{ __($side === 'L' ? 'binary.me.side_left' : 'binary.me.side_right') }}</p>@endif
                    </div>
                </template>
                <h3>{{ __('binary.me.connect_title') }}</h3>
                <p class="muted">{{ __('binary.me.connect_text') }}</p>
                <div class="grid-actions">
                    <template x-if="ref && !refIsMe">
                        <button class="btn btn-primary" type="button" @click="signIn('bind')" :disabled="busy">{{ __('binary.me.bind') }}</button>
                    </template>
                    <button class="btn" :class="ref && !refIsMe ? 'btn-ghost' : 'btn-primary'" type="button" @click="signIn('login')" :disabled="busy">
                        <span x-text="ref && !refIsMe ? @js(__('binary.me.skip')) : @js(__('binary.me.connect'))">{{ __('binary.me.connect') }}</span>
                    </button>
                </div>
            </div>

            {{-- เข้าระบบแล้ว --}}
            <template x-if="me">
                <div class="grid-dash" data-testid="binary-dash">
                    <div class="grid-dash-head">
                        <div>
                            <span class="muted">{{ __('binary.me.wallet') }}</span>
                            <b class="mono" x-text="short(me.address)"></b>
                            <span class="muted small">{{ __('binary.me.referrer') }}: <span class="mono" x-text="me.referrer ? short(me.referrer) : @js(__('binary.me.no_referrer'))"></span></span>
                        </div>
                        <button class="btn btn-ghost btn-sm" type="button" @click="logout()">{{ __('binary.me.logout') }}</button>
                    </div>

                    <template x-if="me.can_bind && ref && !refIsMe">
                        <div class="grid-invite">
                            <span>{{ __('binary.me.invited') }}</span>
                            <b class="mono" x-text="ref"></b>
                            <p>{{ __('binary.me.bind_text') }}</p>
                            <button class="btn btn-primary btn-sm" type="button" @click="signIn('bind')" :disabled="busy">{{ __('binary.me.bind') }}</button>
                        </div>
                    </template>
                    <p class="plan-note warn" x-show="me.held" x-cloak>{!! $icon !!}<span>{{ __('binary.me.held') }}</span></p>

                    <div class="kpis grid-kpis">
                        <div class="kpi-tile"><span>{{ __('binary.me.kpi_usdt') }}</span><b class="num" x-text="usd(me.mine.usdt)"></b><em>USDT</em></div>
                        <div class="kpi-tile"><span>{{ __('binary.me.kpi_idtc') }}</span><b class="num" x-text="idtc(me.mine.idtc)"></b><em>IDTC</em></div>
                        <div class="kpi-tile"><span>{{ __('binary.me.kpi_ready') }}</span><b class="num" x-text="usd(me.mine.ready)"></b><em>USDT</em></div>
                        <div class="kpi-tile"><span>{{ __('binary.me.kpi_wait') }}</span><b class="num" x-text="usd(me.mine.wait)"></b><em>USDT · <span x-text="endsIn()"></span></em></div>
                    </div>
                    <p class="muted small">{{ __('binary.me.payout_note') }}</p>

                    {{-- ฝาก USDT --}}
                    <div class="grid-box bin-deposit" data-testid="binary-deposit">
                        <h4>{{ __('binary.deposit.title') }}</h4>
                        <ol class="bin-steps">
                            <li>{{ __('binary.deposit.step1') }}</li>
                            <li>{{ __('binary.deposit.step2') }}</li>
                            <li>{{ __('binary.deposit.step3') }}</li>
                        </ol>
                        <p class="plan-note warn" x-show="!me.open" x-cloak>{!! $icon !!}<span>{{ __('binary.deposit.closed') }}</span></p>
                        <div x-show="me.open">
                            <label class="grid-link">
                                <span>{{ __('binary.deposit.to') }}</span>
                                <span class="grid-link-row">
                                    <input class="mono" type="text" readonly :value="me.wallet" @focus="$event.target.select()">
                                    <button class="btn btn-ghost btn-sm" type="button" @click="copyText(me.wallet, 'wallet')" x-text="copiedKey === 'wallet' ? @js(__('binary.me.copied')) : @js(__('binary.me.copy'))">{{ __('binary.me.copy') }}</button>
                                </span>
                            </label>
                            <div class="bin-amount">
                                <label class="grid-link">
                                    <span>{{ __('binary.deposit.amount') }} · <span x-text="@js(__('binary.deposit.min', ['min' => '__M__'])).replace('__M__', num(me.plan.min_usdt))"></span></span>
                                    <input class="bin-input num" type="text" inputmode="decimal" autocomplete="off" x-model.trim="amount" :disabled="depositing" placeholder="0.00">
                                </label>
                                <div class="kv">
                                    <div><span>{{ __('binary.deposit.balance') }}</span><b class="num" x-text="bal() + ' USDT'"></b></div>
                                    <div><span>{{ __('binary.deposit.get') }}</span><b class="num" x-text="idtc(willGet) + ' IDTC'"></b></div>
                                    <div><span x-text="@js(__('binary.deposit.price', ['price' => '__P__'])).replace('__P__', me.plan.price)"></span><b></b></div>
                                </div>
                            </div>
                            <label class="bin-agree"><input type="checkbox" x-model="agree" :disabled="depositing"><span>{{ __('binary.deposit.agree') }}</span></label>
                            <div class="grid-claim-act">
                                <button class="btn btn-primary" type="button" @click="deposit()" :disabled="depositing || busy || !!depositBlock">{{ __('binary.deposit.button') }}</button>
                                <span class="muted small" x-show="depositBlock.trim()" x-text="depositBlock"></span>
                            </div>
                            <p class="plan-note" x-show="depMsg" x-cloak role="status">{!! $icon !!}<span x-text="depMsg"></span></p>
                            <p class="plan-note warn" x-show="depErr" x-cloak role="alert">{!! $icon !!}<span x-text="depErr"></span></p>
                            <p class="muted small" x-show="depHash" x-cloak><a :href="explorerTx(depHash)" target="_blank" rel="noopener">{{ __('binary.deposit.tx') }} ↗</a></p>
                        </div>
                        <ul class="bin-warn">
                            <li>{{ __('binary.deposit.warn_wallet') }}</li>
                            <li>{{ __('binary.deposit.warn_chain') }}</li>
                            <li>{{ __('binary.deposit.warn_refund') }}</li>
                        </ul>
                    </div>

                    {{-- สองขา --}}
                    <div class="grid-box grid-bin bin-legs" data-testid="binary-legs">
                        <h4>{{ __('binary.legs.title') }}</h4>
                        <div class="bin-balance" role="img" aria-label="{{ __('binary.legs.balance') }}">
                            <span class="l" :style="`width:${leftPct}%`"><b x-text="usd(me.mine.left.usdt)"></b></span>
                            <span class="r" :style="`width:${100 - leftPct}%`"><b x-text="usd(me.mine.right.usdt)"></b></span>
                        </div>
                        <div class="grid-two">
                            @foreach (['left', 'right'] as $leg)
                                <div class="grid-leg bin-leg-{{ $leg }}">
                                    <h5>{{ __('binary.legs.'.$leg) }} <em class="num" x-text="num(me.mine.{{ $leg }}.count)"></em> <span class="muted small">{{ __('binary.legs.members') }}</span></h5>
                                    <div class="kv">
                                        <div><span>{{ __('binary.legs.volume') }}</span><b class="num" x-text="usd(me.mine.{{ $leg }}.usdt) + ' USDT'"></b></div>
                                        <div><span>{{ __('binary.legs.carry') }}</span><b class="num" x-text="usd(me.mine.{{ $leg }}.carry) + ' USDT'"></b></div>
                                        <div><span>{{ __('binary.legs.directs') }}</span><b class="num" x-text="num(me.mine.{{ $leg }}.directs)"></b></div>
                                    </div>
                                    <label class="grid-link">
                                        <span>{{ __('binary.legs.link_'.$leg) }}</span>
                                        <span class="grid-link-row">
                                            <input class="mono" type="text" readonly :value="me.links.{{ $leg }}" @focus="$event.target.select()">
                                            <button class="btn btn-ghost btn-sm" type="button" @click="copyText(me.links.{{ $leg }}, '{{ $leg }}')" x-text="copiedKey === '{{ $leg }}' ? @js(__('binary.me.copied')) : @js(__('binary.me.copy'))">{{ __('binary.me.copy') }}</button>
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        <div class="kv">
                            <div><span>{{ __('binary.me.position') }}</span><b><template x-if="me.mine.leg"><span><span x-text="me.mine.leg === 'L' ? @js(__('binary.legs.left')) : @js(__('binary.legs.right'))"></span> {{ __('binary.me.under') }} <span class="mono" x-text="me.mine.parent"></span></span></template><template x-if="!me.mine.leg"><span>{{ __('binary.me.top') }}</span></template></b></div>
                        </div>
                        <p class="muted small" x-show="me.mine.qualified">{{ __('binary.legs.qualified') }}</p>
                        <p class="muted small" x-show="!me.mine.qualified" x-text="@js(__('binary.legs.need', ['n' => '__N__'])).replace('__N__', me.plan.directs)"></p>
                        <p class="muted small" x-show="me.week.mine && me.week.mine.capped">{{ __('binary.legs.capped') }}</p>
                        <label class="grid-link">
                            <span>{{ __('binary.legs.pref') }}</span>
                            <select class="grid-select" @change="setSide($event.target.value)" :disabled="savingSide">
                                <option value="" :selected="!me.pref">{{ __('binary.legs.pref_auto') }}</option>
                                <option value="L" :selected="me.pref === 'L'">{{ __('binary.legs.left') }}</option>
                                <option value="R" :selected="me.pref === 'R'">{{ __('binary.legs.right') }}</option>
                            </select>
                            <span class="muted small">{{ __('binary.legs.pref_note') }}</span>
                        </label>
                    </div>

                    <h4 class="grid-h">{{ __('binary.history.title') }}</h4>
                    <p class="muted" x-show="!me.deposits.length">{{ __('binary.history.empty') }}</p>
                    <div class="table-scroll" x-show="me.deposits.length">
                        <table class="grid-tbl">
                            <thead><tr><th>{{ __('binary.history.when') }}</th><th class="r">{{ __('binary.history.usdt') }}</th><th class="r">{{ __('binary.history.idtc') }}</th><th>{{ __('binary.history.status') }}</th></tr></thead>
                            <tbody>
                                <template x-for="(d, i) in me.deposits" :key="d.tx + ':' + i">
                                    <tr>
                                        <td><a :href="explorerTx(d.tx)" target="_blank" rel="noopener" x-text="when(d.at)"></a></td>
                                        <td class="r num" x-text="usd(d.usdt)"></td>
                                        <td class="r num" x-text="d.status === 'ok' ? idtc(d.idtc) : '—'"></td>
                                        <td><span class="grid-status" :data-s="d.status === 'ok' ? 'ready' : (d.status === 'refunded' ? 'wait' : 'held')" x-text="@js($statuses)[d.status] || d.status"></span></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </section>

        {{-- ═════════ แผน ═════════ --}}
        <section class="grid-how bin-how" aria-labelledby="binHow">
            <h2 id="binHow">{{ __('binary.how.title') }}</h2>
            <ol class="bin-flow">
                <li><b>{{ __('binary.how.s1_t') }}</b><span>{{ __('binary.how.s1', ['price' => $plan['price'], 'min' => $num($plan['min_usdt'])]) }}</span></li>
                <li><b>{{ __('binary.how.s2_t') }}</b><span>{{ __('binary.how.s2') }}</span></li>
                <li><b>{{ __('binary.how.s3_t') }}</b><span>{{ __('binary.how.s3', ['pair' => $pct($plan['pair'])]) }}</span></li>
                <li><b>{{ __('binary.how.s4_t') }}</b><span>{{ __('binary.how.s4', ['pool' => $pct($plan['pool'])]) }}</span></li>
            </ol>

            <div class="grid-two" style="margin-top:18px">
                <div class="panel">
                    <h3>{{ __('binary.how.money_title') }}</h3>
                    <div class="bin-split" role="img" aria-label="{{ __('binary.how.money_title') }}">
                        <span class="pool" style="width:{{ $pct($plan['pool']) }}%"></span><span class="rest" style="width:{{ $pct($restPct) }}%"></span>
                    </div>
                    <div class="kv">
                        <div><span><i class="bin-dot pool"></i>{{ __('binary.how.money_pool') }}</span><b class="num">{{ $pct($plan['pool']) }}%</b></div>
                        <div><span><i class="bin-dot rest"></i>{{ __('binary.how.money_rest_'.$rest) }}</span><b class="num">{{ $pct($restPct) }}%</b></div>
                    </div>
                    <p class="muted small">{{ __('binary.how.money_note') }}</p>
                </div>
                <div class="panel">
                    <h3>{{ __('binary.how.facts_title') }}</h3>
                    <div class="kv">
                        <div><span>{{ __('binary.how.price') }}</span><b class="num">{{ $plan['price'] }} USDT</b></div>
                        <div><span>{{ __('binary.how.min') }}</span><b class="num">{{ $num($plan['min_usdt']) }} USDT</b></div>
                        <div><span>{{ __('binary.how.pair') }}</span><b class="num">{{ $pct($plan['pair']) }}% <span class="muted small">{{ __('binary.how.pair_of') }}</span></b></div>
                        <div><span>{{ __('binary.how.cap') }}</span><b class="num">{{ $pct($plan['cap']) }}% <span class="muted small">{{ __('binary.how.cap_of') }}</span></b></div>
                        <div><span>{{ __('binary.how.directs') }}</span><b class="num">{{ $plan['directs'] }} {{ __('binary.how.people') }}</b></div>
                        @if ($totals['quota'] > 0)
                            <div><span>{{ __('binary.how.quota') }}</span><b class="num">{{ $num($totals['quota']) }} IDTC</b></div>
                            <div><span>{{ __('binary.how.sold') }}</span><b class="num">{{ $num($totals['idtc']) }} IDTC · {{ number_format($soldPct, 1) }}%</b></div>
                        @endif
                        @if ($source)<div><span>{{ __('binary.how.source') }}</span><b>{{ __('plan.alloc.'.$source['key'].'.name') }}</b></div>@endif
                        @if ($wallet)<div><span>{{ __('binary.how.wallet') }}</span><b class="mono bin-addr">{{ $wallet }}</b></div>@endif
                    </div>
                </div>
            </div>

            <div class="panel" style="margin-top:18px">
                <h3>{{ __('binary.how.rules_title') }}</h3>
                <ul class="grid-rules">
                    @foreach (['separate', 'buy', 'bind', 'week', 'qualify', 'payout'] as $r)
                        <li>{{ __('binary.how.rule_'.$r) }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="plan-note warn grid-risk" role="note">{!! $icon !!}<span><b>{{ __('binary.risk.title') }}</b> {{ __('binary.risk.text') }}</span></div>
        </section>
    </div>
@endsection

@extends('layouts.site')

@php
    use App\Support\SiteLocale;
    // ตัวเลขทุกตัวมาจากแผนที่ใช้อยู่ ($plan) — ไม่พิมพ์ตัวเลขลงข้อความ
    $pct = fn ($v) => rtrim(rtrim(number_format($v * 100, 2), '0'), '.');
    $r = $plan['rank'];
    $boost = implode(' · ', array_map(fn ($b) => __('grid.how.boost_step', ['from' => $b['from'] + 1, 'to' => $b['to'], 'add' => $pct($b['add'])]), $plan['boost']));
    $ranks = [
        'spark' => __('grid.rank_req.spark', ['own' => number_format($r['spark_own'])]),
        'cell' => __('grid.rank_req.cell', ['d' => $r['cell_d']]),
        'grid' => __('grid.rank_req.grid', ['d' => $r['grid_d'], 't' => number_format($r['grid_t'])]),
        'core' => __('grid.rank_req.core', ['d' => $r['core_d'], 't' => number_format($r['core_t'])]),
    ];
    $gridJs = [
        'ref' => $ref,
        'side' => $side,
        'routes' => [
            'me' => SiteLocale::route('grid.me'),
            'nonce' => SiteLocale::route('grid.nonce'),
            'verify' => SiteLocale::route('grid.verify'),
            'logout' => SiteLocale::route('grid.logout'),
            'side' => SiteLocale::route('grid.side'),
        ],
        'csrf' => csrf_token(),
        'ranks' => __('grid.ranks'),
    ];
    $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/></svg>';
@endphp

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">{{ __('grid.kicker') }}</p>
            <h1>{!! __('grid.h1') !!}</h1>
            <p class="lead">{{ __('grid.lead') }}</p>
        </div>
    </div>

    <div class="wrap grid-page" x-data="gridMember(@js($gridJs))">
        @if ($preview)
            <p class="plan-note warn" data-testid="grid-preview">{!! $icon !!}<span>{{ __('grid.preview') }}</span></p>
        @endif

        {{-- ═════════ สมาชิก ═════════ --}}
        <section class="panel grid-me" aria-label="{{ __('grid.me.aria') }}">
            <p class="plan-note warn" x-show="error" x-cloak role="alert">{!! $icon !!}<span x-text="error"></span></p>
            <p class="plan-note" x-show="phase" x-cloak role="status">{!! $icon !!}<span x-text="phase"></span></p>

            {{-- ยังไม่เข้าระบบ --}}
            <div x-show="!me" class="grid-connect">
                <template x-if="ref && !refIsMe">
                    <div class="grid-invite">
                        <span>{{ __('grid.me.invited') }}</span>
                        <b class="mono" x-text="ref"></b>
                        <p>{{ __('grid.me.bind_text', ['buyer' => $pct($plan['buyer'])]) }}</p>
                        @if ($side && $plan['binary']['pool'] > 0)<p class="muted small">{{ __($side === 'L' ? 'grid.bin.side_left' : 'grid.bin.side_right') }}</p>@endif
                    </div>
                </template>
                <h3>{{ __('grid.me.connect_title') }}</h3>
                <p class="muted">{{ __('grid.me.connect_text') }}</p>
                <div class="grid-actions">
                    <template x-if="ref && !refIsMe">
                        <button class="btn btn-primary" type="button" @click="signIn('bind')" :disabled="busy">{{ __('grid.me.bind') }}</button>
                    </template>
                    <button class="btn" :class="ref && !refIsMe ? 'btn-ghost' : 'btn-primary'" type="button" @click="signIn('login')" :disabled="busy">
                        <span x-text="ref && !refIsMe ? @js(__('grid.me.skip')) : @js(__('grid.me.connect'))">{{ __('grid.me.connect') }}</span>
                    </button>
                </div>
            </div>

            {{-- เข้าระบบแล้ว --}}
            <template x-if="me">
                <div class="grid-dash">
                    <div class="grid-dash-head">
                        <div>
                            <span class="muted">{{ __('grid.me.rank') }}</span>
                            <b class="grid-rank" :data-rank="me.rank" x-text="ranks[me.rank]"></b>
                            <span class="mono muted" x-text="short(me.address)"></span>
                        </div>
                        <button class="btn btn-ghost btn-sm" type="button" @click="logout()">{{ __('grid.me.logout') }}</button>
                    </div>

                    <template x-if="me.can_bind && ref && !refIsMe">
                        <div class="grid-invite">
                            <span>{{ __('grid.me.invited') }}</span>
                            <b class="mono" x-text="ref"></b>
                            <p>{{ __('grid.me.bind_text', ['buyer' => $pct($plan['buyer'])]) }}</p>
                            <button class="btn btn-primary btn-sm" type="button" @click="signIn('bind')" :disabled="busy">{{ __('grid.me.bind') }}</button>
                        </div>
                    </template>

                    <label class="grid-link">
                        <span>{{ __('grid.me.link') }}</span>
                        <span class="grid-link-row">
                            <input class="mono" type="text" readonly :value="me.link" @focus="$event.target.select()">
                            <button class="btn btn-ghost btn-sm" type="button" @click="copy()" x-text="copied ? @js(__('grid.me.copied')) : @js(__('grid.me.copy'))">{{ __('grid.me.copy') }}</button>
                            <button class="btn btn-ghost btn-sm" type="button" x-show="canShare" @click="share()">{{ __('grid.me.share') }}</button>
                            <button class="btn btn-ghost btn-sm" type="button" data-testid="grid-qr" @click="$store.qr.show(me.link, @js(__('grid.me.link')), 'idtc-grid')">{{ __('site.qr.button') }}</button>
                        </span>
                    </label>

                    <div class="kpis grid-kpis">
                        <div class="kpi-tile"><span>{{ __('grid.me.ready') }}</span><b class="num" x-text="idtc(me.rewards.ready)"></b><em>IDTC</em></div>
                        <div class="kpi-tile"><span x-text="@js(__('grid.me.wait', ['days' => '__D__'])).replace('__D__', me.hold_days)"></span><b class="num" x-text="idtc(me.rewards.wait)"></b><em>IDTC</em></div>
                        <div class="kpi-tile"><span>{{ __('grid.me.team') }}</span><b class="num" x-text="usd(me.team)"></b><em><span x-text="num(me.team_count)"></span> · {{ __('grid.me.team_count') }}</em></div>
                        <div class="kpi-tile"><span>{{ __('grid.me.directs') }}</span><b class="num" x-text="num(me.directs)"></b><em>{{ __('grid.me.own') }} <span x-text="usd(me.own)"></span></em></div>
                    </div>
                    <p class="plan-note warn" x-show="me.rewards.held > 0" x-cloak>{!! $icon !!}<span>{{ __('grid.me.held') }} · <b x-text="idtc(me.rewards.held)"></b> IDTC</span></p>

                    <div class="grid-box grid-claim" x-show="me.claims && me.claims.contract && me.claims.items.length" x-cloak>
                        <h4>{{ __('grid.me.claim_title') }}</h4>
                        <div class="kv">
                            <div><span>{{ __('grid.me.claim_now') }}</span><b class="num" x-text="fmtIdtc(sumIdtc(claimable)) + ' IDTC'"></b></div>
                            <div x-show="claimLocked.length"><span>{{ __('grid.me.claim_locked') }}</span><b class="num" x-text="fmtIdtc(sumIdtc(claimLocked)) + ' IDTC'"></b></div>
                            <div x-show="claimLocked.length"><span>{{ __('grid.me.claim_unlock') }}</span><b x-text="claimLocked.length ? when(claimLocked[0].unlock_at) : ''"></b></div>
                            <div x-show="claimedTotal > 0"><span>{{ __('grid.me.claim_done') }}</span><b class="num" x-text="fmtIdtc(claimedTotal) + ' IDTC'"></b></div>
                        </div>
                        <p class="plan-note warn" x-show="me.claims.error" x-cloak>{!! $icon !!}<span>{{ __('grid.me.claim_read_error') }}</span></p>
                        <div class="grid-claim-act">
                            <button class="btn btn-primary" type="button" @click="claim()" :disabled="claiming || busy || !claimable.length">{{ __('grid.me.claim_button') }}</button>
                            <span class="muted small">{{ __('grid.me.claim_note') }}</span>
                        </div>
                        <p class="plan-note" x-show="claimMsg" x-cloak role="status">{!! $icon !!}<span x-text="claimMsg"></span></p>
                        <p class="plan-note warn" x-show="claimErr" x-cloak role="alert">{!! $icon !!}<span x-text="claimErr"></span></p>
                        <p class="muted small" x-show="claimHash" x-cloak><a :href="explorerTx(claimHash)" target="_blank" rel="noopener">{{ __('grid.me.claim_tx') }} ↗</a></p>
                    </div>

                    {{-- Binary (สองขา) — แสดงเมื่อแผนเปิดกอง Binary --}}
                    <div class="grid-box grid-bin" x-show="me.binary && me.binary.on" x-cloak data-testid="grid-binary">
                        <h4>{{ __('grid.bin.title') }}</h4>
                        <p class="muted small">{{ __('grid.bin.text', ['pair' => $pct($plan['binary']['pair']), 'pool' => $pct($plan['binary']['pool'])]) }}</p>
                        <div class="grid-two">
                            @foreach (['left', 'right'] as $leg)
                                <div class="grid-leg">
                                    <h5>{{ __('grid.bin.'.$leg) }} <em class="num" x-text="num(me.binary.{{ $leg }}.count)"></em> <span class="muted small">{{ __('grid.bin.members') }}</span></h5>
                                    <div class="kv">
                                        <div><span>{{ __('grid.bin.volume') }}</span><b class="num" x-text="idtc(me.binary.{{ $leg }}.idtc) + ' IDTC'"></b></div>
                                        <div><span>{{ __('grid.bin.carry') }}</span><b class="num" x-text="idtc(me.binary.{{ $leg }}.carry) + ' IDTC'"></b></div>
                                        <div><span>{{ __('grid.bin.directs') }}</span><b class="num" x-text="num(me.binary.{{ $leg }}.directs)"></b></div>
                                    </div>
                                    <label class="grid-link">
                                        <span>{{ __('grid.bin.link_'.$leg) }}</span>
                                        <span class="grid-link-row">
                                            <input class="mono" type="text" readonly :value="me.binary.links.{{ $leg }}" @focus="$event.target.select()">
                                            <button class="btn btn-ghost btn-sm" type="button" @click="copyText(me.binary.links.{{ $leg }}, '{{ $leg }}')" x-text="copiedKey === '{{ $leg }}' ? @js(__('grid.me.copied')) : @js(__('grid.me.copy'))">{{ __('grid.me.copy') }}</button>
                                            <button class="btn btn-ghost btn-sm" type="button" data-testid="grid-qr-{{ $leg }}" @click="$store.qr.show(me.binary.links.{{ $leg }}, @js(__('grid.bin.link_'.$leg)), 'idtc-grid-{{ $leg }}')">{{ __('site.qr.button') }}</button>
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        <div class="kv">
                            <div><span>{{ __('grid.bin.week') }}</span><b class="num" x-text="idtc(me.binary.week.mine ? me.binary.week.mine.share : 0) + ' IDTC'"></b></div>
                            <div><span>{{ __('grid.bin.earned') }}</span><b class="num" x-text="idtc(me.binary.earned) + ' IDTC'"></b></div>
                            <div x-show="me.binary.leg"><span>{{ __('grid.bin.placed') }}</span><b><span x-text="me.binary.leg === 'L' ? @js(__('grid.bin.left')) : @js(__('grid.bin.right'))"></span> {{ __('grid.bin.under') }} <span class="mono" x-text="me.binary.parent"></span></b></div>
                        </div>
                        <p class="muted small" x-show="me.binary.week.mine && me.binary.week.mine.capped">{{ __('grid.bin.capped') }}</p>
                        <p class="muted small" x-show="me.binary.qualified">{{ __('grid.bin.qualified') }}</p>
                        <p class="muted small" x-show="!me.binary.qualified" x-text="@js(__('grid.bin.need', ['n' => '__N__'])).replace('__N__', me.binary.need_directs)"></p>
                        <label class="grid-link">
                            <span>{{ __('grid.bin.pref') }}</span>
                            <select class="grid-select" @change="setSide($event.target.value)" :disabled="savingSide">
                                <option value="" :selected="!me.binary.pref">{{ __('grid.bin.pref_auto') }}</option>
                                <option value="L" :selected="me.binary.pref === 'L'">{{ __('grid.bin.left') }}</option>
                                <option value="R" :selected="me.binary.pref === 'R'">{{ __('grid.bin.right') }}</option>
                            </select>
                            <span class="muted small">{{ __('grid.bin.pref_note') }}</span>
                        </label>
                    </div>

                    <div class="grid-two">
                        <div class="grid-box">
                            <h4>{{ __('grid.me.next') }}</h4>
                            <template x-if="me.next">
                                <div>
                                    <b class="grid-rank" :data-rank="me.next.key" x-text="ranks[me.next.key]"></b>
                                    <template x-for="n in me.next.needs" :key="n.k">
                                        <div class="grid-need">
                                            <span x-text="needLabel(n)"></span>
                                            <i><b :style="`width:${Math.min(100, n.need > 0 ? n.have / n.need * 100 : 100)}%`"></b></i>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <p x-show="!me.next" class="muted">{{ __('grid.me.top') }}</p>
                            <p class="muted small">{{ __('grid.me.referrer') }}: <span class="mono" x-text="me.referrer ? short(me.referrer) : @js(__('grid.me.no_referrer'))"></span></p>
                        </div>
                        <div class="grid-box">
                            <h4>{{ __('grid.me.sprint') }}</h4>
                            <div class="kv">
                                <div><span>{{ __('grid.me.sprint_pool') }}</span><b class="num" x-text="idtc(me.sprint.pool) + ' IDTC'"></b></div>
                                <div x-show="me.sprint.eligible"><span>{{ __('grid.me.sprint_mine') }}</span><b class="num" x-text="idtc(me.sprint.mine ? me.sprint.mine.share : 0) + ' IDTC'"></b></div>
                            </div>
                            <p class="muted small" x-show="!me.sprint.eligible">{{ __('grid.me.sprint_need') }}</p>
                            <p class="muted small" x-text="endsIn()"></p>
                        </div>
                    </div>

                    <h4 class="grid-h">{{ __('grid.me.rings') }}</h4>
                    <div class="grid-rings">
                        <template x-for="d in [1, 2, 3]" :key="d">
                            <div class="grid-ring">
                                <h5><span x-text="@js(__('grid.me.ring', ['n' => '__N__'])).replace('__N__', d)"></span> <em class="num" x-text="me.rings[d] ? num(me.rings[d].count) : 0"></em></h5>
                                <p class="muted small" x-show="!me.rings[d] || !me.rings[d].count">{{ __('grid.me.ring_empty') }}</p>
                                <ul>
                                    <template x-for="(c, i) in (me.rings[d]?.items || []).slice(0, 12)" :key="d + ':' + i">
                                        <li><span class="mono" x-text="c.wallet"></span><span class="grid-rank sm" :data-rank="c.rank" x-text="ranks[c.rank]"></span><span class="num" x-text="usd(c.own)"></span></li>
                                    </template>
                                </ul>
                                <p class="muted small" x-show="me.rings[d] && me.rings[d].count > 12" x-text="@js(__('grid.me.more', ['n' => '__N__'])).replace('__N__', me.rings[d] ? num(me.rings[d].count - 12) : 0)"></p>
                            </div>
                        </template>
                    </div>

                    <h4 class="grid-h">{{ __('grid.me.recent') }}</h4>
                    <p class="muted" x-show="!me.recent.length">{{ __('grid.me.recent_empty') }}</p>
                    <div class="table-scroll" x-show="me.recent.length">
                        <table class="grid-tbl">
                            <thead><tr><th>{{ __('grid.me.col_when') }}</th><th>{{ __('grid.me.col_kind') }}</th><th>{{ __('grid.me.col_from') }}</th><th class="r">{{ __('grid.me.col_amount') }}</th><th>{{ __('grid.me.col_status') }}</th></tr></thead>
                            <tbody>
                                <template x-for="(x, i) in me.recent" :key="i">
                                    <tr>
                                        <td x-text="when(x.at)"></td>
                                        <td x-text="kind(x)"></td>
                                        <td class="mono" x-text="x.from"></td>
                                        <td class="r num" x-text="idtc(x.amount)"></td>
                                        <td><span class="grid-status" :data-s="x.status" x-text="status(x.status)"></span></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p class="muted small">{{ __('grid.me.estimate') }} <span x-text="me.claims && me.claims.contract ? @js(__('grid.me.payout_live')) : @js(__('grid.me.payout_note'))">{{ __('grid.me.payout_note') }}</span></p>
                </div>
            </template>
        </section>

        {{-- ═════════ แผน ═════════ --}}
        <section class="grid-how" aria-labelledby="gridHow">
            <h2 id="gridHow">{{ __('grid.how.title') }}</h2>
            <div class="grid-two">
                <div class="panel">
                    <h3>{{ __('grid.how.rings_title') }}</h3>
                    <div class="kv">
                        <div><span>{{ __('grid.how.ring1') }}</span><b class="num">{{ $pct($plan['levels'][0]) }}%</b></div>
                        <div><span>{{ __('grid.how.ring2') }}</span><b class="num">{{ $pct($plan['levels'][1]) }}%</b></div>
                        <div><span>{{ __('grid.how.ring3') }}</span><b class="num">{{ $pct($plan['levels'][2]) }}%</b></div>
                        <div><span>{{ __('grid.how.buyer') }}</span><b class="num">{{ $pct($plan['buyer']) }}%</b></div>
                    </div>
                    <p class="muted small">% {{ __('grid.how.of_buy') }}@if ($boost) · {{ __('grid.how.boost', ['steps' => $boost]) }}@endif</p>
                    <h3 style="margin-top:18px">{{ __('grid.how.sprint_title') }}</h3>
                    <p class="muted">{{ __('grid.how.sprint_text', ['sprint' => $pct($plan['sprint']), 'cap' => $pct($plan['sprint_cap'])]) }}</p>
                    @if ($plan['binary']['pool'] > 0)
                        <h3 style="margin-top:18px">{{ __('grid.bin.how_title') }}</h3>
                        <p class="muted">{{ __('grid.bin.text', ['pair' => $pct($plan['binary']['pair']), 'pool' => $pct($plan['binary']['pool'])]) }}</p>
                        <p class="muted small">{{ __('grid.bin.need', ['n' => $plan['binary']['directs']]) }} · {{ __('grid.bin.how_rule', ['cap' => $pct($plan['binary']['cap'])]) }}</p>
                    @endif
                </div>
                <div class="panel">
                    <h3>{{ __('grid.how.ranks_title') }}</h3>
                    <div class="table-scroll"><table class="grid-tbl">
                        <thead><tr><th>{{ __('grid.how.col_rank') }}</th><th>{{ __('grid.how.col_req') }}</th><th>{{ __('grid.how.col_get') }}</th></tr></thead>
                        <tbody>
                            @foreach ($ranks as $key => $req)
                                <tr><td><span class="grid-rank sm" data-rank="{{ $key }}">{{ __('grid.ranks.'.$key) }}</span></td><td>{{ $req }}</td><td>{{ __('grid.rank_get.'.$key) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table></div>
                </div>
            </div>
            <div class="panel" style="margin-top:18px">
                <h3>{{ __('grid.how.rules_title') }}</h3>
                <ul class="grid-rules">
                    <li>{{ __('grid.how.rule_budget', ['budget' => number_format((float) $plan['budget'])]) }}</li>
                    <li>{{ __('grid.how.rule_hold', ['days' => $plan['hold_days']]) }}</li>
                    <li>{{ __('grid.how.rule_nocomp') }}</li>
                    <li>{{ __('grid.how.rule_bind') }}</li>
                    <li>{{ __('grid.how.rule_rate') }}</li>
                    <li>{{ __('grid.how.rule_instant', ['instant' => $pct($plan['instant'])]) }}</li>
                </ul>
            </div>
            <div class="plan-note warn grid-risk" role="note">{!! $icon !!}<span><b>{{ __('grid.risk.title') }}</b> {{ __('grid.risk.text') }}</span></div>
        </section>
    </div>
@endsection

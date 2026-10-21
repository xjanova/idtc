@extends('layouts.site')

@php
    $warnIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>';
    $tick = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    $wallet = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2z"/><path d="M16 14.5h.5"/></svg>';
@endphp

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">USDT Bridge</p>
            <h1>{!! __('bridge.h1') !!}</h1>
            <p class="lead">{{ __('bridge.lead') }}</p>
        </div>
    </div>

    <div class="wrap presale-grid" x-data="bridge">
        <section class="buy-card" aria-label="{{ __('bridge.aria') }}">
            <div class="buy-inner">
                <div class="buy-top">
                    <div class="seg" role="tablist" aria-label="{{ __('bridge.aria') }}">
                        <button type="button" role="tab" :aria-selected="(tab === 'deposit').toString()" :class="{ 'is-on': tab === 'deposit' }" @click="setTab('deposit')" :disabled="busy">{{ __('bridge.tab_deposit') }}</button>
                        <button type="button" role="tab" :aria-selected="(tab === 'withdraw').toString()" :class="{ 'is-on': tab === 'withdraw' }" @click="setTab('withdraw')" :disabled="busy">{{ __('bridge.tab_withdraw') }}</button>
                    </div>
                    <span class="sale-status" data-s="loading" :data-s="info === null ? (infoError ? 'error' : 'loading') : (open ? 'live' : 'paused')"><i aria-hidden="true"></i>
                        <span x-text="info === null ? (infoError ? @js(__('bridge.closed')) : @js(__('bridge.loading'))) : (open ? @js(__('bridge.open')) : @js(__('bridge.closed')))">{{ __('bridge.loading') }}</span></span>
                </div>

                <p class="plan-note warn" x-show="info && !open" x-cloak>{!! $warnIcon !!}<span x-text="reasonText"></span></p>
                <p class="plan-note warn" x-show="infoError && !info" x-cloak>{!! $warnIcon !!}<span><span x-text="infoError"></span> · <button type="button" class="link-btn" @click="loadInfo()">{{ __('site.retry') }}</button></span></p>

                {{-- ═════ เติม: BSC → TPIX ═════ --}}
                <div x-show="tab === 'deposit'">
                    <div class="field" :class="{ 'is-bad': depProblem }">
                        <label for="depIn"><span>{{ __('bridge.dep.from') }}</span><span class="num" x-show="account" x-cloak>{{ __('bridge.dep.in_wallet') }} <b x-text="bscAmt(bscUsdt)">—</b></span></label>
                        <div class="field-row">
                            <input id="depIn" type="text" inputmode="decimal" autocomplete="off" spellcheck="false" maxlength="24" placeholder="0"
                                   x-model="depIn" @input="error = ''" :readonly="busy" aria-label="{{ __('bridge.dep.aria') }}" aria-describedby="depHelp" :aria-invalid="depProblem ? 'true' : 'false'">
                            <span class="coin"><span class="usdt" aria-hidden="true">₮</span>USDT <small class="chain-tag bsc">BSC</small></span>
                        </div>
                        <div class="quick" x-show="depMax !== null && depMax > 0n" x-cloak>
                            <button type="button" @click="setDepMax()" :disabled="busy">{{ __('bridge.dep.max') }}</button>
                        </div>
                    </div>
                    <p class="field-msg" id="depHelp" role="alert" x-text="depProblem"></p>

                    <div class="swap-arrow" aria-hidden="true"><span><svg viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></svg></span></div>

                    <div class="field">
                        <label><span>{{ __('bridge.dep.to') }}</span><span class="mono" x-show="account" x-cloak x-text="short(account)"></span></label>
                        <div class="field-row">
                            <output class="num" x-text="usdTrim(depReceive)">0</output>
                            <span class="coin"><span class="usdt" aria-hidden="true">₮</span>USDT <small class="chain-tag tpix">TPIX</small></span>
                        </div>
                    </div>

                    <p class="plan-note warn" x-show="senderOk === false" x-cloak>{!! $warnIcon !!}<span>{{ __('bridge.sender_contract') }}</span></p>
                    <p class="plan-note warn" x-show="noGas" x-cloak>{!! $warnIcon !!}<span>{{ __('bridge.no_bnb') }}</span></p>

                    <dl class="kv">
                        <div><dt>{{ __('bridge.kv.min') }}</dt><dd x-text="info?.deposit?.min ? info.deposit.min + ' USDT' : '—'">—</dd></div>
                        <div><dt>{{ __('bridge.kv.max') }}</dt><dd x-text="info?.deposit?.max_per_tx ? info.deposit.max_per_tx + ' USDT' : '—'">—</dd></div>
                        <div><dt>{{ __('bridge.kv.daily') }}</dt><dd x-text="info?.deposit?.max_per_address_daily ? info.deposit.max_per_address_daily + ' USDT' : '—'">—</dd></div>
                        <div x-show="info?.deposit?.remaining_today" x-cloak><dt>{{ __('bridge.kv.today') }}</dt><dd x-text="info?.deposit?.remaining_today + ' USDT'"></dd></div>
                        <div><dt>{{ __('bridge.kv.fee') }}</dt><dd x-text="!info?.deposit?.fee || Number(info.deposit.fee) === 0 ? @js(__('bridge.kv.free')) : info.deposit.fee + ' USDT'">—</dd></div>
                        <div><dt>{{ __('bridge.kv.eta') }}</dt><dd x-text="info?.eta_minutes ? @js(__('bridge.kv.minutes')).replace(':n', '~' + info.eta_minutes) : '—'">—</dd></div>
                        <div x-show="account" x-cloak><dt>{{ __('bridge.kv.gas_bsc') }}</dt><dd x-text="bnb(bscGas) + ' BNB'"></dd></div>
                    </dl>

                    <div class="buy-actions">
                        <button class="btn btn-primary btn-block" type="button" x-show="!account" @click="connect()" :disabled="busy">{!! $wallet !!}<span>{{ __('bridge.dep.connect') }}</span></button>
                        <button class="btn btn-primary btn-block" type="button" x-show="account" x-cloak @click="deposit()" :disabled="!canDeposit">{{ __('bridge.dep.button') }}</button>
                        <p class="buy-hint">{{ __('bridge.dep.hint') }}</p>
                    </div>
                </div>

                {{-- ═════ ถอน: TPIX → BSC ═════ --}}
                <div x-show="tab === 'withdraw'" x-cloak>
                    <div class="field" :class="{ 'is-bad': wdProblem }">
                        <label for="wdIn"><span>{{ __('bridge.wd.from') }}</span><span class="num" x-show="account" x-cloak>{{ __('bridge.wd.in_wallet') }} <b x-text="usd(tpixUsdt)">—</b></span></label>
                        <div class="field-row">
                            <input id="wdIn" type="text" inputmode="decimal" autocomplete="off" spellcheck="false" maxlength="24" placeholder="0"
                                   x-model="wdIn" @input="error = ''" :readonly="busy" aria-label="{{ __('bridge.wd.aria') }}" aria-describedby="wdHelp" :aria-invalid="wdProblem ? 'true' : 'false'">
                            <span class="coin"><span class="usdt" aria-hidden="true">₮</span>USDT <small class="chain-tag tpix">TPIX</small></span>
                        </div>
                        <div class="quick" x-show="account && tpixUsdt !== null && tpixUsdt > 0n" x-cloak>
                            <button type="button" @click="setWdMax()" :disabled="busy">{{ __('bridge.wd.max') }}</button>
                        </div>
                    </div>
                    <p class="field-msg" id="wdHelp" role="alert" x-text="wdProblem"></p>

                    <div class="swap-arrow" aria-hidden="true"><span><svg viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></svg></span></div>

                    <div class="field">
                        <label><span>{{ __('bridge.wd.to') }}</span></label>
                        <div class="field-row">
                            <output class="num" x-text="usdTrim(wdReceive)">0</output>
                            <span class="coin"><span class="usdt" aria-hidden="true">₮</span>USDT <small class="chain-tag bsc">BSC</small></span>
                        </div>
                    </div>

                    <div class="field" :class="{ 'is-bad': wdTarget && targetProblem }">
                        <label for="wdTarget"><span>{{ __('bridge.wd.target') }}</span><span x-show="targetIsSelf" x-cloak>{{ __('bridge.wd.target_self') }}</span></label>
                        <input id="wdTarget" class="addr-in mono" type="text" autocomplete="off" spellcheck="false" maxlength="42" placeholder="0x…" x-model.trim="wdTarget" :readonly="busy">
                    </div>
                    <p class="field-msg" role="alert" x-text="wdTarget || account ? targetProblem : ''"></p>
                    <p class="plan-note warn" x-show="targetOk && !targetIsSelf && !targetProblem" x-cloak>{!! $warnIcon !!}<span>{{ __('bridge.wd.target_other') }}</span></p>

                    <dl class="kv">
                        <div><dt>{{ __('bridge.kv.min') }}</dt><dd x-text="info?.withdraw?.min ? info.withdraw.min + ' USDT' : '—'">—</dd></div>
                        <div><dt>{{ __('bridge.kv.max') }}</dt><dd x-text="info?.withdraw?.max_per_tx ? info.withdraw.max_per_tx + ' USDT' : '—'">—</dd></div>
                        <div><dt>{{ __('bridge.kv.fee') }}</dt><dd x-text="info?.withdraw?.fee ? info.withdraw.fee + ' USDT' : '—'">—</dd></div>
                        <div><dt>{{ __('bridge.kv.eta') }}</dt><dd x-text="info?.eta_minutes ? @js(__('bridge.kv.minutes')).replace(':n', '~' + info.eta_minutes) : '—'">—</dd></div>
                    </dl>

                    <div class="buy-actions">
                        <button class="btn btn-primary btn-block" type="button" x-show="!account" @click="connect()" :disabled="busy">{!! $wallet !!}<span>{{ __('bridge.dep.connect') }}</span></button>
                        <button class="btn btn-primary btn-block" type="button" x-show="account" x-cloak @click="withdraw()" :disabled="!canWithdraw">{{ __('bridge.wd.button') }}</button>
                        <p class="buy-hint">{{ __('bridge.wd.hint') }}</p>
                    </div>
                </div>

                <div class="flow-line" x-show="busy && phaseText" x-cloak role="status">
                    <span class="spin" aria-hidden="true"></span>
                    <span x-text="phaseText"></span>
                    <button type="button" class="link-btn" x-show="!phase.endsWith('-wait')" @click="cancelFlow()">{{ __('site.cancel') }}</button>
                </div>
                <div class="flow-msg err" x-show="error && !busy" x-cloak role="alert">
                    <span x-text="error"></span>
                    <a x-show="mobileNoWallet" href="{{ lroute('add-network') }}">{{ __('trade.open_in_wallet') }}</a>
                </div>
                <p class="flow-msg ok" x-show="notice && !error" x-cloak x-text="notice" role="status"></p>
            </div>
        </section>

        <aside class="side-stack">
            <div class="panel">
                <h3>{{ __('bridge.track.title') }}</h3>
                <p class="muted-p" x-show="tracked.length === 0">{{ __('bridge.track.empty') }}</p>
                <ul class="track-list" x-show="tracked.length" x-cloak>
                    <template x-for="item in tracked" :key="item.hash">
                        <li :data-tone="stateTone(item)">
                            <div class="track-head">
                                <b x-text="item.kind === 'deposit' ? @js(__('bridge.track.deposit')) : @js(__('bridge.track.withdraw'))"></b>
                                <span class="num" x-text="itemAmount(item)"></span>
                                <button type="button" class="link-btn" @click="forget(item.hash)">{{ __('bridge.track.forget') }}</button>
                            </div>
                            <p class="track-state" x-text="stateText(item)"></p>
                            <p class="track-links">
                                <span class="mono" x-text="short(item.hash)"></span>
                                <template x-for="l in itemLinks(item)" :key="l.href"><a :href="l.href" target="_blank" rel="noopener" x-text="l.label"></a></template>
                            </p>
                        </li>
                    </template>
                </ul>
                <div class="field lookup">
                    <label for="lookup"><span>{{ __('bridge.track.lookup') }}</span></label>
                    <input id="lookup" class="addr-in mono" type="text" autocomplete="off" spellcheck="false" maxlength="66" placeholder="{{ __('bridge.track.lookup_ph') }}" x-model.trim="lookup">
                    <p class="field-msg" x-text="lookupProblem()"></p>
                    <div class="row-btns" x-show="lookup && !lookupProblem()" x-cloak>
                        <button class="btn btn-ghost btn-sm" type="button" @click="doLookup('deposit')">{{ __('bridge.track.as_deposit') }}</button>
                        <button class="btn btn-ghost btn-sm" type="button" @click="doLookup('withdraw')">{{ __('bridge.track.as_withdraw') }}</button>
                    </div>
                </div>
                <p class="muted-s">{{ __('bridge.track.note') }}</p>
            </div>

            <div class="panel">
                <h3>{{ __('bridge.next.title') }}</h3>
                <p class="muted-p">{{ __('bridge.next.text') }}</p>
                <div class="row-btns">
                    <a class="btn btn-primary btn-sm" href="{{ lroute('presale') }}">{{ __('bridge.next.presale') }}</a>
                    <a class="btn btn-ghost btn-sm" href="{{ lroute('trade') }}">{{ __('bridge.next.trade') }}</a>
                    <button class="btn btn-ghost btn-sm" type="button" x-show="usdtTpix" @click="addUsdtTpix()">{{ __('bridge.next.add_usdt') }}</button>
                </div>
            </div>

            <div class="panel">
                <h3>{{ __('bridge.warn.title') }}</h3>
                <ul class="rules">
                    @foreach (__('bridge.warn.items') as $item)
                        <li>{!! $tick !!}<span>{{ $item }}</span></li>
                    @endforeach
                </ul>
                <p class="muted-s" style="margin-top:12px"><a href="{{ config('idtc.bridge.page') }}" target="_blank" rel="noopener">{{ __('bridge.official') }} ↗</a></p>
            </div>
        </aside>
    </div>
@endsection

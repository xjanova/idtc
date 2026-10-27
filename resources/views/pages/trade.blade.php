@extends('layouts.site')

@php
    use App\Support\ContractList;
    use App\Support\Idtc;

    $tokenAddr = Idtc::contract('token');
    $live = $tokenAddr !== null;
    $dex = config('idtc.dex');
    $slips = [50, 100, 300];
    $warnIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>';
    $infoIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/></svg>';
    $tick = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    $contracts = ContractList::rows(['token', 'usdt', 'dex_router']);
@endphp

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">TPIX DEX</p>
            <h1>{!! __('trade.h1') !!}</h1>
            <p class="lead">{{ __('trade.lead') }}</p>
        </div>
    </div>

    <div class="wrap presale-grid" x-data="trade" data-trade-state="{{ $live ? 'live' : 'pending-deploy' }}">
        <section class="buy-card" aria-label="{{ __('trade.aria') }}">
            <div class="buy-inner">
                <div class="buy-top">
                    <div class="seg" role="tablist" aria-label="{{ __('trade.aria') }}">
                        <button type="button" role="tab" :aria-selected="(side === 'buy').toString()" :class="{ 'is-on': side === 'buy' }" @click="setSide('buy')" :disabled="busy">{{ __('trade.buy') }}</button>
                        <button type="button" role="tab" :aria-selected="(side === 'sell').toString()" :class="{ 'is-on': side === 'sell', 'is-sell': side === 'sell' }" @click="setSide('sell')" :disabled="busy">{{ __('trade.sell') }}</button>
                    </div>
                    <div class="buy-top-r">
                        <span class="sale-status" data-s="{{ $live ? 'loading' : 'nodeploy' }}" :data-s="status"><i aria-hidden="true"></i><span x-text="@js(__('trade.status'))[status]">{{ $live ? __('trade.status.loading') : __('trade.status.nodeploy') }}</span></span>
                        <button type="button" class="icon-btn" @click="showSettings = !showSettings" :aria-expanded="showSettings.toString()" aria-controls="tradeSettings" aria-label="{{ __('trade.settings') }}">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>
                        </button>
                    </div>
                </div>

                <div class="settings-box" id="tradeSettings" x-show="showSettings" x-cloak x-transition.opacity>
                    <b>{{ __('trade.slippage_title') }}</b>
                    <div class="quick" role="group" aria-label="{{ __('trade.slippage_title') }}">
                        @foreach ($slips as $s)
                            <button type="button" :class="{ 'is-on': slipBps === {{ $s }} && slipInput === '' }" @click="setSlip({{ $s }})">{{ rtrim(rtrim(number_format($s / 100, 2), '0'), '.') }}%</button>
                        @endforeach
                        <input class="slip-in" type="text" inputmode="decimal" maxlength="5" placeholder="{{ __('trade.slippage_custom') }}" x-model="slipInput" @input="onSlipInput()" aria-label="{{ __('trade.slippage_custom') }}">
                    </div>
                    <p class="field-msg" x-text="slipProblem"></p>
                    <p class="muted-s">{{ __('trade.slippage_help') }}</p>
                </div>

                @unless ($live)
                    <p class="plan-note" data-testid="trade-pending">{!! $infoIcon !!}<span>{{ __('trade.pending') }}</span></p>
                @endunless
                <p class="plan-note warn" x-show="poolError" x-cloak>{!! $warnIcon !!}<span><span x-text="poolError"></span> · <button type="button" class="link-btn" @click="refresh()">{{ __('site.retry') }}</button></span></p>
                <p class="plan-note" x-show="status === 'nopool'" x-cloak>{!! $infoIcon !!}<span>{{ __('trade.no_pool_note') }}</span></p>

                <div class="pair-pick" role="group" aria-label="{{ __('trade.with') }}">
                    <span>{{ __('trade.with') }}</span>
                    <button type="button" :class="{ 'is-on': counterKey === 'usdt' }" @click="setCounter('usdt')" :disabled="busy"><span class="usdt" aria-hidden="true">₮</span>USDT</button>
                    <button type="button" :class="{ 'is-on': counterKey === 'tpix' }" @click="setCounter('tpix')" :disabled="busy"><span class="usdt tpix" aria-hidden="true">T</span>TPIX</button>
                </div>

                <div class="field" :class="{ 'is-bad': problem }">
                    <label for="tradeIn"><span>{{ __('trade.pay') }}</span><span class="num" x-show="account" x-cloak>{{ __('trade.in_wallet') }} <b x-text="amt(balIn, inDec)">—</b> <span x-text="inSymbol"></span></span></label>
                    <div class="field-row">
                        <input id="tradeIn" type="text" inputmode="decimal" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="32"
                               placeholder="0" x-model="input" @input="onInput()" :readonly="busy" :aria-invalid="problem ? 'true' : 'false'" aria-describedby="tradeHelp">
                        <span class="coin" x-show="inSymbol === 'IDTC'"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="24" height="24">IDTC</span>
                        <span class="coin" x-show="inSymbol !== 'IDTC'" x-cloak><span class="usdt" aria-hidden="true" x-text="inSymbol === 'USDT' ? '₮' : 'T'">₮</span><span x-text="inSymbol">USDT</span></span>
                    </div>
                    <div class="quick" role="group" aria-label="{{ __('trade.pct') }}" x-show="account && balIn !== null && balIn > 0n" x-cloak>
                        @foreach ([25, 50, 75, 100] as $p)
                            <button type="button" @click="setPct({{ $p }})" :disabled="busy">{{ $p }}%</button>
                        @endforeach
                    </div>
                </div>
                <p class="field-msg" id="tradeHelp" role="alert" x-text="problem"></p>

                <div class="swap-arrow"><button type="button" @click="flip()" :disabled="busy" aria-label="{{ __('trade.flip') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4v16M4 16l4 4 4-4M16 20V4M12 8l4-4 4 4"/></svg></button></div>

                <div class="field">
                    <label><span>{{ __('trade.receive') }}</span><span class="num" x-show="account" x-cloak>{{ __('trade.in_wallet') }} <b x-text="amt(balOut, outDec)">—</b></span></label>
                    <div class="field-row">
                        <output class="num" for="tradeIn" aria-live="polite" x-text="outText">0</output>
                        <span class="coin" x-show="outSymbol === 'IDTC'"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="24" height="24">IDTC</span>
                        <span class="coin" x-show="outSymbol !== 'IDTC'" x-cloak><span class="usdt" aria-hidden="true" x-text="outSymbol === 'USDT' ? '₮' : 'T'">₮</span><span x-text="outSymbol">USDT</span></span>
                    </div>
                </div>

                <p class="plan-note" x-show="presaleBetter" x-cloak>{!! $infoIcon !!}<span><span x-text="@js(__('trade.presale_better')).replace(':out', amt(presaleOut, {{ (int) config('idtc.token.decimals') }}))"></span> <a href="{{ lroute('presale') }}">{{ __('trade.presale_go') }}</a></span></p>
                <p class="plan-note warn" x-show="amountOk && impactLevel === 'warn'" x-cloak>{!! $warnIcon !!}<span>{{ __('trade.impact_warn') }}</span></p>
                <div class="plan-note danger" x-show="amountOk && impactLevel === 'high'" x-cloak>
                    {!! $warnIcon !!}
                    <span>{{ __('trade.impact_high') }}
                        <label class="ack"><input type="checkbox" x-model="impactAck"> {{ __('trade.impact_ack') }}</label>
                    </span>
                </div>

                <dl class="kv">
                    <div><dt>{{ __('trade.kv.price') }}</dt><dd>1 IDTC = <span x-text="priceText">—</span> <span x-text="counter.symbol">USDT</span></dd></div>
                    <div x-show="amountOk && hasPool" x-cloak><dt>{{ __('trade.kv.min_out') }}</dt><dd><span x-text="minOutText"></span> <span x-text="outSymbol"></span></dd></div>
                    <div x-show="amountOk && hasPool" x-cloak><dt>{{ __('trade.kv.impact') }}</dt><dd :class="'impact-' + impactLevel" x-text="impactText"></dd></div>
                    <div><dt>{{ __('trade.kv.slippage') }}</dt><dd x-text="slipText">1%</dd></div>
                    <div><dt>{{ __('trade.kv.fee') }}</dt><dd>{{ rtrim(rtrim(number_format($dex['fee_bps'] / 100, 2), '0'), '.') }}%</dd></div>
                    <div><dt>{{ __('trade.kv.gas') }}</dt><dd>0 TPIX</dd></div>
                </dl>

                <div class="buy-actions">
                    <button class="btn btn-primary btn-block" type="button" x-show="!account" @click="connect()" :disabled="busy">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2z"/><path d="M16 14.5h.5"/></svg>
                        <span x-text="phase === 'connect' ? @js(__('trade.connecting')) : @js(__('trade.connect'))">{{ __('trade.connect') }}</span>
                    </button>
                    <button class="btn btn-block" type="button" x-show="account" x-cloak @click="swap()" :disabled="!canSwap" :class="side === 'sell' ? 'btn-sell' : 'btn-primary'">
                        <span x-text="busy ? @js(__('trade.working')) : (side === 'buy' ? @js(__('trade.do_buy')) : @js(__('trade.do_sell')))">{{ __('trade.do_buy') }}</span>
                    </button>
                    <p class="buy-hint">
                        <span x-show="account && !walletChainOk" x-cloak>{{ __('trade.hint_chain') }}</span>
                        <span x-show="account && walletChainOk && amountOk && needsApprove" x-cloak>{{ __('trade.hint_approve') }}</span>
                        <span x-show="side === 'buy' && counterKey === 'usdt'">{{ __('trade.no_usdt') }} <a href="{{ lroute('bridge') }}">{{ __('trade.no_usdt_link') }}</a></span>
                    </p>
                </div>

                <div class="flow-line" x-show="busy && phaseText" x-cloak role="status">
                    <span class="spin" aria-hidden="true"></span>
                    <span x-text="phaseText"></span>
                    <a x-show="errorTx && phase.endsWith('-wait')" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">{{ __('site.explorer_link') }}</a>
                    <button type="button" class="link-btn" x-show="!phase.endsWith('-wait')" @click="cancelFlow()">{{ __('site.cancel') }}</button>
                </div>
                <div class="flow-msg err" x-show="error && !busy" x-cloak role="alert">
                    <span x-text="error"></span>
                    <a x-show="errorTx" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">{{ __('site.explorer_tx') }}</a>
                    <a x-show="mobileNoWallet" href="{{ lroute('add-network') }}">{{ __('trade.open_in_wallet') }}</a>
                </div>
                <p class="flow-msg ok" x-show="notice && !error && !success" x-cloak x-text="notice" role="status"></p>

                <div class="success" x-show="success" x-cloak role="status">
                    <span class="success-ic" aria-hidden="true">{!! $tick !!}</span>
                    <div>
                        <b x-text="success && success.side === 'sell' ? @js(__('trade.success_sell')) : @js(__('trade.success_buy'))"></b>
                        <p>{{ __('trade.success_got') }} <span class="num" x-text="success ? amt(success.got, success.side === 'buy' ? {{ (int) config('idtc.token.decimals') }} : (success.sym === 'USDT' ? {{ (int) config('idtc.sale.pay_decimals') }} : 18), 4) : ''"></span>
                            <span x-text="success ? (success.side === 'buy' ? 'IDTC' : success.sym) : ''"></span>
                            <span x-show="success && !success.exact">{{ __('trade.success_est') }}</span></p>
                        <div class="row-btns">
                            <a class="btn btn-ghost btn-sm" :href="success ? explorerTx(success.hash) : '#'" target="_blank" rel="noopener">{{ __('site.view_tx') }}</a>
                            <button class="btn btn-primary btn-sm" type="button" x-show="tokenAddr" @click="addToken()">{{ __('trade.add_idtc') }}</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="again()">{{ __('trade.again') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <aside class="side-stack">
            <div class="panel user-panel" x-show="account" x-cloak>
                <h3>{{ __('trade.wallet.title') }} <a class="addr" :href="account ? explorerAddress(account) : '#'" target="_blank" rel="noopener" x-text="account ? short(account) + ' ↗' : ''"></a></h3>
                <dl class="kv kv-tight">
                    <div><dt>{{ __('trade.wallet.idtc') }}</dt><dd x-text="amt(balIdtc, {{ (int) config('idtc.token.decimals') }})">—</dd></div>
                    <div><dt x-text="counterKey === 'usdt' ? @js(__('trade.wallet.usdt')) : @js(__('trade.wallet.tpix'))"></dt><dd x-text="amt(balCounter, counter.decimals)">—</dd></div>
                </dl>
                <div class="row-btns"><button class="btn btn-ghost btn-sm" type="button" @click="loadUser()">{{ __('trade.wallet.refresh') }}</button></div>
            </div>

            <div class="panel">
                <h3>{{ __('trade.pool.title') }} <span class="pill-wait" x-show="status !== 'live'">{{ __('trade.pool.none') }}</span></h3>
                <dl class="kv kv-tight">
                    <div><dt>{{ __('trade.pool.reserves') }}</dt><dd x-text="hasPool ? amt(reserveIdtc, {{ (int) config('idtc.token.decimals') }}, 0) + ' IDTC' : '—'">—</dd></div>
                    <div><dt></dt><dd x-text="hasPool ? amt(reserveCounter, counter.decimals) + ' ' + counter.symbol : ''"></dd></div>
                    <div><dt>{{ __('trade.pool.rate') }}</dt><dd x-text="hasPool ? '1 ' + counter.symbol + ' = ' + inverseText + ' IDTC' : '—'">—</dd></div>
                    <div x-show="pairAddr" x-cloak><dt>{{ __('trade.kv.pool') }}</dt><dd><a class="mono" :href="pairAddr ? explorerAddress(pairAddr) : '#'" target="_blank" rel="noopener" x-text="pairAddr ? short(pairAddr) + ' ↗' : ''"></a></dd></div>
                </dl>
                <p class="muted-s">{{ __('trade.pool.note') }}</p>
            </div>

            <div class="panel">
                <h3>{{ __('trade.risk.title') }}</h3>
                <ul class="rules">
                    @foreach (__('trade.risk.items') as $item)
                        <li>{!! $tick !!}<span>{{ $item }}</span></li>
                    @endforeach
                </ul>
            </div>

            <div class="panel">
                <h3>{{ __('presale.contracts.title') }} @unless ($live)<span class="pill-wait">{{ __('site.pending') }}</span>@endunless</h3>
                <ul class="clist">
                    @foreach ($contracts as $c)
                        <li data-contract="{{ $c['key'] }}">
                            <span class="clist-name">{{ $c['name'] }}<small>{{ $c['kind'] }}</small></span>
                            @if ($c['address'])
                                <a class="addr" href="{{ $c['url'] }}" target="_blank" rel="noopener" title="{{ $c['address'] }}">{{ Idtc::short($c['address']) }} ↗</a>
                            @else
                                <span class="pill-wait">{{ __('site.pending') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </aside>
    </div>
@endsection

@extends('layouts.site')

@php
    use App\Support\ContractList;
    use App\Support\Idtc;
    use App\Support\SiteLocale;

    $saleAddr = Idtc::contract('presale');
    $live = $saleAddr !== null;
    $sale = config('idtc.sale');
    // ราคาในแผน: IDTC ต่อ 1 USDT = 10^payDecimals × num ÷ den ÷ 10^18 (สูตรเดียวกับสัญญา)
    $rate = ((float) $sale['rate_num'] / (float) $sale['rate_den']) * 10 ** (int) $sale['pay_decimals'] / 10 ** (int) config('idtc.token.decimals');
    $cap = (float) $sale['cap'];
    $defaultPay = 100;
    $defaultOut = number_format(floor($defaultPay * $rate * 100) / 100, 2);
    $contracts = ContractList::rows(['presale', 'token', 'usdt']);
    $tick = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    $explorer = config('idtc.chain.explorer');
@endphp

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">Presale</p>
            <h1>{!! __('presale.h1') !!}</h1>
            <p class="lead">{{ __('presale.lead', ['price' => $sale['price_usd']]) }}</p>
        </div>
    </div>

    <div class="wrap presale-grid" x-data="presale" data-presale-state="{{ $live ? 'live' : 'pending-deploy' }}">
        {{-- ═════════ BUY CARD ═════════ --}}
        <section class="buy-card" aria-label="{{ __('presale.buy_aria') }}">
            <div class="buy-inner">
                <div class="buy-top">
                    <span class="sale-status" data-s="{{ $live ? 'loading' : 'nodeploy' }}" :data-s="status"><i aria-hidden="true"></i><span x-text="statusLabel">{{ $live ? __('presale.reading') : __('site.pending') }}</span></span>
                    <span class="buy-note num" x-text="statusNote">{{ $live ? '' : __('presale.plan_note') }}</span>
                </div>

                @unless ($live)
                    <p class="plan-note" data-testid="presale-pending">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/></svg>
                        <span>{{ __('presale.pending') }}</span>
                    </p>
                @endunless
                <p class="plan-note warn" x-show="loadError" x-cloak>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                    <span><span x-text="loadError"></span> · <button type="button" class="link-btn" @click="refresh()">{{ __('site.retry') }}</button></span>
                </p>
                <p class="plan-note warn" x-show="rateMismatch" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span>{{ __('presale.rate_mismatch') }}</span></p>
                <p class="plan-note warn" x-show="payMismatch" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span>{{ __('presale.pay_mismatch') }}</span></p>

                <div class="field" :class="{ 'is-bad': problem }">
                    <label for="payIn"><span>{{ __('presale.you_pay') }}</span><span class="num" x-show="account" x-cloak>{{ __('presale.in_wallet') }} <b x-text="usdt(usdtBal)">—</b> USDT</span></label>
                    <div class="field-row">
                        <input id="payIn" type="text" inputmode="decimal" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="24"
                               value="{{ $defaultPay }}" x-model="input" @input="onInput()" :readonly="busy"
                               aria-describedby="payHelp" :aria-invalid="problem ? 'true' : 'false'" aria-label="{{ __('presale.pay_aria') }}">
                        <span class="coin"><span class="usdt" aria-hidden="true">₮</span>USDT</span>
                    </div>
                    <div class="quick" role="group" aria-label="{{ __('presale.quick') }}">
                        @foreach (['10', '50', '100', '500'] as $q)
                            <button type="button" @click="setQuick('{{ $q }}')" :disabled="busy">{{ $q }}</button>
                        @endforeach
                        <button type="button" x-show="maxPay !== null && maxPay > 0n" x-cloak @click="setQuick(maxPay)" :disabled="busy">{{ __('presale.max') }}</button>
                    </div>
                </div>
                <p class="field-msg" id="payHelp" role="alert" x-text="problem"></p>

                <div class="swap-arrow" aria-hidden="true"><span><svg viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></svg></span></div>

                <div class="field">
                    <label><span>{{ __('presale.you_get') }}</span><span>{{ __('presale.on_confirm') }}</span></label>
                    <div class="field-row">
                        <output class="num" for="payIn" aria-live="polite" x-text="quoteText">{{ $defaultOut }}</output>
                        <span class="coin"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="24" height="24">IDTC</span>
                    </div>
                </div>
                <p class="field-msg warn" x-show="quoteMismatch" x-cloak>{{ __('presale.quote_mismatch') }}</p>

                <dl class="kv">
                    <div><dt>{{ __('presale.kv.price') }}</dt><dd>1 USDT = <span x-text="rateText">{{ number_format($rate, 2) }}</span> IDTC</dd></div>
                    <div><dt>{{ __('presale.kv.gas') }}</dt><dd>0 TPIX</dd></div>
                    <div><dt>{{ __('presale.kv.min') }}</dt><dd><span x-text="usdtShort(min)">{{ $sale['min_purchase_usdt'] }}</span> USDT</dd></div>
                    <div x-show="deployed && loaded" x-cloak><dt>{{ __('presale.kv.per_wallet') }}</dt><dd x-text="maxPerWallet === 0n ? @js(__('presale.kv.unlimited')) : idtc(maxPerWallet, 0) + ' IDTC'"></dd></div>
                    <div>
                        <dt>{{ __('presale.kv.treasury') }}</dt>
                        <dd>
                            <a class="mono" x-show="treasury" x-cloak :href="treasury ? explorerAddress(treasury) : '#'" target="_blank" rel="noopener" x-text="treasury ? short(treasury) + ' ↗' : ''"></a>
                            <span x-show="!treasury">{{ $live ? '…' : __('site.pending') }}</span>
                        </dd>
                    </div>
                </dl>

                {{-- ปุ่มทำรายการ --}}
                <div class="buy-actions">
                    <button class="btn btn-primary btn-block" type="button" x-show="!account" @click="connect()" :disabled="busy">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2z"/><path d="M16 14.5h.5"/></svg>
                        <span x-text="phase === 'connect' ? @js(__('presale.connecting')) : @js(__('presale.connect'))">{{ __('presale.connect') }}</span>
                    </button>
                    <div class="steps2" x-show="account" x-cloak>
                        <button class="btn" type="button" @click="purchase()" :disabled="!canApprove"
                                :class="needsApprove ? 'btn-primary' : ('btn-ghost' + (amountOk ? ' is-done' : ''))">
                            <span x-text="phase === 'approve' || phase === 'approve-wait' ? @js(__('presale.approving')) : (!needsApprove && amountOk ? @js(__('presale.approved')) : @js(__('presale.approve')))">{{ __('presale.approve') }}</span>
                        </button>
                        <button class="btn" type="button" @click="purchase()" :disabled="!canBuy" :class="needsApprove ? 'btn-ghost' : 'btn-primary'">
                            <span x-text="phase === 'buy' || phase === 'buy-wait' ? @js(__('presale.buying')) : @js(__('presale.buy'))">{{ __('presale.buy') }}</span>
                        </button>
                    </div>
                    <p class="buy-hint">
                        @if ($live)
                            <span x-show="account && !walletChainOk" x-cloak>{{ __('presale.hint_chain') }}</span>
                            <span x-show="account && walletChainOk && status === 'live' && needsApprove" x-cloak>{{ __('presale.hint_approve') }}</span>
                            <span x-show="status !== 'live' && status !== 'loading'" x-cloak x-text="@js(__('presale.hint_status'))"></span>
                        @else
                            {{ __('presale.hint_pending') }}
                        @endif
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
                    <a x-show="mobileNoWallet" href="{{ lroute('add-network') }}">{{ __('presale.open_in_wallet') }}</a>
                </div>
                <p class="flow-msg ok" x-show="notice && !error && !success" x-cloak x-text="notice" role="status"></p>

                <div class="success" x-show="success" x-cloak role="status">
                    <span class="success-ic" aria-hidden="true">{!! $tick !!}</span>
                    <div>
                        <b>{{ __('presale.success') }}</b>
                        <p>{{ __('presale.success_got') }} <span class="num" x-text="success ? idtc(success.out) : ''"></span> {{ __('presale.success_for') }} <span class="num" x-text="success ? usdtShort(success.pay) : ''"></span> USDT</p>
                        <div class="row-btns">
                            <a class="btn btn-ghost btn-sm" :href="success ? explorerTx(success.hash) : '#'" target="_blank" rel="noopener">{{ __('site.view_tx') }}</a>
                            <button class="btn btn-primary btn-sm" type="button" x-show="tokenAddr" @click="addToken()">{{ __('presale.add_idtc') }}</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="again()">{{ __('presale.again') }}</button>
                        </div>
                        <p class="muted-s" x-show="notice" x-text="notice"></p>
                    </div>
                </div>

                <div class="meter">
                    <div class="meter-head"><span>{{ __('presale.sold') }}</span><b class="num"><span x-text="idtc(sold, 0)">0</span> IDTC</b></div>
                    <div class="bar" role="progressbar" aria-label="{{ __('presale.sold_aria') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" :aria-valuenow="pct"><i :style="`--w:${pct}%`"></i></div>
                    <div class="meter-foot">
                        <span><span x-text="pctText">0.00</span>% · {{ __('presale.left') }} <span class="num" x-text="idtc(remaining, 0)">{{ number_format($cap) }}</span> IDTC</span>
                        <span>{{ __('presale.cap_total') }} <span x-text="big(cap)">{{ SiteLocale::big($cap) }}</span></span>
                    </div>
                    <div class="meter-foot"><span x-text="loaded ? raisedText : (loadError ? @js(__('presale.cant_read')) : @js($live ? __('presale.reading_totals') : __('presale.waiting')))">{{ $live ? __('presale.reading_totals') : __('presale.waiting') }}</span></div>
                </div>
            </div>
        </section>

        {{-- ═════════ SIDE ═════════ --}}
        <aside class="side-stack">
            <div class="panel user-panel" x-show="account" x-cloak>
                <h3>{{ __('presale.wallet.title') }} <a class="addr" :href="account ? explorerAddress(account) : '#'" target="_blank" rel="noopener" x-text="account ? short(account) + ' ↗' : ''"></a></h3>
                <dl class="kv kv-tight">
                    <div><dt>{{ __('presale.wallet.usdt') }}</dt><dd x-text="usdt(usdtBal)">—</dd></div>
                    <div x-show="deployed"><dt>{{ __('presale.wallet.allowance') }}</dt><dd x-text="usdt(allowance)">—</dd></div>
                    <div x-show="deployed"><dt>{{ __('presale.wallet.bought') }}</dt><dd x-text="idtc(purchased) + ' IDTC'">—</dd></div>
                    <div x-show="deployed && loaded"><dt>{{ __('presale.wallet.left') }}</dt><dd x-text="walletLeft === null ? @js(__('presale.kv.unlimited')) : idtc(walletLeft) + ' IDTC'">—</dd></div>
                    <div x-show="tokenAddr"><dt>{{ __('presale.wallet.idtc') }}</dt><dd x-text="idtc(tokenBal)">—</dd></div>
                </dl>
                <p class="muted-s" x-show="!deployed">{{ __('presale.wallet.pending') }}</p>
                <div class="row-btns">
                    <button class="btn btn-ghost btn-sm" type="button" @click="loadUser()">{{ __('presale.wallet.refresh') }}</button>
                    <button class="btn btn-ghost btn-sm" type="button" x-show="payAddr" @click="addUsdt()">{{ __('presale.wallet.add_usdt') }}</button>
                </div>
            </div>

            <div class="panel">
                <h3>{{ __('presale.rules.title') }}</h3>
                <ul class="rules">
                    @foreach (__('presale.rules.items', ['rate' => number_format($rate, 2), 'cap' => SiteLocale::big($cap)]) as $rule)
                        <li>{!! $tick !!}<span>{{ $rule }}</span></li>
                    @endforeach
                </ul>
                <p class="muted-s" style="margin-top:12px">{{ __('presale.rules.note') }}</p>
            </div>

            <div class="panel">
                <h3>{{ __('presale.bridge.title') }}</h3>
                <p class="muted-p">{{ __('presale.bridge.text') }}</p>
                <a class="btn btn-ghost btn-block" href="https://tpix.online/bridge" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17h18M5 17V9a7 7 0 0 1 14 0v8M9 17v-4M15 17v-4"/></svg>{{ __('presale.bridge.cta') }}
                </a>
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
                <p class="muted-s" style="margin-top:12px">{!! __('presale.contracts.check', [
                    'explorer' => '<a href="'.e($explorer).'" target="_blank" rel="noopener">explorer.tpix.online</a>',
                    'transparency' => '<a href="'.e(lroute('transparency')).'">'.e(__('site.nav.transparency')).'</a>',
                ]) !!}</p>
            </div>
        </aside>
    </div>
@endsection

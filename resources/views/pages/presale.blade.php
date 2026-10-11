@extends('layouts.site')

@php
    use App\Support\ContractList;
    use App\Support\Idtc;

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
@endphp

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">Presale</p>
            <h1>ซื้อ IDTC <span class="nowrap">ราคาคงที่</span> <span class="grad nowrap">ด้วย USDT</span></h1>
            <p class="lead">ได้เหรียญทันทีในธุรกรรมเดียว ไม่มีรอเคลม · ราคา ${{ $sale['price_usd'] }} ต่อ IDTC เท่ากันทุกคน · ค่าแก๊ส 0 บนเชน TPIX</p>
        </div>
    </div>

    <div class="wrap presale-grid" x-data="presale" data-presale-state="{{ $live ? 'live' : 'pending-deploy' }}">
        {{-- ═════════ BUY CARD ═════════ --}}
        <section class="buy-card" aria-label="ซื้อ IDTC">
            <div class="buy-inner">
                <div class="buy-top">
                    <span class="sale-status" data-s="{{ $live ? 'loading' : 'nodeploy' }}" :data-s="status"><i aria-hidden="true"></i><span x-text="statusLabel">{{ $live ? 'กำลังอ่านสัญญา…' : 'รอ deploy' }}</span></span>
                    <span class="buy-note num" x-text="statusNote">{{ $live ? '' : 'ตัวเลขคำนวณจากราคาในแผน' }}</span>
                </div>

                @unless ($live)
                    <p class="plan-note" data-testid="presale-pending">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/></svg>
                        <span>สัญญา presale ยังไม่ขึ้นเชน (รอ deploy) — ปุ่มซื้อจะเปิดเมื่อสัญญาพร้อม ราคาด้านล่างคือราคาเดียวกับที่จะฝังในสัญญา</span>
                    </p>
                @endunless
                <p class="plan-note warn" x-show="loadError" x-cloak>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg>
                    <span><span x-text="loadError"></span> · <button type="button" class="link-btn" @click="refresh()">ลองใหม่</button></span>
                </p>
                <p class="plan-note warn" x-show="rateMismatch" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span>อัตราในสัญญาไม่ตรงกับแผนที่ประกาศ หน้านี้คำนวณตามอัตราในสัญญา</span></p>
                <p class="plan-note warn" x-show="payMismatch" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span>สัญญานี้รับชำระด้วยเหรียญคนละตัวกับ USDT ที่ตั้งค่าไว้ หน้านี้ใช้ตัวที่สัญญากำหนด</span></p>

                <div class="field" :class="{ 'is-bad': problem }">
                    <label for="payIn"><span>คุณจ่าย</span><span class="num" x-show="account" x-cloak>ในกระเป๋า <b x-text="usdt(usdtBal)">—</b> USDT</span></label>
                    <div class="field-row">
                        <input id="payIn" type="text" inputmode="decimal" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="24"
                               value="{{ $defaultPay }}" x-model="input" @input="onInput()" :readonly="busy"
                               aria-describedby="payHelp" :aria-invalid="problem ? 'true' : 'false'" aria-label="จำนวน USDT ที่จ่าย">
                        <span class="coin"><span class="usdt" aria-hidden="true">₮</span>USDT</span>
                    </div>
                    <div class="quick" role="group" aria-label="จำนวนลัด">
                        @foreach (['10', '50', '100', '500'] as $q)
                            <button type="button" @click="setQuick('{{ $q }}')" :disabled="busy">{{ $q }}</button>
                        @endforeach
                        <button type="button" x-show="maxPay !== null && maxPay > 0n" x-cloak @click="setQuick(maxPay)" :disabled="busy">สูงสุด</button>
                    </div>
                </div>
                <p class="field-msg" id="payHelp" role="alert" x-text="problem"></p>

                <div class="swap-arrow" aria-hidden="true"><span><svg viewBox="0 0 24 24"><path d="M12 5v14M6 13l6 6 6-6"/></svg></span></div>

                <div class="field">
                    <label><span>คุณได้รับ</span><span>ทันทีที่เชนยืนยัน</span></label>
                    <div class="field-row">
                        <output class="num" for="payIn" aria-live="polite" x-text="quoteText">{{ $defaultOut }}</output>
                        <span class="coin"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="24" height="24">IDTC</span>
                    </div>
                </div>
                <p class="field-msg warn" x-show="quoteMismatch" x-cloak>ตัวเลขจากสัญญาต่างจากที่หน้าเว็บคำนวณ แสดงตามสัญญา</p>

                <dl class="kv">
                    <div><dt>ราคา</dt><dd>1 USDT = <span x-text="rateText">{{ number_format($rate, 2) }}</span> IDTC</dd></div>
                    <div><dt>ค่าแก๊ส</dt><dd>0 TPIX</dd></div>
                    <div><dt>ขั้นต่ำต่อครั้ง</dt><dd><span x-text="usdtShort(min)">{{ $sale['min_purchase_usdt'] }}</span> USDT</dd></div>
                    <div x-show="deployed && loaded" x-cloak><dt>เพดานต่อกระเป๋า</dt><dd x-text="maxPerWallet === 0n ? 'ไม่จำกัด' : idtc(maxPerWallet, 0) + ' IDTC'"></dd></div>
                    <div>
                        <dt>เงินเข้ากระเป๋าโครงการ</dt>
                        <dd>
                            <a class="mono" x-show="treasury" x-cloak :href="treasury ? explorerAddress(treasury) : '#'" target="_blank" rel="noopener" x-text="treasury ? short(treasury) + ' ↗' : ''"></a>
                            <span x-show="!treasury">{{ $live ? '…' : 'รอ deploy' }}</span>
                        </dd>
                    </div>
                </dl>

                {{-- ปุ่มทำรายการ --}}
                <div class="buy-actions">
                    <button class="btn btn-primary btn-block" type="button" x-show="!account" @click="connect()" :disabled="busy">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2z"/><path d="M16 14.5h.5"/></svg>
                        <span x-text="phase === 'connect' ? 'กำลังเชื่อมกระเป๋า…' : 'เชื่อมกระเป๋าเพื่อซื้อ'">เชื่อมกระเป๋าเพื่อซื้อ</span>
                    </button>
                    <div class="steps2" x-show="account" x-cloak>
                        <button class="btn" type="button" @click="purchase()" :disabled="!canApprove"
                                :class="needsApprove ? 'btn-primary' : ('btn-ghost' + (amountOk ? ' is-done' : ''))">
                            <span x-text="phase === 'approve' || phase === 'approve-wait' ? 'กำลังอนุญาต…' : (!needsApprove && amountOk ? '✓ อนุญาต USDT แล้ว' : '1 · อนุญาต USDT')">1 · อนุญาต USDT</span>
                        </button>
                        <button class="btn" type="button" @click="purchase()" :disabled="!canBuy" :class="needsApprove ? 'btn-ghost' : 'btn-primary'">
                            <span x-text="phase === 'buy' || phase === 'buy-wait' ? 'กำลังซื้อ…' : '2 · ซื้อ IDTC'">2 · ซื้อ IDTC</span>
                        </button>
                    </div>
                    <p class="buy-hint">
                        @if ($live)
                            <span x-show="account && !walletChainOk" x-cloak>กระเป๋าอยู่เชนอื่น ตอนกดซื้อระบบจะขอสลับเป็นเชน TPIX ให้</span>
                            <span x-show="account && walletChainOk && status === 'live' && needsApprove" x-cloak>กดครั้งเดียว: อนุญาต USDT เท่ายอดที่ซื้อ แล้วกระเป๋าจะถามยืนยันการซื้อต่อทันที</span>
                            <span x-show="status !== 'live' && status !== 'loading'" x-cloak x-text="'ซื้อได้เมื่อสถานะเป็น “เปิดขายอยู่”'"></span>
                        @else
                            ปุ่มซื้อจะกดได้เมื่อสัญญา presale ขึ้นเชน ระหว่างนี้เชื่อมกระเป๋าเพื่อดูยอด USDT บนเชน TPIX ได้
                        @endif
                    </p>
                </div>

                <div class="flow-line" x-show="busy && phaseText" x-cloak role="status">
                    <span class="spin" aria-hidden="true"></span>
                    <span x-text="phaseText"></span>
                    <a x-show="errorTx && phase.endsWith('-wait')" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">ดูบน explorer ↗</a>
                    <button type="button" class="link-btn" x-show="!phase.endsWith('-wait')" @click="cancelFlow()">ยกเลิก</button>
                </div>

                <div class="flow-msg err" x-show="error && !busy" x-cloak role="alert">
                    <span x-text="error"></span>
                    <a x-show="errorTx" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">ดูรายการบน explorer ↗</a>
                    <a x-show="mobileNoWallet" href="{{ route('add-network') }}">เปิดเว็บนี้ในแอปกระเป๋า →</a>
                </div>
                <p class="flow-msg ok" x-show="notice && !error && !success" x-cloak x-text="notice" role="status"></p>

                <div class="success" x-show="success" x-cloak role="status">
                    <span class="success-ic" aria-hidden="true">{!! $tick !!}</span>
                    <div>
                        <b>ซื้อสำเร็จ เหรียญเข้ากระเป๋าแล้ว</b>
                        <p>ได้รับ <span class="num" x-text="success ? idtc(success.out) : ''"></span> IDTC จากการจ่าย <span class="num" x-text="success ? usdtShort(success.pay) : ''"></span> USDT</p>
                        <div class="row-btns">
                            <a class="btn btn-ghost btn-sm" :href="success ? explorerTx(success.hash) : '#'" target="_blank" rel="noopener">ดูธุรกรรม ↗</a>
                            <button class="btn btn-primary btn-sm" type="button" x-show="tokenAddr" @click="addToken()">เพิ่ม IDTC ลงกระเป๋า</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="again()">ซื้อเพิ่ม</button>
                        </div>
                        <p class="muted-s" x-show="notice" x-text="notice"></p>
                    </div>
                </div>

                <div class="meter">
                    <div class="meter-head"><span>ขายแล้ว</span><b class="num"><span x-text="idtc(sold, 0)">0</span> IDTC</b></div>
                    <div class="bar" role="progressbar" aria-label="สัดส่วนที่ขายแล้ว" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" :aria-valuenow="pct"><i :style="`--w:${pct}%`"></i></div>
                    <div class="meter-foot">
                        <span><span x-text="pctText">0.00</span>% · เหลือ <span class="num" x-text="idtc(remaining, 0)">{{ number_format($cap) }}</span> IDTC</span>
                        <span>เพดานรวม <span x-text="idtc(cap / 1000000n, 0)">{{ number_format($cap / 1e6) }}</span> ล้าน</span>
                    </div>
                    <div class="meter-foot"><span x-text="loaded ? 'ระดมได้แล้ว ' + usdt(raised) + ' USDT' : (loadError ? 'ยังอ่านยอดจากสัญญาไม่ได้' : '{{ $live ? 'กำลังอ่านยอดจากสัญญา…' : 'รอเปิดสัญญา presale' }}')">{{ $live ? 'กำลังอ่านยอดจากสัญญา…' : 'รอเปิดสัญญา presale' }}</span></div>
                </div>
            </div>
        </section>

        {{-- ═════════ SIDE ═════════ --}}
        <aside class="side-stack">
            <div class="panel user-panel" x-show="account" x-cloak>
                <h3>กระเป๋าของคุณ <a class="addr" :href="account ? explorerAddress(account) : '#'" target="_blank" rel="noopener" x-text="account ? short(account) + ' ↗' : ''"></a></h3>
                <dl class="kv kv-tight">
                    <div><dt>USDT บนเชน TPIX</dt><dd x-text="usdt(usdtBal)">—</dd></div>
                    <div x-show="deployed"><dt>อนุญาตให้ presale ใช้</dt><dd x-text="usdt(allowance)">—</dd></div>
                    <div x-show="deployed"><dt>ซื้อไปแล้วใน presale</dt><dd x-text="idtc(purchased) + ' IDTC'">—</dd></div>
                    <div x-show="deployed && loaded"><dt>ซื้อได้อีก (เพดานต่อกระเป๋า)</dt><dd x-text="walletLeft === null ? 'ไม่จำกัด' : idtc(walletLeft) + ' IDTC'">—</dd></div>
                    <div x-show="tokenAddr"><dt>IDTC ในกระเป๋า</dt><dd x-text="idtc(tokenBal)">—</dd></div>
                </dl>
                <p class="muted-s" x-show="!deployed">สัญญา presale ยังไม่ขึ้นเชน ยอดด้านบนคือ USDT บนเชน TPIX ของคุณ</p>
                <div class="row-btns">
                    <button class="btn btn-ghost btn-sm" type="button" @click="loadUser()">รีเฟรชยอด</button>
                    <button class="btn btn-ghost btn-sm" type="button" x-show="payAddr" @click="addUsdt()">เพิ่ม USDT ลงกระเป๋า</button>
                </div>
            </div>

            <div class="panel">
                <h3>กติกาที่สัญญาบังคับ</h3>
                <ul class="rules">
                    <li>{!! $tick !!}<span>ราคาคงที่ตลอดรอบ 1 USDT = {{ number_format($rate, 2) }} IDTC ไม่มีฟังก์ชันแก้ราคา</span></li>
                    <li>{!! $tick !!}<span>ขายรวมได้ไม่เกิน {{ number_format($cap / 1e6) }} ล้าน IDTC</span></li>
                    <li>{!! $tick !!}<span>ได้เหรียญในธุรกรรมเดียวกับที่จ่าย ไม่มีขั้นรอเคลม</span></li>
                    <li>{!! $tick !!}<span>สัญญาไม่ถือเงินผู้ซื้อ USDT เข้ากระเป๋าโครงการทันที</span></li>
                    <li>{!! $tick !!}<span>ทีมงานถอนเหรียญที่ขายไม่หมดได้เฉพาะหลังปิดรอบหรือระหว่างหยุดขาย ไม่กระทบเหรียญที่ซื้อไปแล้ว</span></li>
                </ul>
                <p class="muted-s" style="margin-top:12px">ทีมงานหยุดขายชั่วคราว ปรับขั้นต่ำ เพดานต่อกระเป๋า และช่วงเวลาขายได้ ทุกคำสั่งบันทึกบนเชนและตรวจย้อนหลังได้</p>
            </div>

            <div class="panel">
                <h3>ยังไม่มี USDT บนเชน TPIX?</h3>
                <p class="muted-p">ส่ง USDT จาก BNB Chain ผ่านสะพานของ TPIX แล้วรับ USDT บนเชน TPIX ในอัตรา 1:1 จากนั้นกลับมาซื้อที่หน้านี้</p>
                <a class="btn btn-ghost btn-block" href="https://tpix.online/bridge" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17h18M5 17V9a7 7 0 0 1 14 0v8M9 17v-4M15 17v-4"/></svg>ไปที่สะพาน USDT (tpix.online)
                </a>
            </div>

            <div class="panel">
                <h3>ที่อยู่สัญญา @unless ($live)<span class="pill-wait">รอ deploy</span>@endunless</h3>
                <ul class="clist">
                    @foreach ($contracts as $c)
                        <li data-contract="{{ $c['key'] }}">
                            <span class="clist-name">{{ $c['name'] }}<small>{{ $c['kind'] }}</small></span>
                            @if ($c['address'])
                                <a class="addr" href="{{ $c['url'] }}" target="_blank" rel="noopener" title="{{ $c['address'] }}">{{ Idtc::short($c['address']) }} ↗</a>
                            @else
                                <span class="pill-wait">รอ deploy</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="muted-s" style="margin-top:12px">ตรวจซอร์สโค้ดและทุกธุรกรรมได้ที่ <a href="{{ config('idtc.chain.explorer') }}" target="_blank" rel="noopener">explorer.tpix.online</a> · ดูทั้งหมดที่หน้า <a href="{{ route('transparency') }}">ความโปร่งใส</a></p>
            </div>
        </aside>
    </div>
@endsection

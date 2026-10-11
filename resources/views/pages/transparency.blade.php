@extends('layouts.site')

@php
    use App\Support\ContractList;
    use App\Support\Idtc;

    $vestAddr = Idtc::contract('vesting');
    $live = $vestAddr !== null;
    $period = (int) config('idtc.vesting.period_seconds');
    $tranches = array_values((array) config('idtc.vesting.tranches', []));
    $plan = ['period' => $period, 'tranches' => $tranches];
    $total = array_sum(array_map(fn ($t) => (float) $t['amount'], $tranches));
    $cells = max(60, ...array_map(fn ($t) => (int) $t['cliff'] + (int) $t['release'], $tranches ?: [['cliff' => 0, 'release' => 0]]));
    $years = (int) ceil($cells / 12);
    $first = collect($tranches)->sortBy('cliff')->first();
    $million = fn ($v) => number_format($v / 1e6);
    $rows = ContractList::rows();
@endphp

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">ความโปร่งใส</p>
            <h1><span class="nowrap">เหรียญที่ล็อกไว้</span> <span class="grad nowrap">เห็นได้ทุกวินาที</span></h1>
            <p class="lead">ข้อมูลหน้านี้อ่านจากสัญญาบนเชน TPIX โดยตรง ไม่ผ่านฐานข้อมูลของเรา ตรวจทานได้เองบน explorer</p>
        </div>
    </div>

    <div class="wrap lock-page" x-data="lockDashboard(@js($plan))" data-vesting-state="{{ $live ? 'live' : 'pending-deploy' }}">
        @unless ($live)
            <div class="plan-banner" role="note">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <div>
                    <b>สัญญาล็อกยังไม่ขึ้นเชน — ตัวเลขด้านล่างคือแผน</b>
                    <p>ตารางนี้คือตารางเดียวกับที่จะฝังในสัญญา TrancheVesting เมื่อสัญญาขึ้นเชน หน้านี้จะอ่านยอดจริงจากสัญญาเองโดยอัตโนมัติ</p>
                </div>
            </div>
        @endunless
        <p class="plan-note warn" x-show="loadError" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span><span x-text="loadError"></span> · <button type="button" class="link-btn" @click="load()">ลองใหม่</button></span></p>
        <p class="plan-note warn" x-show="tokenMismatch" x-cloak><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5M12 18v.5"/></svg><span>สัญญาล็อกนี้ถือเหรียญคนละตัวกับ IDTC ที่ตั้งค่าไว้ ตรวจที่อยู่สัญญาอีกครั้ง</span></p>

        {{-- ═════════ ยอดรวม ═════════ --}}
        <section class="kpis" aria-label="ยอดรวมในสัญญาล็อก">
            <div class="kpi-tile">
                <span>ล็อกตามตาราง {{ $live ? '' : '(แผน)' }}</span>
                <b><span x-text="million(total)">{{ $million($total) }}</span> <small>ล้าน IDTC</small></b>
                <em class="mono" x-text="full(total, 0) + ' IDTC'">{{ number_format($total) }} IDTC</em>
            </div>
            <div class="kpi-tile">
                <span>ปลดไปแล้ว</span>
                <b><span x-text="million(released)">0</span> <small>ล้าน IDTC</small></b>
                <em x-text="live ? pctReleased.toFixed(2) + '% ของยอดล็อก' : deployed ? '…' : 'แผน: งวดแรกเดือนที่ {{ $first ? $first['cliff'] + 1 : '—' }}'">แผน: งวดแรกเดือนที่ {{ $first ? $first['cliff'] + 1 : '—' }}</em>
            </div>
            <div class="kpi-tile">
                <span>ยังล็อกอยู่</span>
                <b><span x-text="million(locked)">{{ $million($total) }}</span> <small>ล้าน IDTC</small></b>
                <em>ยังไม่ถึงเวลาปลด ไม่มีใครถอนได้</em>
            </div>
            <div class="kpi-tile">
                <span>เหรียญในสัญญาตอนนี้</span>
                <b x-show="deployed"><span x-text="million(balance)">…</span> <small>ล้าน IDTC</small></b>
                <b x-show="!deployed" class="kpi-wait">รอ deploy</b>
                <em>
                    <span class="pill-ok" x-show="funded === true" x-cloak>✓ ล็อกครบตามตาราง</span>
                    <span class="pill-warn" x-show="funded === false" x-cloak>เหรียญในสัญญายังไม่ครบตาราง</span>
                    @if ($live)
                        <span class="pill-wait" x-show="funded === null">กำลังตรวจ…</span>
                    @else
                        จะแสดงยอดจริงเมื่อสัญญาขึ้นเชน
                    @endif
                </em>
            </div>
        </section>

        <section class="panel lock-meta" aria-label="ข้อมูลสัญญาล็อก">
            <div>
                <span>สัญญาล็อก (TrancheVesting)</span>
                @if ($live)
                    <a class="addr" href="{{ Idtc::explorerAddress($vestAddr) }}" target="_blank" rel="noopener">{{ $vestAddr }} ↗</a>
                @else
                    <b><span class="pill-wait">รอ deploy</span></b>
                @endif
            </div>
            <div>
                <span>กระเป๋าผู้รับเมื่อปลด (beneficiary)</span>
                <a class="addr" x-show="beneficiary" x-cloak :href="beneficiary ? explorerAddress(beneficiary) : '#'" target="_blank" rel="noopener" x-text="beneficiary ? beneficiary + ' ↗' : ''"></a>
                <b x-show="!beneficiary">{{ $live ? '…' : 'กำหนดตอน deploy' }}</b>
            </div>
            <div>
                <span>เริ่มนับ (start)</span>
                <b x-text="live ? thaiDate(start, true) : 'วันเปิดตลาด · กำหนดตอน deploy'">{{ $live ? '…' : 'วันเปิดตลาด · กำหนดตอน deploy' }}</b>
            </div>
            <div>
                <span>ความยาว 1 งวด</span>
                <b><span class="num" x-text="period.toLocaleString('en-US')">{{ number_format($period) }}</span> วินาที (เดือนเฉลี่ย 365.25 วัน ÷ 12)</b>
            </div>
        </section>

        {{-- ═════════ งวดถัดไป + ปุ่มปลด ═════════ --}}
        <section class="panel next-panel" aria-label="งวดปลดถัดไป">
            <div class="next-info">
                <p class="kicker">งวดถัดไป</p>
                <h3 x-text="nextLabel">@if ($first)ก้อน "{{ $first['name'] }}" งวดที่ 1 · เดือนที่ {{ $first['cliff'] + 1 }} หลังวันเริ่มล็อก@endif</h3>
                <p class="muted-p" x-show="!live || nextAt">ปลด <b class="num" x-text="full(live ? nextAmount : nextAmountPlan, 0)">{{ $first ? number_format($first['amount'] / $first['release']) : '—' }}</b> IDTC ไปที่กระเป๋าผู้รับ · ใครก็กดปลดได้เมื่อถึงเวลา</p>
                <p class="muted-s" x-show="live && nextAt" x-cloak x-text="'ถึงเวลา ' + thaiDate(nextAt, true)"></p>
            </div>
            <div>
                <div class="countdown" aria-label="นับถอยหลังถึงงวดถัดไป">
                    <div><b x-text="cd ? cd.d.toLocaleString('en-US') : '—'">—</b><span>วัน</span></div>
                    <div><b x-text="cd ? pad(cd.h) : '—'">—</b><span>ชม.</span></div>
                    <div><b x-text="cd ? pad(cd.m) : '—'">—</b><span>นาที</span></div>
                    <div><b x-text="cd ? pad(cd.s) : '—'">—</b><span>วินาที</span></div>
                </div>
                @unless ($live)
                    <p class="muted-s cd-note">นับถอยหลังจะเริ่มเมื่อสัญญาขึ้นเชนและกำหนดวันเริ่มล็อก</p>
                @endunless
            </div>

            @if ($live)
                <div class="release-box" x-cloak>
                    <div class="release-row">
                        <div>
                            <span class="muted-s">ถึงเวลาปลดแล้ว รอคนกด</span>
                            <b class="num release-amt"><span x-text="full(releasable)">…</span> IDTC</b>
                        </div>
                        <button class="btn btn-primary" type="button" @click="askRelease()" :disabled="!canRelease || confirming">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2"/></svg>ปลดงวดที่ถึงเวลา
                        </button>
                    </div>
                    <p class="muted-s" x-show="loaded && releasable === 0n && !success">ยังไม่มีงวดที่ถึงเวลา ปุ่มจะกดได้เมื่อถึงงวดถัดไป</p>

                    <div class="confirm-box" x-show="confirming" x-cloak role="group" aria-label="ยืนยันการปลดงวด">
                        <p>จะเรียก <code>release()</code> บนสัญญาล็อก ปลด <b class="num" x-text="full(releasable)"></b> IDTC ไปที่กระเป๋าผู้รับ <a :href="beneficiary ? explorerAddress(beneficiary) : '#'" target="_blank" rel="noopener" class="mono" x-text="short(beneficiary)"></a> เท่านั้น (ไม่ใช่กระเป๋าของคุณ) · ค่าแก๊ส 0 TPIX</p>
                        <div class="row-btns">
                            <button class="btn btn-primary btn-sm" type="button" @click="release()" :disabled="busy">ยืนยัน ปลดงวด</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="cancelRelease()" :disabled="busy && phase === 'wait'">ยกเลิก</button>
                        </div>
                        <div class="flow-line" x-show="busy && phaseText" role="status">
                            <span class="spin" aria-hidden="true"></span><span x-text="phaseText"></span>
                            <a x-show="errorTx && phase === 'wait'" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">ดูบน explorer ↗</a>
                        </div>
                    </div>

                    <div class="flow-msg err" x-show="error && !busy" x-cloak role="alert">
                        <span x-text="error"></span>
                        <a x-show="errorTx" :href="errorTx ? explorerTx(errorTx) : '#'" target="_blank" rel="noopener">ดูรายการบน explorer ↗</a>
                    </div>
                    <div class="success" x-show="success" x-cloak role="status">
                        <span class="success-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></span>
                        <div>
                            <b>ปลดงวดสำเร็จ</b>
                            <p><span class="num" x-text="success && success.amount !== null ? full(success.amount) + ' IDTC' : 'เหรียญ'"></span> ไปที่กระเป๋าผู้รับแล้ว</p>
                            <div class="row-btns"><a class="btn btn-ghost btn-sm" :href="success ? explorerTx(success.hash) : '#'" target="_blank" rel="noopener">ดูธุรกรรม ↗</a></div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        {{-- ═════════ 4 ก้อนล็อก ═════════ --}}
        <div class="section-mini">
            <h2 class="h3">ตารางปลดทีละก้อน</h2>
            <div class="legend-row">
                <span><i class="lg lg-lock"></i>ช่วงล็อกเต็ม</span>
                <span><i class="lg lg-rel"></i>ถึงกำหนดปลดแล้ว</span>
                <span><i class="lg lg-due"></i>งวดที่รอปลด</span>
                <span x-show="live" x-cloak><i class="lg lg-now"></i>เดือนปัจจุบัน</span>
            </div>
        </div>

        @unless ($live)
            <section class="lock-grid" aria-label="ก้อนล็อกตามแผน">
                @foreach ($tranches as $t)
                    <article class="tranche">
                        <div class="tranche-top"><h3>{{ $t['name'] }}</h3><b>{{ $million($t['amount']) }} <small>ล้าน IDTC</small></b></div>
                        <div class="meta"><span>ล็อกเต็ม {{ $t['cliff'] }} เดือน · ปลด {{ $t['release'] }} งวด</span><span>งวดแรก เดือนที่ {{ $t['cliff'] + 1 }} หลังวันเริ่มล็อก</span></div>
                        <div class="timeline" style="--cells: {{ $cells }}" role="img" aria-label="ไทม์ไลน์ {{ $cells }} เดือน: ล็อก {{ $t['cliff'] }} เดือน แล้วปลด {{ $t['release'] }} งวด">
                            @for ($m = 0; $m < $cells; $m++)<i class="{{ $m < $t['cliff'] ? 'lock' : ($m < $t['cliff'] + $t['release'] ? 'due' : '') }}"></i>@endfor
                        </div>
                        <div class="tl-axis" style="--years: {{ $years }}">@for ($y = 1; $y <= $years; $y++)<span>ปีที่ {{ $y }}</span>@endfor</div>
                        <div class="meta"><span>ปลดครบเดือนที่ {{ $t['cliff'] + $t['release'] }}</span><span class="num">{{ number_format($t['amount'] / $t['release'], 2) }} IDTC ต่องวด</span></div>
                    </article>
                @endforeach
            </section>
        @else
            <section class="lock-grid" aria-label="ก้อนล็อกจากสัญญา">
                <template x-for="(t, i) in tranches" :key="i">
                    <article class="tranche">
                        <div class="tranche-top"><h3 x-text="t.name"></h3><b><span x-text="million(t.amount)"></span> <small>ล้าน IDTC</small></b></div>
                        <div class="meta"><span x-text="'ถึงกำหนดแล้ว ' + vestedCount(t) + '/' + t.release + ' งวด'"></span><span x-text="'งวดแรก ' + firstUnlock(t)"></span></div>
                        <div class="timeline" :style="`--cells:${cells}`" x-html="cellsFor(t)" role="img" :aria-label="`ไทม์ไลน์ ${cells} เดือน: ล็อก ${t.cliff} เดือน ปลดแล้ว ${vestedCount(t)} จาก ${t.release} งวด`"></div>
                        <div class="tl-axis" :style="`--years:${Math.ceil(cells / 12)}`"><template x-for="y in Math.ceil(cells / 12)" :key="y"><span x-text="'ปีที่ ' + y"></span></template></div>
                        <div class="meta"><span x-text="'ปลดครบ ' + lastUnlock(t)"></span><span class="num" x-text="full(perInstallment(t)) + ' IDTC ต่องวด'"></span></div>
                    </article>
                </template>
            </section>
        @endif

        {{-- ═════════ สัญญาทั้งหมด ═════════ --}}
        <section class="panel contracts-panel" aria-label="สัญญาทั้งหมดของ IDTC">
            <h3>สัญญาทั้งหมดของ IDTC <span class="muted-s">สถานะ verified ตรวจสดจาก explorer</span></h3>
            <div class="table-scroll">
                <table class="contracts">
                    <thead><tr><th>สัญญา</th><th>หน้าที่</th><th>ที่อยู่</th><th>สถานะ</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $c)
                            <tr data-contract="{{ $c['key'] }}">
                                <td data-label="สัญญา"><b>{{ $c['name'] }}</b><small class="mono">{{ $c['kind'] }}</small></td>
                                <td data-label="หน้าที่">{{ $c['role'] }}</td>
                                <td data-label="ที่อยู่">
                                    @if ($c['address'])
                                        <span class="addr-row" x-data="{ copied: false }">
                                            <a class="addr" href="{{ $c['url'] }}" target="_blank" rel="noopener">{{ $c['address'] }}</a>
                                            <button type="button" class="copy-btn" @click="navigator.clipboard?.writeText('{{ $c['address'] }}').then(() => { copied = true; setTimeout(() => copied = false, 1500) })" :aria-label="copied ? 'คัดลอกแล้ว' : 'คัดลอกที่อยู่'" title="คัดลอกที่อยู่">
                                                <svg x-show="!copied" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                                                <svg x-show="copied" x-cloak viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                            </button>
                                        </span>
                                    @else
                                        <span class="addr">รอ deploy</span>
                                    @endif
                                </td>
                                <td data-label="สถานะ" @class(['is-pending' => ! $c['address']])>
                                    @if ($c['address'])
                                        <span x-data="verifyBadge('{{ $c['address'] }}')"><a href="{{ $c['url'] }}" target="_blank" rel="noopener" class="vpill" data-v="unknown" :data-v="state" x-text="label">ตรวจบน explorer ↗</a></span>
                                    @else
                                        <span class="pill-wait">รอ deploy</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted-s" style="margin-top:14px">ตรวจทุกที่อยู่ได้เองที่ <a href="{{ config('idtc.chain.explorer') }}" target="_blank" rel="noopener">explorer.tpix.online</a> · Chain ID {{ config('idtc.chain.id') }}</p>
        </section>
    </div>
@endsection

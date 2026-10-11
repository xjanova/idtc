@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    use App\Services\Chain\Address;
    use App\Services\Chain\Units;
    use App\Support\Idtc;

    $sale = config('idtc.sale');
    $v = $p['v'] ?? [];
    $ok = $p['deployed'] && $p['ok'];
    $pct = $ok && $v['saleCap'] !== '0' ? min(100, Units::toFloat($v['sold'], 18) / max(1, Units::toFloat($v['saleCap'], 18)) * 100) : 0;
    $pending = $ok && Address::isValid($v['pendingOwner']) && ! Address::isZero($v['pendingOwner']) ? $v['pendingOwner'] : null;
    $rateText = number_format(1e6 * (float) $sale["rate_num"] / (float) $sale["rate_den"] / 1e18, 2);
    $check = fn (?bool $b) => $b === null ? '<span class="pill-wait">อ่านไม่ได้</span>' : ($b ? '<span class="pill-ok">✓ ตรงแผน</span>' : '<span class="pill-bad">✗ ไม่ตรงแผน</span>');
@endphp

@section('sub')
    สัญญา FixedPriceSale · ราคาฝังในสัญญา เปลี่ยนไม่ได้ · ค่าอ่านจากเชน (แคช 15 วินาที)
@endsection

@section('content')
    @if (! $p['deployed'])
        <div class="grid-2">
            <div class="panel"><h3>สถานะ @include('admin.partials.presale-pill')</h3><x-admin.pending-deploy what="สัญญา presale"/></div>
            <div class="panel">
                <h3>แผนจาก config <span class="meta">ใช้ตรวจสัญญาเมื่อขึ้นเชน</span></h3>
                <div class="kv">
                    <div><span>ราคา</span><b>${{ $sale['price_usd'] }} ต่อ IDTC</b></div>
                    <div><span>อัตรา</span><b>1 USDT = {{ $rateText }} IDTC</b></div>
                    <div><span>เพดานขายรวม</span><b>{{ number_format((float) $sale['cap']) }} IDTC</b></div>
                    <div><span>จ่ายด้วย</span><b>{{ $sale['pay_symbol'] }} (ทศนิยม {{ $sale['pay_decimals'] }})</b></div>
                </div>
                <p class="fine">คำสั่งควบคุม (หยุดขาย ตั้งเวลา ตั้งเพดาน เปลี่ยนกระเป๋ารับเงิน ถอนเหรียญ) จะเปิดใช้ทันทีที่ตั้งที่อยู่สัญญา</p>
            </div>
        </div>
    @elseif (! $p['ok'])
        <div class="flash err" role="alert">อ่านสัญญา presale ไม่ได้: {{ $p['error'] }} <a href="{{ route('admin.presale') }}">ลองใหม่</a></div>
        <div class="panel"><div class="kv"><div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$p['address']" full/></b></div></div></div>
    @else
        <div class="grid-2">
            <div class="panel">
                <h3>สถานะบนเชน @include('admin.partials.presale-pill')</h3>
                <div class="meter-head"><span>ขายแล้ว</span><b>{{ number_format($pct, 2) }}%</b></div>
                <div class="bar" style="margin-bottom:14px"><i style="--w: {{ round($pct, 2) }}%"></i></div>
                <div class="kv">
                    <div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$p['address']"/></b></div>
                    <div><span>ขายไปแล้ว</span><b>{{ F::idtc($v['sold']) }} IDTC</b></div>
                    <div><span>USDT ที่ได้ (raised)</span><b>{{ F::usdt($v['raised']) }} USDT</b></div>
                    <div><span>เหลือขายถึงเพดาน</span><b>{{ F::idtc($p['remaining']) }} IDTC</b></div>
                    <div><span>เหรียญในสัญญา</span><b @class(['ok' => $p['funded'] === true, 'bad' => $p['funded'] === false])>{{ F::idtc($p['balance']) }} IDTC {{ $p['funded'] === true ? '· พอขาย' : ($p['funded'] === false ? '· ฝากยังไม่ครบ' : '') }}</b></div>
                    <div><span>ช่วงเวลาขาย</span><b>{{ F::dateTime($p['start']) }} → {{ F::dateTime($p['end']) }}</b></div>
                    <div><span>ซื้อขั้นต่ำ</span><b>{{ $v['minPurchase'] === '0' ? 'ไม่กำหนด' : F::usdt($v['minPurchase'], 6).' USDT' }}</b></div>
                    <div><span>เพดานต่อกระเป๋า</span><b>{{ $v['maxPerWallet'] === '0' ? 'ไม่จำกัด' : F::idtc($v['maxPerWallet']).' IDTC' }}</b></div>
                    <div><span>กระเป๋ารับเงิน</span><b><x-admin.addr :address="$v['treasury']"/></b></div>
                    <div><span>เจ้าของสัญญา</span><b><x-admin.addr :address="$v['owner']"/></b></div>
                    @if ($pending)<div><span>เจ้าของใหม่ที่รอรับสิทธิ์</span><b><x-admin.addr :address="$pending"/></b></div>@endif
                </div>
            </div>
            <div class="stack">
                <div class="panel">
                    <h3>ตรวจกับแผน <span class="meta">config/idtc.php</span></h3>
                    <div class="kv">
                        <div><span>อัตรา (1 USDT = {{ $rateText }} IDTC)</span><b>{!! $check($p['plan']['rate']) !!}</b></div>
                        <div><span>เพดานขายรวม {{ number_format((float) $sale['cap']) }}</span><b>{!! $check($p['plan']['cap']) !!}</b></div>
                        <div><span>รับชำระด้วย USDT ของเชน TPIX</span><b>{!! $check($p['plan']['pay_token']) !!}</b></div>
                    </div>
                    <p class="fine">ราคาและเพดานรวมฝังตั้งแต่ deploy — ถ้าไม่ตรงแผน ต้อง deploy สัญญาใหม่ ไม่มีปุ่มแก้</p>
                </div>
                @include('admin.partials.recent-tx')
            </div>
        </div>

        <div class="panel section-gap">
            <h3>คำสั่งควบคุม <span class="meta">เซ็นด้วยกระเป๋าเจ้าของสัญญา ({{ Idtc::short(F::addr($v['owner'])) }})</span></h3>
            @if (! $me->canWrite())
                <p class="readonly-note">สิทธิ์ของคุณดูได้อย่างเดียว — คำสั่งควบคุมใช้ได้เฉพาะแอดมินและเจ้าของ</p>
            @else
                <div class="grid-2">
                    <div>
                        <div class="act danger" id="pause" x-data="chainAction('{{ $v['paused'] ? 'presale.unpause' : 'presale.pause' }}')">
                            <div class="act-head"><div><b>{{ $v['paused'] ? 'เปิดขายต่อ' : 'หยุดขายชั่วคราว' }}</b><p>{{ $v['paused'] ? 'ตอนนี้หยุดอยู่ — ผู้ซื้อกดซื้อไม่ได้' : 'ใช้เมื่อมีเหตุผิดปกติ ผู้ซื้อจะกดซื้อไม่ได้ทันที' }}</p></div>
                                <button class="btn btn-sm {{ $v['paused'] ? 'btn-primary' : 'btn-danger' }}" type="button" @click="run()" :disabled="busy">{{ $v['paused'] ? 'เปิดขายต่อ' : 'หยุดขายชั่วคราว' }}</button></div>
                            @include('admin.partials.action-state')
                        </div>

                        <form class="act" id="limits" x-data="chainAction('presale.setLimits', @js(['min' => \App\Services\Chain\Units::fromRaw($v['minPurchase'], 6), 'max' => \App\Services\Chain\Units::fromRaw($v['maxPerWallet'], 18)]))" @submit.prevent="run()">
                            <div class="act-head"><div><b>ยอดซื้อขั้นต่ำ / เพดานต่อกระเป๋า</b><p>0 = ไม่กำหนด/ไม่จำกัด</p></div></div>
                            <div class="form-row">
                                <label class="field"><span>ซื้อขั้นต่ำต่อครั้ง (USDT)</span><input class="input mono" x-model="f.min" inputmode="decimal" autocomplete="off" required></label>
                                <label class="field"><span>เพดานต่อกระเป๋า (IDTC)</span><input class="input mono" x-model="f.max" inputmode="decimal" autocomplete="off"></label>
                            </div>
                            <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกบนเชน</button></div>
                            @include('admin.partials.action-state')
                        </form>

                        <form class="act" id="window" x-data="chainAction('presale.setWindow', @js(['start' => F::inputValue($p['start']), 'end' => F::inputValue($p['end'])]))" @submit.prevent="run()">
                            <div class="act-head"><div><b>ช่วงเวลาขาย</b><p>เวลาไทย (UTC+7) — เวลาปิดต้องหลังเวลาเปิด</p></div></div>
                            <div class="form-row">
                                <label class="field"><span>เปิดขาย</span><input class="input" type="datetime-local" x-model="f.start" required></label>
                                <label class="field"><span>ปิดขาย</span><input class="input" type="datetime-local" x-model="f.end" required></label>
                            </div>
                            <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกบนเชน</button></div>
                            @include('admin.partials.action-state')
                        </form>
                    </div>

                    <div>
                        <form class="act danger" id="treasury" x-data="chainAction('presale.setTreasury', { address: '' })" @submit.prevent="run()">
                            <div class="act-head"><div><b>เปลี่ยนกระเป๋ารับเงิน</b><p>ตอนนี้: <span class="mono">{{ F::addr($v['treasury']) }}</span></p></div></div>
                            <label class="field"><span>ที่อยู่กระเป๋ารับเงินใหม่</span><input class="input mono" x-model="f.address" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                            <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">เปลี่ยนกระเป๋ารับเงิน…</button></div>
                            @include('admin.partials.action-state')
                        </form>

                        <form class="act danger" id="withdraw" x-data="chainAction('presale.withdrawUnsold', @js(['to' => '', 'balanceText' => F::idtc($p['balance']).' IDTC']))" @submit.prevent="run()">
                            <div class="act-head"><div><b>ถอนเหรียญที่ขายไม่หมด</b><p>ในสัญญาตอนนี้ {{ F::idtc($p['balance']) }} IDTC</p></div>
                                @unless ($p['can_withdraw'])<span class="pill-wait">ยังถอนไม่ได้</span>@endunless</div>
                            @if ($p['can_withdraw'])
                                <label class="field"><span>ส่งไปที่</span><input class="input mono" x-model="f.to" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                                <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">ถอนเหรียญ…</button></div>
                            @else
                                <p class="fine">ถอนได้เฉพาะหลังปิดรอบ ({{ F::dateTime($p['end']) }}) หรือระหว่างหยุดขายชั่วคราว — สัญญาบังคับเอง กันถอนระหว่างที่คนกำลังซื้อ</p>
                            @endif
                            @include('admin.partials.action-state')
                        </form>

                        <form class="act danger" id="owner" x-data="chainAction('presale.transferOwnership', { address: '' })" @submit.prevent="run()">
                            <div class="act-head"><div><b>โอนสิทธิ์เจ้าของสัญญา</b><p>สองขั้น: เริ่มโอน → กระเป๋าใหม่กดรับเอง</p></div></div>
                            <label class="field"><span>ที่อยู่เจ้าของใหม่</span><input class="input mono" x-model="f.address" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                            <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">เริ่มโอนสิทธิ์…</button></div>
                            @include('admin.partials.action-state')
                        </form>

                        @if ($pending)
                            <div class="act" x-data="chainAction('presale.acceptOwnership')">
                                <div class="act-head"><div><b>รับสิทธิ์เจ้าของ</b><p>สำหรับกระเป๋า <span class="mono">{{ F::addr($pending) }}</span> เท่านั้น</p></div>
                                    <button class="btn btn-primary btn-sm" type="button" @click="run()" :disabled="busy">รับสิทธิ์เจ้าของ</button></div>
                                @include('admin.partials.action-state')
                            </div>
                        @endif
                    </div>
                </div>
                <p class="sig"><x-admin.icon name="shield"/>ทุกคำสั่งตรวจสิทธิ์และจำลองบนเชนก่อน แล้วให้คุณยืนยันในหน้านี้ จากนั้นเซ็นในกระเป๋าของคุณเอง — เซิร์ฟเวอร์ไม่ถือคีย์</p>
            @endif
        </div>
    @endif
@endsection

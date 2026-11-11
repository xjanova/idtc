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

    // สัญญาเติมพูล (PoolFeeder) — ขายผ่าน TPIX Launchpad: เป็นเจ้าของถาวรและกระเป๋ารับเงินของ presale
    $fv = $fd['v'] ?? [];
    $fdOk = $fd['deployed'] && $fd['ok'];
    $fdPending = $fdOk && Address::isValid($fv['pendingOwner']) && ! Address::isZero($fv['pendingOwner']) ? $fv['pendingOwner'] : null;
    $unlockText = $fdOk ? F::dateTime($fd['lp_unlock']) : '';
    $act = $viaFeeder
        ? ['pause' => 'feeder.pauseSale', 'unpause' => 'feeder.unpauseSale', 'limits' => 'feeder.setSaleLimits', 'window' => 'feeder.setSaleWindow', 'unsold' => 'feeder.withdrawUnsold']
        : ['pause' => 'presale.pause', 'unpause' => 'presale.unpause', 'limits' => 'presale.setLimits', 'window' => 'presale.setWindow', 'unsold' => 'presale.withdrawUnsold'];
    $controlOwner = $viaFeeder ? ($fv['owner'] ?? null) : ($v['owner'] ?? null);
    $canWithdrawUnsold = $viaFeeder ? ($fdOk && $fd['unlocked']) : ($p['can_withdraw'] ?? false);
    // ยังไม่ได้ตั้งสัญญาเติมพูล แต่เจ้าของกับกระเป๋ารับเงินเป็นที่อยู่เดียวกัน — ลักษณะของการขายผ่าน launchpad
    $looksLaunchpad = $ok && ! Idtc::isDeployed('feeder') && Address::isValid($v['owner']) && Address::same($v['owner'], $v['treasury']);
    $bandText = fn (array $b) => match ($b['state']) {
        'empty' => '<span class="pill-wait">พูลยังว่าง</span> เติมครั้งแรกตั้งราคาตามราคาขายพอดี',
        'in' => '<span class="pill-ok">✓ อยู่ในกรอบ</span> '.number_format($b['percent'], 2).'% ของราคาขาย',
        'out' => '<span class="pill-bad">นอกกรอบ</span> '.number_format($b['percent'], 2).'% ของราคาขาย — เติมพูลไม่ได้จนราคากลับเข้ากรอบ (เงินรออยู่ในสัญญา)',
        default => '<span class="pill-wait">อ่านไม่ได้</span>',
    };
    $bps = fn (?string $n) => $n === null ? '—' : rtrim(rtrim(number_format(((int) $n) / 100, 2), '0'), '.').'%';
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
                <p class="fine">คำสั่งควบคุม (หยุดขาย ตั้งเวลา ตั้งเพดาน ถอนเหรียญ) จะเปิดใช้ทันทีที่ตั้งที่อยู่สัญญา — ถ้าขายผ่าน TPIX Launchpad ให้ตั้งที่อยู่สัญญาเติมพูลด้วย</p>
            </div>
        </div>
    @elseif (! $p['ok'])
        <div class="flash err" role="alert">อ่านสัญญา presale ไม่ได้: {{ $p['error'] }} <a href="{{ route('admin.presale') }}">ลองใหม่</a></div>
        <div class="panel"><div class="kv"><div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$p['address']" full/></b></div></div></div>
    @else
        @if ($feederMismatch)
            <div class="flash err" role="alert">สัญญาเติมพูลที่ตั้งไว้ (<span class="mono">{{ Idtc::short((string) Idtc::contract('feeder')) }}</span>) ไม่ใช่เจ้าของ presale ตัวนี้ — คำสั่งด้านล่างจึงสั่งที่ presale ตรง ตรวจที่อยู่ทั้งสองในหน้า <a href="{{ route('admin.settings') }}">ตั้งค่าเหรียญ</a></div>
        @elseif ($looksLaunchpad)
            <div class="flash warn" role="status">เจ้าของ presale กับกระเป๋ารับเงินเป็นที่อยู่เดียวกัน — ถ้าเปิดขายผ่าน TPIX Launchpad ที่อยู่นี้คือสัญญาเติมพูล ให้ตั้งที่ “สัญญาเติมพูล” ในหน้า <a href="{{ route('admin.settings') }}">ตั้งค่าเหรียญ</a> แล้วคำสั่งด้านล่างจะสั่งผ่านสัญญานั้นให้</div>
        @endif
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
                    <div><span>กระเป๋ารับเงิน{{ $viaFeeder ? ' (สัญญาเติมพูล)' : '' }}</span><b><x-admin.addr :address="$v['treasury']"/></b></div>
                    <div><span>เจ้าของสัญญา{{ $viaFeeder ? ' (สัญญาเติมพูล)' : '' }}</span><b><x-admin.addr :address="$v['owner']"/></b></div>
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
            <h3>คำสั่งควบคุม <span class="meta">{{ $viaFeeder ? 'สั่งผ่านสัญญาเติมพูล · เซ็นด้วยกระเป๋าเจ้าของสัญญาเติมพูล' : 'เซ็นด้วยกระเป๋าเจ้าของสัญญา' }} ({{ $controlOwner ? Idtc::short(F::addr($controlOwner)) : 'อ่านไม่ได้' }})</span></h3>
            @if (! $me->canEdit('presale'))
                <p class="readonly-note">สิทธิ์ของคุณดูได้อย่างเดียว — คำสั่งควบคุมใช้ได้เฉพาะแอดมินและเจ้าของ</p>
            @elseif ($viaFeeder && ! $fdOk)
                <p class="readonly-note">อ่านสัญญาเติมพูลไม่ได้ — คำสั่งควบคุมของ presale ต้องสั่งผ่านสัญญานั้น <a href="{{ route('admin.presale') }}">ลองใหม่</a></p>
            @else
                <div class="grid-2">
                    <div>
                        <div class="act danger" id="pause" x-data="chainAction('{{ $v['paused'] ? $act['unpause'] : $act['pause'] }}')">
                            <div class="act-head"><div><b>{{ $v['paused'] ? 'เปิดขายต่อ' : 'หยุดขายชั่วคราว' }}</b><p>{{ $v['paused'] ? 'ตอนนี้หยุดอยู่ — ผู้ซื้อกดซื้อไม่ได้' : 'ใช้เมื่อมีเหตุผิดปกติ ผู้ซื้อจะกดซื้อไม่ได้ทันที' }}</p></div>
                                <button class="btn btn-sm {{ $v['paused'] ? 'btn-primary' : 'btn-danger' }}" type="button" @click="run()" :disabled="busy">{{ $v['paused'] ? 'เปิดขายต่อ' : 'หยุดขายชั่วคราว' }}</button></div>
                            @include('admin.partials.action-state')
                        </div>

                        <form class="act" id="limits" x-data="chainAction('{{ $act['limits'] }}', @js(['min' => Units::fromRaw($v['minPurchase'], 6), 'max' => Units::fromRaw($v['maxPerWallet'], 18)]))" @submit.prevent="run()">
                            <div class="act-head"><div><b>ยอดซื้อขั้นต่ำ / เพดานต่อกระเป๋า</b><p>0 = ไม่กำหนด/ไม่จำกัด</p></div></div>
                            <div class="form-row">
                                <label class="field"><span>ซื้อขั้นต่ำต่อครั้ง (USDT)</span><input class="input mono" x-model="f.min" inputmode="decimal" autocomplete="off" required></label>
                                <label class="field"><span>เพดานต่อกระเป๋า (IDTC)</span><input class="input mono" x-model="f.max" inputmode="decimal" autocomplete="off"></label>
                            </div>
                            <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกบนเชน</button></div>
                            @include('admin.partials.action-state')
                        </form>

                        <form class="act" id="window" x-data="chainAction('{{ $act['window'] }}', @js(['start' => F::inputValue($p['start']), 'end' => F::inputValue($p['end'])] + ($viaFeeder ? ['endLimit' => $fd['sale_end_limit'], 'endLimitText' => F::dateTime($fd['sale_end_limit'])] : [])))" @submit.prevent="run()">
                            <div class="act-head"><div><b>ช่วงเวลาขาย</b><p>เวลาไทย (UTC+7) — เวลาปิดต้องหลังเวลาเปิด{{ $viaFeeder ? ' และไม่เกิน '.F::dateTime($fd['sale_end_limit']).' (ล็อกไว้ตอนสร้างการขาย ขยายไม่ได้ เลื่อนหรือย่นได้)' : '' }}</p></div></div>
                            <div class="form-row">
                                <label class="field"><span>เปิดขาย</span><input class="input" type="datetime-local" x-model="f.start" required></label>
                                <label class="field"><span>ปิดขาย</span><input class="input" type="datetime-local" x-model="f.end" required></label>
                            </div>
                            <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกบนเชน</button></div>
                            @include('admin.partials.action-state')
                        </form>
                    </div>

                    <div>
                        @unless ($viaFeeder)
                            <form class="act danger" id="treasury" x-data="chainAction('presale.setTreasury', { address: '' })" @submit.prevent="run()">
                                <div class="act-head"><div><b>เปลี่ยนกระเป๋ารับเงิน</b><p>ตอนนี้: <span class="mono">{{ F::addr($v['treasury']) }}</span></p></div></div>
                                <label class="field"><span>ที่อยู่กระเป๋ารับเงินใหม่</span><input class="input mono" x-model="f.address" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                                <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">เปลี่ยนกระเป๋ารับเงิน…</button></div>
                                @include('admin.partials.action-state')
                            </form>
                        @endunless

                        <form class="act danger" id="withdraw" x-data="chainAction('{{ $act['unsold'] }}', @js(['to' => '', 'balanceText' => F::idtc($p['balance']).' IDTC']))" @submit.prevent="run()">
                            <div class="act-head"><div><b>ถอนเหรียญที่ขายไม่หมด</b><p>ในสัญญาตอนนี้ {{ F::idtc($p['balance']) }} IDTC</p></div>
                                @unless ($canWithdrawUnsold)<span class="pill-wait">ยังถอนไม่ได้</span>@endunless</div>
                            @if ($canWithdrawUnsold)
                                <label class="field"><span>ส่งไปที่</span><input class="input mono" x-model="f.to" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                                <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">ถอนเหรียญ…</button></div>
                            @elseif ($viaFeeder)
                                <p class="fine">ถอนได้หลังปลดล็อก LP ({{ $unlockText }}) เท่านั้น — สัญญาเติมพูลบังคับเอง แม้หยุดขายชั่วคราวก็ถอนก่อนไม่ได้</p>
                            @else
                                <p class="fine">ถอนได้เฉพาะหลังปิดรอบ ({{ F::dateTime($p['end']) }}) หรือระหว่างหยุดขายชั่วคราว — สัญญาบังคับเอง กันถอนระหว่างที่คนกำลังซื้อ</p>
                            @endif
                            @include('admin.partials.action-state')
                        </form>

                        @if ($viaFeeder)
                            <div class="act">
                                <div class="act-head"><div><b>กระเป๋ารับเงินและเจ้าของ presale</b><p>ล็อกถาวร: USDT จากการขายเข้าสัญญาเติมพูลเสมอ และโอนเจ้าของ presale ไม่ได้ — สัญญาเติมพูลไม่มีคำสั่งให้เปลี่ยน (กันการเปลี่ยนทางเงินของผู้ซื้อ)</p></div></div>
                            </div>
                        @else
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
                        @endif
                    </div>
                </div>
                <p class="sig"><x-admin.icon name="shield"/>ทุกคำสั่งตรวจสิทธิ์และจำลองบนเชนก่อน แล้วให้คุณยืนยันในหน้านี้ จากนั้นเซ็นในกระเป๋าของคุณเอง — เซิร์ฟเวอร์ไม่ถือคีย์</p>
            @endif
        </div>
    @endif

    @if ($fd['deployed'])
        <div class="panel section-gap" id="feeder">
            <h3>สัญญาเติมพูล <span class="meta">PoolFeeder จาก TPIX Launchpad · เงินขายเข้าพูล IDTC/USDT · LP ล็อกในสัญญา</span></h3>
            @if (! $fdOk)
                <div class="flash err" role="alert">อ่านสัญญาเติมพูลไม่ได้: {{ $fd['error'] }} <a href="{{ route('admin.presale') }}">ลองใหม่</a></div>
                <div class="kv"><div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$fd['address']" full/></b></div></div>
            @else
                <div class="grid-2">
                    <div class="kv">
                        <div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$fd['address']"/></b></div>
                        <div><span>ผูกกับ presale</span><b>{!! $fd['bound'] === null ? '<span class="pill-wait">ยังไม่ได้ตั้ง presale</span>' : ($fd['bound'] ? '<span class="pill-ok">✓ ตัวที่ตั้งไว้</span>' : '<span class="pill-bad">✗ คนละตัวกับที่ตั้งไว้</span>') !!}</b></div>
                        <div><span>ส่วนของเงินขายที่เข้าพูล</span><b>{{ $bps($fv['poolShareBps']) }}</b></div>
                        <div><span>กรอบราคาที่เติมพูลได้</span><b>±{{ $bps($fv['bandBps']) }} ของราคาขาย</b></div>
                        <div><span>ราคาพูลตอนนี้</span><b>{!! $bandText($fd['band']) !!}</b></div>
                        <div><span>USDT รอเข้าพูล</span><b>{{ F::usdt($fv['pendingQuote'], 6) }} USDT <span class="meta">เติมได้เมื่อถึง {{ F::usdt($fv['minFeed'], 6) }} USDT</span></b></div>
                        <div><span>IDTC สำรองไว้จับคู่</span><b>{{ F::idtc($fv['tokenReserve']) }} IDTC</b></div>
                        <div><span>LP ที่ล็อกในสัญญา</span><b>{{ $fd['lp_balance'] === null ? 'ยังไม่มีพูล' : Units::format($fd['lp_balance'], 18, 6).' LP' }}</b></div>
                        <div><span>เข้าพูลแล้วรวม</span><b>{{ F::usdt($fv['totalQuoteToPool']) }} USDT + {{ F::idtc($fv['totalTokenToPool']) }} IDTC</b></div>
                        @if ($fv['poolShareBps'] !== '10000')<div><span>ส่งเข้ากระเป๋าโครงการแล้ว</span><b>{{ F::usdt($fv['totalQuoteToProject']) }} USDT</b></div>@endif
                        <div><span>กระเป๋าโครงการ</span><b><x-admin.addr :address="$fv['projectTreasury']"/></b></div>
                        <div><span>ปลดล็อก LP</span><b @class(['ok' => $fd['unlocked']])>{{ $unlockText }} {{ $fd['unlocked'] ? '· ปลดแล้ว' : '· ล็อกอยู่' }}</b></div>
                        <div><span>ปิดขายได้ไม่เกิน</span><b>{{ F::dateTime($fd['sale_end_limit']) }}</b></div>
                        <div><span>เจ้าของสัญญาเติมพูล</span><b><x-admin.addr :address="$fv['owner']"/></b></div>
                        @if ($fdPending)<div><span>เจ้าของใหม่ที่รอรับสิทธิ์</span><b><x-admin.addr :address="$fdPending"/></b></div>@endif
                    </div>

                    <div>
                        @if (! $me->canEdit('presale'))
                            <p class="readonly-note">สิทธิ์ของคุณดูได้อย่างเดียว — คำสั่งของสัญญาเติมพูลใช้ได้เฉพาะแอดมินและเจ้าของ</p>
                        @else
                            <div class="act" id="feed" x-data="chainAction('feeder.feed', @js(['pendingText' => F::usdt($fv['pendingQuote'], 6).' USDT', 'shareText' => $bps($fv['poolShareBps']), 'unlockText' => $unlockText]))">
                                <div class="act-head"><div><b>เติมพูล</b><p>นำ USDT ที่รออยู่เข้าพูลพร้อม IDTC สำรอง ตามราคาในพูล — ใครกดก็ได้ ไม่มีเงินออกจากระบบ</p></div>
                                    <button class="btn btn-primary btn-sm" type="button" @click="run()" :disabled="busy">เติมพูล</button></div>
                                @include('admin.partials.action-state')
                            </div>

                            <form class="act danger" id="project-treasury" x-data="chainAction('feeder.setProjectTreasury', { address: '' })" @submit.prevent="run()">
                                <div class="act-head"><div><b>เปลี่ยนกระเป๋าโครงการ</b><p>รับส่วนแบ่งโครงการ (ถ้ามี) และเหรียญที่หลงเข้าพูลว่างตอนเติมครั้งแรก — ตอนนี้: <span class="mono">{{ F::addr($fv['projectTreasury']) }}</span></p></div></div>
                                <label class="field"><span>ที่อยู่กระเป๋าโครงการใหม่</span><input class="input mono" x-model="f.address" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                                <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">เปลี่ยนกระเป๋าโครงการ…</button></div>
                                @include('admin.partials.action-state')
                            </form>

                            @if ($fd['unlocked'])
                                @foreach ([
                                    ['feeder.withdrawLiquidity', 'ถอน LP', 'LP ทั้งหมดที่สัญญาถือ ('.($fd['lp_balance'] === null ? '0' : Units::format($fd['lp_balance'], 18, 6)).' LP)', 'ถอน LP…'],
                                    ['feeder.withdrawLeftoverQuote', 'ถอน USDT ที่ค้าง', 'USDT ที่ไม่ได้เข้าพูล ('.F::usdt($fv['pendingQuote'], 6).' USDT)', 'ถอน USDT…'],
                                    ['feeder.withdrawReserve', 'ถอน IDTC สำรองที่เหลือ', 'IDTC สำรองที่ไม่ได้จับคู่ ('.F::idtc($fv['tokenReserve']).' IDTC) — ต้องเติมหรือถอน USDT ที่ค้างก่อน', 'ถอน IDTC…'],
                                ] as [$wAction, $wTitle, $wWhat, $wButton])
                                    <form class="act danger" x-data="chainAction('{{ $wAction }}', @js(['to' => '', 'whatText' => $wWhat]))" @submit.prevent="run()">
                                        <div class="act-head"><div><b>{{ $wTitle }}</b><p>{{ $wWhat }}</p></div></div>
                                        <label class="field"><span>ส่งไปที่</span><input class="input mono" x-model="f.to" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                                        <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">{{ $wButton }}</button></div>
                                        @include('admin.partials.action-state')
                                    </form>
                                @endforeach
                            @else
                                <div class="act">
                                    <div class="act-head"><div><b>ถอน LP · USDT ที่ค้าง · IDTC สำรอง</b><p>ทำได้หลัง {{ $unlockText }} เท่านั้น — ก่อนนั้นไม่มีใครถอนได้ รวมถึงเจ้าของ (สัญญาบังคับเอง)</p></div><span class="pill-wait">ล็อกอยู่</span></div>
                                </div>
                            @endif

                            <form class="act danger" id="feeder-owner" x-data="chainAction('feeder.transferOwnership', { address: '' })" @submit.prevent="run()">
                                <div class="act-head"><div><b>โอนสิทธิ์เจ้าของสัญญาเติมพูล</b><p>สองขั้น: เริ่มโอน → กระเป๋าใหม่กดรับเอง · เจ้าของสัญญาเติมพูลคือผู้สั่งงาน presale</p></div></div>
                                <label class="field"><span>ที่อยู่เจ้าของใหม่</span><input class="input mono" x-model="f.address" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                                <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">เริ่มโอนสิทธิ์…</button></div>
                                @include('admin.partials.action-state')
                            </form>

                            @if ($fdPending)
                                <div class="act" x-data="chainAction('feeder.acceptOwnership')">
                                    <div class="act-head"><div><b>รับสิทธิ์เจ้าของสัญญาเติมพูล</b><p>สำหรับกระเป๋า <span class="mono">{{ F::addr($fdPending) }}</span> เท่านั้น</p></div>
                                        <button class="btn btn-primary btn-sm" type="button" @click="run()" :disabled="busy">รับสิทธิ์เจ้าของ</button></div>
                                    @include('admin.partials.action-state')
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
@endsection

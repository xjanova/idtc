@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    use App\Services\Chain\Address;
    use App\Services\Chain\Units;
    use App\Support\Idtc;

    $plan = config('idtc.vesting');
    $ok = $vst['deployed'] && $vst['ok'];
    $v = $vst['v'] ?? [];
    $pending = $ok && Address::isValid($v['pendingBeneficiary']) && ! Address::isZero($v['pendingBeneficiary']) ? $v['pendingBeneficiary'] : null;
    $releasable = $ok ? (string) $v['releasable'] : '0';
    $vestedPct = $ok && $v['totalAllocation'] !== '0' ? (1 - Units::toFloat($v['lockedAmount'], 18) / max(1, Units::toFloat($v['totalAllocation'], 18))) * 100 : 0;
@endphp

@section('sub')
    สัญญา TrancheVesting · ไม่มีเจ้าของ ไม่มีปุ่มถอนก่อนกำหนด · ใครก็กดปลดงวดที่ถึงเวลาได้ แต่เหรียญไปที่ผู้รับเท่านั้น
@endsection

@section('content')
    @if (! $vst['deployed'])
        <div class="grid-2">
            <div class="panel"><h3>สัญญาล็อก <span class="pill-wait">รอ deploy</span></h3><x-admin.pending-deploy what="สัญญาล็อก 70%"/></div>
            <div class="panel">
                <h3>ตารางตามแผน <span class="meta">config/idtc.php · งวดละ {{ number_format($plan['period_seconds'] / 86400, 2) }} วัน</span></h3>
                <div class="table-scroll"><table class="tbl">
                    <thead><tr><th>ก้อน</th><th class="r">จำนวน (IDTC)</th><th class="r">ล็อก</th><th class="r">ปลด</th></tr></thead>
                    <tbody>
                        @foreach ($plan['tranches'] as $t)
                            <tr><td>{{ $t['name'] }}</td><td class="r">{{ number_format((float) $t['amount']) }}</td><td class="r">{{ $t['cliff'] }} งวด</td><td class="r">{{ $t['release'] }} งวด</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
                <p class="fine" style="margin-top:10px">ปลดงวดแรกของแต่ละก้อนเมื่อครบล็อก + 1 งวด และครบทั้งก้อนเมื่อครบล็อก + จำนวนงวดปลด</p>
            </div>
        </div>
    @elseif (! $vst['ok'])
        <div class="flash err" role="alert">อ่านสัญญาล็อกไม่ได้: {{ $vst['error'] }} <a href="{{ route('admin.vesting') }}">ลองใหม่</a></div>
        <div class="panel"><div class="kv"><div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$vst['address']" full/></b></div></div></div>
    @else
        <div class="kpis">
            <div class="kpi"><span>ล็อกอยู่</span><b>{{ Units::compact(Units::toFloat($v['lockedAmount'], 18)) }}</b><em class="muted">จาก {{ Units::compact(Units::toFloat($v['totalAllocation'], 18)) }} IDTC</em></div>
            <div class="kpi"><span>ปลดแล้ว (release แล้ว)</span><b>{{ Units::compact(Units::toFloat($v['released'], 18)) }}</b><em class="muted">ถึงเวลาแล้ว {{ number_format($vestedPct, 2) }}%</em></div>
            <div class="kpi"><span>ปลดได้ตอนนี้</span><b>{{ F::idtc($releasable) }}</b>@if ($releasable !== '0')<em>พร้อมกดปลด</em>@else<em class="muted">ยังไม่มีงวดที่ถึงเวลา</em>@endif</div>
            <div class="kpi"><span>งวดถัดไป</span><b>{{ $vst['next_at'] ? F::date($vst['next_at']) : 'ปลดครบแล้ว' }}</b>@if ($vst['next_at'])<em class="muted">{{ Units::compact(Units::toFloat($vst['next_amount'], 18)) }} IDTC</em>@endif</div>
        </div>

        <div class="grid-2">
            <div class="panel">
                <h3>{{ $v['label'] ?: 'สัญญาล็อก' }} <span class="{{ $v['isFunded'] ? 'pill-ok' : 'pill-warn' }}">{{ $v['isFunded'] ? '✓ ฝากครบตามตาราง' : 'ฝากเหรียญยังไม่ครบ' }}</span></h3>
                <div class="kv">
                    <div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$vst['address']"/></b></div>
                    <div><span>เหรียญ</span><b><x-admin.addr :address="$v['token']"/></b></div>
                    <div><span>ผู้รับเหรียญที่ปลด (beneficiary)</span><b><x-admin.addr :address="$v['beneficiary']"/></b></div>
                    @if ($pending)<div><span>ผู้รับรายใหม่ที่รอรับสิทธิ์</span><b><x-admin.addr :address="$pending"/></b></div>@endif
                    <div><span>เริ่มนับ</span><b>{{ F::dateTime((int) $v['start']) }}</b></div>
                    <div><span>ความยาวงวด</span><b>{{ number_format((int) $v['period'] / 86400, 2) }} วัน</b></div>
                    <div><span>ยอดตามตาราง (รวม)</span><b>{{ F::idtc($v['totalAllocation']) }}</b></div>
                    <div><span>เหรียญในสัญญา</span><b>{{ F::idtc($vst['balance']) }}</b></div>
                    <div><span>ยังต้องจ่ายตามตาราง</span><b>{{ F::idtc($vst['obligation']) }}</b></div>
                    @if ($vst['excess'] !== '0')<div><span>ส่วนเกิน (คืนได้)</span><b class="ok">{{ F::idtc($vst['excess']) }}</b></div>@endif
                </div>
            </div>
            <div class="stack">
                <div class="panel">
                    <h3>ตารางปลดล็อก <span class="meta">จากสัญญา</span></h3>
                    <div class="table-scroll"><table class="tbl">
                        <thead><tr><th>ก้อน</th><th class="r">จำนวน</th><th>ปลดงวดแรก</th><th>ครบทั้งก้อน</th><th class="r">ต่องวด</th></tr></thead>
                        <tbody>
                            @foreach ($vst['tranches'] as $i => $t)
                                <tr>
                                    <td>{{ $plan['tranches'][$i]['name'] ?? 'ก้อน '.($i + 1) }}</td>
                                    <td class="r">{{ Units::compact(Units::toFloat($t['amount'], 18)) }}</td>
                                    <td>{{ F::date($t['first_unlock']) }}</td>
                                    <td>{{ F::date($t['fully_vested']) }}</td>
                                    <td class="r">{{ Units::compact($t['per_period']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                </div>
                @include('admin.partials.recent-tx')
            </div>
        </div>

        <div class="panel section-gap">
            <h3>คำสั่ง <span class="meta">release ใครก็กดได้ · คำสั่งอื่นต้องใช้กระเป๋าผู้รับ ({{ Idtc::short(F::addr($v['beneficiary'])) }})</span></h3>
            @if (! $me->canWrite())
                <p class="readonly-note">สิทธิ์ของคุณดูได้อย่างเดียว — คำสั่งบนเชนใช้ได้เฉพาะแอดมินและเจ้าของ</p>
            @else
                <div class="grid-2">
                    <div>
                        <div class="act" x-data="chainAction('vesting.release', @js(['amountText' => F::idtc($releasable), 'beneficiary' => F::addr($v['beneficiary'])]))">
                            <div class="act-head"><div><b>ปลดงวดที่ถึงเวลา</b><p>{{ $releasable !== '0' ? 'ปลดได้ '.F::idtc($releasable).' IDTC → ผู้รับ' : 'ยังไม่มีงวดที่ถึงเวลา' }}</p></div>
                                <button class="btn btn-primary btn-sm" type="button" @click="run()" :disabled="busy || {{ $releasable === '0' ? 'true' : 'false' }}">{{ $releasable !== '0' ? 'ปลดงวดที่ถึงเวลา' : 'ยังไม่ถึงเวลา' }}</button></div>
                            @include('admin.partials.action-state')
                        </div>
                        @if ($pending)
                            <div class="act" x-data="chainAction('vesting.acceptBeneficiary')">
                                <div class="act-head"><div><b>รับสิทธิ์ผู้รับ</b><p>สำหรับกระเป๋า <span class="mono">{{ F::addr($pending) }}</span> เท่านั้น</p></div>
                                    <button class="btn btn-primary btn-sm" type="button" @click="run()" :disabled="busy">รับสิทธิ์ผู้รับ</button></div>
                                @include('admin.partials.action-state')
                            </div>
                        @endif
                        <form class="act danger" x-data="chainAction('vesting.sweepExcess', @js(['token' => F::addr($v['token'])]))" @submit.prevent="run()">
                            <div class="act-head"><div><b>คืนเหรียญส่วนเกิน</b><p>เฉพาะส่วนที่เกินตาราง หรือเหรียญอื่นที่ส่งผิดเข้ามา — ยอดที่ต้องล็อกไม่ถูกแตะ</p></div></div>
                            <label class="field"><span>ที่อยู่เหรียญที่จะคืน</span><input class="input mono" x-model="f.token" autocomplete="off" spellcheck="false" required></label>
                            <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">คืนส่วนเกิน…</button></div>
                            @include('admin.partials.action-state')
                        </form>
                    </div>
                    <div>
                        <form class="act danger" x-data="chainAction('vesting.transferBeneficiary', { address: '' })" @submit.prevent="run()">
                            <div class="act-head"><div><b>เปลี่ยนผู้รับเหรียญที่ปลดล็อก</b><p>สองขั้น: เริ่มเปลี่ยน → กระเป๋าใหม่กดรับเอง (เช่น ย้ายไป multisig)</p></div></div>
                            <label class="field"><span>ที่อยู่ผู้รับรายใหม่</span><input class="input mono" x-model="f.address" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                            <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">เริ่มเปลี่ยนผู้รับ…</button></div>
                            @include('admin.partials.action-state')
                        </form>
                    </div>
                </div>
                <p class="sig"><x-admin.icon name="shield"/>สัญญาล็อกไม่มีเจ้าของ — ไม่มีคำสั่งไหนถอนยอดที่ล็อกก่อนเวลาได้ แม้แต่ผู้รับเอง</p>
            @endif
        </div>
    @endif
@endsection

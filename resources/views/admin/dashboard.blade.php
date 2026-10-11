@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    use App\Services\Chain\Units;
    use App\Support\Idtc;

    $deployed = $presale['deployed'];
    $live = $deployed && $presale['ok'];
    $v = $presale['v'] ?? [];

    // ขายวันนี้เทียบเมื่อวาน (USDT)
    $delta = null;
    if ($yesterday['usdt'] > 0) {
        $delta = ($today['usdt'] - $yesterday['usdt']) / $yesterday['usdt'] * 100;
    }
    $raised = $live && $v['raised'] !== null ? Units::toFloat($v['raised'], 6) : $totals['usdt'];
    $seriesTotal = array_sum(array_column($series, 'usdt'));
@endphp

@section('sub')
    ข้อมูลสัญญาอ่านจากเชน TPIX (แคช 15 วินาที) · ยอดขายจากฐานข้อมูลที่ดึงจาก event บนเชน
    @if ($indexState?->last_success_at) · ซิงก์ล่าสุด {{ F::dateTime($indexState->last_success_at) }} @endif
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.sales.export') }}"><x-admin.icon name="download"/>ส่งออก CSV</a>
    @include('admin.partials.sync-button')
@endsection

@section('content')
    @if (! $deployed)
        <div class="flash info" role="note">ยังไม่ได้ตั้งที่อยู่สัญญา presale — ตัวเลขยอดขายจะเริ่มนับทันทีที่สัญญาขึ้นเชนและตั้งที่อยู่ในหน้า <a href="{{ route('admin.settings') }}">ตั้งค่าเหรียญ</a></div>
    @elseif (! $presale['ok'] && $presale['error'])
        <div class="flash err" role="alert">อ่านสัญญา presale ไม่ได้: {{ $presale['error'] }}</div>
    @endif
    @include('admin.partials.indexer-note')

    <div class="kpis">
        <div class="kpi">
            <span>ขายวันนี้ (IDTC)</span>
            <b>{{ number_format($today['idtc']) }}</b>
            @if ($delta !== null)
                <em @class(['down' => $delta < 0])>{{ $delta >= 0 ? '▲' : '▼' }} {{ number_format(abs($delta), 1) }}% จากเมื่อวาน · {{ F::money($today['usdt']) }} USDT</em>
            @elseif ($today['count'] > 0)
                <em>{{ F::money($today['usdt']) }} USDT · {{ number_format($today['count']) }} รายการ</em>
            @else
                <em class="muted">ยังไม่มียอดวันนี้</em>
            @endif
        </div>
        <div class="kpi">
            <span>USDT ที่ได้ทั้งหมด</span>
            <b>${{ F::money($raised) }}</b>
            @if ($today['usdt'] > 0)
                <em>▲ ${{ F::money($today['usdt']) }} วันนี้</em>
            @else
                <em class="muted">{{ $live ? 'อ่านจากสัญญา (raised)' : 'จากรายการที่ดึงแล้ว' }}</em>
            @endif
        </div>
        <div class="kpi">
            <span>ผู้ซื้อ</span>
            <b>{{ number_format($totals['buyers']) }}</b>
            @if ($newBuyers > 0)
                <em>▲ {{ number_format($newBuyers) }} รายใหม่วันนี้</em>
            @else
                <em class="muted">{{ number_format($totals['count']) }} รายการทั้งหมด</em>
            @endif
        </div>
        <div class="kpi">
            <span>IDTC เหลือใน presale</span>
            @if ($live && $presale['remaining'] !== null)
                <b>{{ Units::compact(Units::toFloat($presale['remaining'], 18)) }}</b>
                @if ($presale['funded'] === true)
                    <em>เหรียญในสัญญาพอขายถึงเพดาน</em>
                @elseif ($presale['funded'] === false)
                    <em class="down">ฝากเหรียญยังไม่ครบ: มี {{ Units::compact(Units::toFloat($presale['balance'], 18)) }} ในสัญญา</em>
                @else
                    <em class="muted">จากเพดาน {{ Units::compact(Units::toFloat($v['saleCap'], 18)) }}</em>
                @endif
            @else
                <b>—</b>
                <em class="muted">{{ $deployed ? 'อ่านจากสัญญาไม่ได้' : 'รอ deploy สัญญา presale' }}</em>
            @endif
        </div>
    </div>

    <div class="grid-main">
        <div class="panel">
            <h3>ยอดขายรายวัน (USDT)
                <span class="seg" role="group" aria-label="ช่วงเวลา">
                    <a href="{{ route('admin.dashboard', ['range' => 14]) }}" @class(['on' => $range === 14])>14 วัน</a>
                    <a href="{{ route('admin.dashboard', ['range' => 30]) }}" @class(['on' => $range === 30])>30 วัน</a>
                </span>
            </h3>
            <x-admin.area-chart :series="$series"/>
            <p class="fine" style="margin-top:6px">รวม {{ F::money($seriesTotal) }} USDT ใน {{ $range }} วันล่าสุด · วันตัดตามเวลาไทย</p>
        </div>

        <div class="panel">
            <h3>ควบคุม Presale @include('admin.partials.presale-pill', ['p' => $presale])</h3>
            @if (! $deployed)
                <x-admin.pending-deploy what="สัญญา presale"/>
            @elseif (! $presale['ok'])
                <div class="empty"><b>อ่านค่าจากสัญญาไม่ได้</b><span>{{ $presale['error'] }}</span></div>
            @else
                <div class="ctrl">
                    <div class="ctrl-row"><div><b>{{ $v['paused'] ? 'หยุดขายชั่วคราวอยู่' : 'การขาย' }}</b><span>{{ $v['paused'] ? 'ผู้ซื้อกดซื้อไม่ได้จนกว่าจะเปิดขายต่อ' : 'หยุดชั่วคราวได้ทันทีเมื่อมีเหตุผิดปกติ' }}</span></div><a class="btn btn-ghost btn-sm" href="{{ route('admin.presale') }}#pause">{{ $me->canWrite() ? ($v['paused'] ? 'เปิดขายต่อ' : 'หยุดชั่วคราว') : 'ดู' }}</a></div>
                    <div class="ctrl-row"><div><b>ขั้นต่ำ / เพดานต่อกระเป๋า</b><span>{{ F::usdt($v['minPurchase'], 6) }} USDT · {{ $v['maxPerWallet'] === '0' ? 'ไม่จำกัด' : F::idtc($v['maxPerWallet']).' IDTC' }}</span></div><a class="btn btn-ghost btn-sm" href="{{ route('admin.presale') }}#limits">{{ $me->canWrite() ? 'แก้ไข' : 'ดู' }}</a></div>
                    <div class="ctrl-row"><div><b>ช่วงเวลาขาย</b><span>{{ F::dateTime($presale['start']) }} → {{ F::dateTime($presale['end']) }}</span></div><a class="btn btn-ghost btn-sm" href="{{ route('admin.presale') }}#window">{{ $me->canWrite() ? 'แก้ไข' : 'ดู' }}</a></div>
                    <div class="ctrl-row"><div><b>กระเป๋ารับเงิน</b><span class="mono">{{ Idtc::short(F::addr($v['treasury'])) }}</span></div><a class="btn btn-ghost btn-sm" href="{{ route('admin.presale') }}#treasury">{{ $me->canWrite() ? 'เปลี่ยน' : 'ดู' }}</a></div>
                </div>
            @endif
            <p class="sig"><x-admin.icon name="shield"/>ทุกคำสั่งเซ็นด้วยกระเป๋าแอดมินในเบราว์เซอร์ เซิร์ฟเวอร์ไม่ถือคีย์</p>
        </div>

        <div class="panel">
            <h3>รายการซื้อล่าสุด <a class="meta" href="{{ route('admin.sales') }}">ดูทั้งหมด →</a></h3>
            @if ($recent->isEmpty())
                <div class="empty"><x-admin.icon name="chart"/><b>ยังไม่มีรายการซื้อ</b><span>{{ $deployed ? 'รายการใหม่จะขึ้นเองหลังผู้ซื้อกดซื้อ (ระบบดึงจากเชนทุกนาที)' : 'จะเริ่มมีรายการเมื่อสัญญา presale ขึ้นเชนและเปิดขาย' }}</span></div>
            @else
                <div class="table-scroll"><table class="tbl">
                    <thead><tr><th>เวลา</th><th>กระเป๋า</th><th class="r">จ่าย (USDT)</th><th class="r">ได้ (IDTC)</th><th>ธุรกรรม</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $p)
                            <tr>
                                <td class="mono">{{ F::dateTime($p->block_time) }}</td>
                                <td><x-admin.addr :address="$p->buyer"/></td>
                                <td class="r">{{ F::usdt($p->pay_amount) }}</td>
                                <td class="r">{{ F::idtc($p->tokens_out) }}</td>
                                <td><a class="mono" style="font-size:12.5px" href="{{ Idtc::explorerTx($p->tx_hash) }}" target="_blank" rel="noopener">ดู ↗</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>

        <div class="stack">
            <div class="panel">
                <h3>สัญญาล็อก
                    @if (! $vesting['deployed']) <span class="pill-wait">รอ deploy</span>
                    @elseif ($vesting['ok']) <span class="{{ $vesting['v']['isFunded'] ? 'pill-ok' : 'pill-warn' }}">{{ $vesting['v']['isFunded'] ? '✓ ฝากครบ' : 'ฝากยังไม่ครบ' }}</span>
                    @else <span class="pill-bad">อ่านไม่ได้</span> @endif
                </h3>
                @if ($vesting['deployed'] && $vesting['ok'])
                    <div class="kv">
                        <div><span>ล็อกอยู่</span><b>{{ F::idtc($vesting['v']['lockedAmount']) }}</b></div>
                        <div><span>งวดถัดไป</span><b>{{ $vesting['next_at'] ? F::date($vesting['next_at']) : 'ปลดครบแล้ว' }}</b></div>
                        <div><span>ปลดได้ตอนนี้</span><b>{{ F::idtc($vesting['v']['releasable']) }}</b></div>
                    </div>
                    <a class="btn btn-ghost btn-sm btn-block" href="{{ route('admin.vesting') }}">{{ $vesting['v']['releasable'] !== '0' ? 'ไปปลดงวดที่ถึงเวลา' : 'ดูตารางปลดล็อก' }}</a>
                @elseif ($vesting['deployed'])
                    <p class="fine">{{ $vesting['error'] }}</p>
                @else
                    <p class="fine">แผน: ล็อก {{ number_format(array_sum(array_map(fn ($t) => (float) $t['amount'], config('idtc.vesting.tranches', [])))) }} IDTC ในสัญญาที่ไม่มีเจ้าของ — ข้อมูลจริงขึ้นเองเมื่อ deploy</p>
                @endif
            </div>

            <div class="panel">
                <h3>พูลสภาพคล่อง</h3>
                <div class="kv" style="margin:0">
                    @foreach (['pair_usdt' => 'IDTC/USDT', 'pair_tpix' => 'IDTC/TPIX'] as $k => $name)
                        @php $pool = $pools[$k]; @endphp
                        <div><span>{{ $name }}</span>
                            @if ($pool['deployed'] && $pool['ok'])
                                <b>{{ Units::compact(Units::toFloat($pool['reserve_quote'], $pool['decimals'])) }} {{ $pool['symbol'] }} · {{ Units::compact(Units::toFloat($pool['reserve_idtc'], 18)) }} IDTC</b>
                            @elseif ($pool['deployed'])
                                <b class="bad">อ่านไม่ได้</b>
                            @else
                                <b style="color:var(--fg-3)">รอ deploy</b>
                            @endif
                        </div>
                    @endforeach
                    @if (($pools['pair_usdt']['price'] ?? null) !== null)
                        <div><span>ราคาในพูล</span><b>${{ F::price($pools['pair_usdt']['price']) }}</b></div>
                    @endif
                </div>
            </div>

            <div class="panel">
                <h3>บันทึกการทำงาน <a class="meta" href="{{ route('admin.audit') }}">ทั้งหมด →</a></h3>
                @if ($logs->isEmpty())
                    <p class="fine">ยังไม่มีบันทึก</p>
                @else
                    <ul class="log">
                        @foreach ($logs as $log)
                            <li><time title="{{ F::dateTime($log->created_at) }}">{{ F::at($log->created_at)->isToday() ? F::at($log->created_at)->format('H:i') : F::dayMonth($log->created_at) }}</time>
                                <span>{{ $log->actorLabel() }} · {{ $log->label() }} @if ($log->tx_status) <x-admin.tx-status :status="$log->tx_status"/> @endif</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection

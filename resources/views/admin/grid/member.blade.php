@extends('layouts.admin')
@php
    use App\Http\Controllers\Admin\GridController as G;
    use App\Services\Grid\GridLedger;
    use App\Support\AdminFormat as F;
    $rk = GridLedger::RANKS[$n['rank']];
    $ro = ! $me->canWrite();
@endphp

@section('sub')
    <x-admin.addr :address="$n['wallet']" full/>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.ledger', ['wallet' => $n['wallet']]) }}"><x-admin.icon name="chart"/>รางวัลของกระเป๋านี้</a>
@endsection

@section('content')
    @if ($n['held'])
        <div class="flash err" role="alert"><b>พักรางวัลอยู่</b> — {{ $row?->held_reason }} · โดย {{ F::addr($row?->held_by) }} เมื่อ {{ F::dateTime($row?->held_at) }}</div>
    @endif

    <div class="kpis">
        <div class="kpi"><span>ระดับ</span><b class="gr {{ $rk }}">{{ G::RANKS[$rk] }}</b><em class="muted">เพื่อนตรง {{ number_format($n['directs']) }} · ทีม {{ number_format($n['team_count']) }} คน</em></div>
        <div class="kpi"><span>ซื้อเอง / ยอดทีม</span><b>${{ number_format($n['own_usdt'], 2) }}</b><em class="muted">ทีม ${{ number_format($n['team_usdt'], 2) }}</em></div>
        <div class="kpi"><span>ครบระยะ / รอ</span><b>{{ number_format($n['ready']) }}</b><em class="muted">รอ {{ number_format($n['wait']) }} IDTC</em></div>
        <div class="kpi"><span>พักไว้ / Sprint</span><b>{{ number_format($n['held_amt']) }}</b><em class="muted">Sprint {{ number_format($n['sprint']) }} IDTC</em></div>
    </div>

    <div class="grid-2 section-gap">
        <div class="panel">
            <h3>สายบน <span class="meta">จากผู้ชวนตรงขึ้นไป</span></h3>
            @if (! $upline)
                <p class="muted">ไม่มีผู้ชวน (เข้ามาเอง)</p>
            @else
                <div class="kv">
                    @foreach ($upline as $i => $w)
                        <div><span>วง {{ $i + 1 }}@if ($i >= 3) <span class="muted">(ไม่ได้รับจากกระเป๋านี้)</span>@endif</span><b><a class="mono" href="{{ route('admin.grid.member', $w) }}">{{ F::addr($w) }}</a> <span class="gr {{ GridLedger::RANKS[$book->nodes[$w]['rank']] }}">{{ G::RANKS[GridLedger::RANKS[$book->nodes[$w]['rank']]] }}</span></b></div>
                    @endforeach
                </div>
            @endif
            @if ($row?->bound_at)
                <p class="fine">ผูกเมื่อ {{ F::dateTime($row->bound_at) }} · เก็บข้อความและลายเซ็นของเจ้าของกระเป๋าไว้เป็นหลักฐาน</p>
            @endif
        </div>
        <div class="panel">
            <h3>พักรางวัล <span class="meta">ใช้เมื่อสงสัยโกง</span></h3>
            @if ($ro)
                <p class="muted">สิทธิ์ผู้ดูอย่างเดียว</p>
            @elseif ($n['held'])
                <form method="POST" action="{{ route('admin.grid.unhold', $n['wallet']) }}" x-data="safeForm('ปลดการพักรางวัล?', @js(['กระเป๋า '.F::addr($n['wallet']), 'รางวัลที่เข้าและที่เกิดจากการซื้อของกระเป๋านี้กลับไปนับตามปกติ']))" @submit="submit($event)">
                    @csrf
                    <button class="btn btn-primary btn-sm" type="submit" :disabled="busy">ปลดการพัก</button>
                </form>
            @else
                <form class="form" method="POST" action="{{ route('admin.grid.hold', $n['wallet']) }}" x-data="safeForm('พักรางวัลของกระเป๋านี้?', @js(['กระเป๋า '.F::addr($n['wallet']), 'รางวัลที่เข้ากระเป๋านี้ และรางวัลที่เกิดจากการซื้อของกระเป๋านี้ จะไม่เข้ารอบจ่ายจนกว่าจะปลด']), true)" @submit="submit($event)">
                    @csrf
                    <label class="field"><span>เหตุผล (ขึ้นในบันทึกการทำงาน)</span><input class="input" name="reason" minlength="8" maxlength="300" required value="{{ old('reason') }}" placeholder="เช่น กระเป๋าใหม่หลายใบได้ค่าแก๊สจากแหล่งเดียวกัน" @error('reason') aria-invalid="true" @enderror>@error('reason')<span class="err">{{ $message }}</span>@enderror</label>
                    <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">พักรางวัล</button></div>
                </form>
            @endif
        </div>
    </div>

    <div class="panel section-gap">
        <h3>ทีม <span class="meta">3 วงที่กระเป๋านี้ได้รางวัล</span></h3>
        @foreach ($rings as $d => $ring)
            <h4 style="margin:12px 0 6px">วง {{ $d }} <span class="muted">· {{ number_format($ring['count']) }} คน</span></h4>
            @if ($ring['items'])
                <div class="table-scroll"><table class="tbl">
                    <thead><tr><th>กระเป๋า</th><th>ระดับ</th><th class="r">ซื้อเอง (USDT)</th><th class="r">ทีม (คน)</th></tr></thead>
                    <tbody>
                        @foreach ($ring['items'] as $c)
                            <tr><td><a class="mono" href="{{ route('admin.grid.member', $c['wallet']) }}">{{ F::addr($c['wallet']) }}</a></td><td><span class="gr {{ GridLedger::RANKS[$c['rank']] }}">{{ G::RANKS[GridLedger::RANKS[$c['rank']]] }}</span></td><td class="r">{{ number_format($c['own_usdt'], 2) }}</td><td class="r">{{ number_format($c['team_count']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
                @if ($ring['count'] > count($ring['items']))<p class="fine">แสดง {{ count($ring['items']) }} จาก {{ number_format($ring['count']) }} คน</p>@endif
            @endif
        @endforeach
        @if (! $rings || ! $rings[1]['count'])<p class="muted">ยังไม่มีใครผูกกระเป๋านี้เป็นผู้ชวน</p>@endif
    </div>

    <div class="grid-2 section-gap">
        <div class="panel">
            <h3>รางวัลที่เข้ากระเป๋านี้ <span class="meta">ล่าสุด {{ count($rewards) }} รายการ</span></h3>
            @if (! $rewards)
                <p class="muted">ยังไม่มี</p>
            @else
                <div class="table-scroll"><table class="tbl">
                    <thead><tr><th>เวลา</th><th>จาก</th><th>ประเภท</th><th class="r">IDTC</th><th>สถานะ</th></tr></thead>
                    <tbody>
                        @foreach ($rewards as $r)
                            <tr><td class="mono">{{ F::dateTime($r['at']) }}</td><td><span class="mono">{{ F::addr($r['buyer']) }}</span></td><td>{{ $r['kind'] === 'buyer' ? 'ส่วนผู้ซื้อ' : 'วง '.$r['ring'] }}</td><td class="r">{{ number_format($r['amount'], 2) }}</td><td><span class="gs {{ $r['status'] }}">{{ G::STATUS[$r['status']] }}</span></td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
        <div class="panel">
            <h3>การซื้อของกระเป๋านี้</h3>
            @if ($purchases->isEmpty())
                <p class="muted">ยังไม่เคยซื้อ presale</p>
            @else
                <div class="table-scroll"><table class="tbl">
                    <thead><tr><th>เวลา</th><th class="r">USDT</th><th class="r">IDTC</th><th>ธุรกรรม</th></tr></thead>
                    <tbody>
                        @foreach ($purchases as $p)
                            <tr><td class="mono">{{ F::dateTime($p->block_time) }}</td><td class="r">{{ F::usdt($p->pay_amount, 2) }}</td><td class="r">{{ F::idtc($p->tokens_out) }}</td><td><x-admin.addr :address="$p->tx_hash" tx/></td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    </div>
@endsection

@extends('layouts.admin')
@php
    use App\Http\Controllers\Admin\GridController as G;
    use App\Support\AdminFormat as F;
    $hasFilter = $filters['wallet'] || $filters['status'] || $filters['week'];
@endphp

@section('sub')
    รางวัลที่เกิดจากการซื้อแต่ละรายการ (วง 1–3 + ส่วนผู้ซื้อ) · ไม่รวม Sprint · เวลาไทย
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.export', array_filter($filters)) }}"><x-admin.icon name="download"/>ส่งออก CSV{{ $hasFilter ? ' (ตามตัวกรอง)' : '' }}</a>
@endsection

@section('content')
    <div class="kpis">
        <div class="kpi"><span>รายการรางวัล{{ $hasFilter ? ' (ตามตัวกรอง)' : '' }}</span><b>{{ number_format($count) }}</b></div>
        <div class="kpi"><span>IDTC รวม</span><b>{{ number_format($sum) }}</b></div>
        <div class="kpi"><span>การซื้อทั้งหมด</span><b>{{ number_format(count($book->events)) }}</b></div>
        <div class="kpi"><span>คืนเข้างบรวม</span><b>{{ number_format($book->totals['returned']) }}</b><em class="muted">รวม Sprint ที่แจกไม่หมด</em></div>
    </div>

    <div class="panel section-gap">
        <form class="filters" method="GET" action="{{ route('admin.grid.ledger') }}">
            <label class="field wide"><span>กระเป๋าผู้ซื้อหรือผู้รับ (ขึ้นต้นด้วย)</span><input class="input mono" type="search" name="wallet" value="{{ $filters['wallet'] }}" placeholder="0x…" autocomplete="off" spellcheck="false" @if ($filterError) aria-invalid="true" @endif></label>
            <label class="field"><span>สถานะ</span>
                <select class="select" name="status">
                    <option value="">ทุกสถานะ</option>
                    @foreach (G::STATUS as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="field"><span>สัปดาห์ขาย</span><input class="input" name="week" inputmode="numeric" value="{{ $filters['week'] }}" placeholder="ทุกสัปดาห์"></label>
            <div class="actions">
                <button class="btn btn-primary btn-sm" type="submit">กรอง</button>
                @if ($hasFilter || $filterError)<a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.ledger') }}">ล้างตัวกรอง</a>@endif
            </div>
        </form>
        @if ($filterError)<p class="err" role="alert" style="margin:-4px 0 12px">{{ $filterError }}</p>@endif

        @if ($rows->isEmpty())
            <div class="empty"><x-admin.icon name="chart"/><b>{{ $hasFilter ? 'ไม่พบรายการตามตัวกรอง' : 'ยังไม่มีรางวัล' }}</b><span>รางวัลเกิดเมื่อมีการซื้อ presale</span></div>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>เวลาซื้อ</th><th class="r">สัปดาห์</th><th>ผู้ซื้อ</th><th class="r">USDT</th><th>ประเภท</th><th>ผู้รับ</th><th class="r">อัตรา</th><th class="r">IDTC</th><th>สถานะ</th><th>ธุรกรรม</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td class="mono">{{ F::dateTime($r['at']) }}</td>
                            <td class="r">{{ $r['week'] + 1 }}</td>
                            <td><a class="mono" href="{{ route('admin.grid.member', $r['buyer']) }}">{{ F::addr($r['buyer']) }}</a></td>
                            <td class="r">{{ number_format($r['usdt'], 2) }}</td>
                            <td>{{ $r['kind'] === 'buyer' ? 'ส่วนผู้ซื้อ' : 'วง '.$r['ring'] }}</td>
                            <td>@if ($r['to'])<a class="mono" href="{{ route('admin.grid.member', $r['to']) }}">{{ F::addr($r['to']) }}</a>@else<span class="muted">—</span>@endif</td>
                            <td class="r">{{ rtrim(rtrim(number_format($r['rate'] * 100, 2), '0'), '.') }}%</td>
                            <td class="r">{{ number_format($r['amount'], 2) }}</td>
                            <td><span class="gs {{ $r['status'] }}" @if ($r['status'] === 'wait') title="ครบระยะถือ {{ F::dateTime($r['confirm_at']) }}" @endif>{{ G::STATUS[$r['status']] }}</span></td>
                            <td><x-admin.addr :address="$r['tx']" tx/></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            {{ $rows->links('admin.partials.pager') }}
        @endif
    </div>
@endsection

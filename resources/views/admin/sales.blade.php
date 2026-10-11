@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    use App\Support\Idtc;
    $hasFilter = $filters['from'] || $filters['to'] || $filters['wallet'];
@endphp

@section('sub')
    รายการซื้อ presale ที่ดึงจาก event Purchased บนเชน · เวลาไทย
    @if ($indexState?->last_block) · ดึงถึงบล็อก {{ number_format($indexState->last_block) }} @endif
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.sales.export', array_filter($filters)) }}"><x-admin.icon name="download"/>ส่งออก CSV{{ $hasFilter ? ' (ตามตัวกรอง)' : '' }}</a>
    @include('admin.partials.sync-button')
@endsection

@section('content')
    @include('admin.partials.indexer-note')
    @if (! Idtc::isDeployed('presale'))
        <div class="flash info" role="note">ยังไม่ได้ตั้งที่อยู่สัญญา presale — รายการซื้อจะเริ่มเข้ามาเมื่อสัญญาขึ้นเชนและตั้งที่อยู่ในหน้า <a href="{{ route('admin.settings') }}">ตั้งค่าเหรียญ</a></div>
    @endif

    <div class="kpis">
        <div class="kpi"><span>รายการ{{ $hasFilter ? ' (ตามตัวกรอง)' : 'ทั้งหมด' }}</span><b>{{ number_format((int) $summary->c) }}</b></div>
        <div class="kpi"><span>USDT รวม</span><b>${{ F::money((float) $summary->u) }}</b></div>
        <div class="kpi"><span>IDTC รวม</span><b>{{ number_format((float) $summary->t) }}</b></div>
        <div class="kpi"><span>ผู้ซื้อไม่ซ้ำ</span><b>{{ number_format((int) $summary->b) }}</b></div>
    </div>

    <div class="panel">
        <form class="filters" method="GET" action="{{ route('admin.sales') }}">
            <label class="field"><span>ตั้งแต่วันที่</span><input class="input" type="date" name="from" value="{{ $filters['from'] }}"></label>
            <label class="field"><span>ถึงวันที่</span><input class="input" type="date" name="to" value="{{ $filters['to'] }}"></label>
            <label class="field wide"><span>กระเป๋าผู้ซื้อ (ทั้งที่อยู่ หรือขึ้นต้นด้วย)</span><input class="input mono" type="search" name="wallet" value="{{ request('wallet') }}" placeholder="0x…" autocomplete="off" spellcheck="false" @if ($filterError) aria-invalid="true" @endif></label>
            <div class="actions">
                <button class="btn btn-primary btn-sm" type="submit">กรอง</button>
                @if ($hasFilter || $filterError)<a class="btn btn-ghost btn-sm" href="{{ route('admin.sales') }}">ล้างตัวกรอง</a>@endif
            </div>
        </form>
        @if ($filterError)<p class="err" role="alert" style="margin:-4px 0 12px">{{ $filterError }}</p>@endif

        @if ($purchases->isEmpty())
            <div class="empty"><x-admin.icon name="chart"/>
                <b>{{ $hasFilter ? 'ไม่พบรายการตามตัวกรอง' : 'ยังไม่มีรายการซื้อ' }}</b>
                <span>{{ $hasFilter ? 'ลองขยายช่วงวันที่ หรือตรวจที่อยู่กระเป๋า' : 'รายการจะขึ้นเองหลังผู้ซื้อกดซื้อบนเชน (ระบบดึงทุกนาที)' }}</span>
            </div>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>เวลา (ไทย)</th><th>กระเป๋าผู้ซื้อ</th><th class="r">จ่าย (USDT)</th><th class="r">ได้ (IDTC)</th><th>ธุรกรรม</th><th class="r">บล็อก</th></tr></thead>
                <tbody>
                    @foreach ($purchases as $p)
                        <tr>
                            <td class="mono">{{ F::dateTime($p->block_time) }}</td>
                            <td><x-admin.addr :address="$p->buyer"/></td>
                            <td class="r">{{ F::usdt($p->pay_amount, 6) }}</td>
                            <td class="r">{{ F::idtc($p->tokens_out, 2) }}</td>
                            <td><x-admin.addr :address="$p->tx_hash" tx/></td>
                            <td class="r mono muted">{{ number_format($p->block_number) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            {{ $purchases->links('admin.partials.pager') }}
        @endif
    </div>
@endsection

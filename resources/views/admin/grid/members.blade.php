@extends('layouts.admin')
@php
    use App\Http\Controllers\Admin\GridController as G;
    use App\Services\Grid\GridLedger;
    use App\Support\AdminFormat as F;
    $sorts = ['team' => 'ยอดทีม', 'own' => 'ซื้อเอง', 'ready' => 'รางวัลครบระยะ', 'directs' => 'เพื่อนตรง', 'bound' => 'ผูกล่าสุด'];
@endphp

@section('sub')
    กระเป๋าทุกใบที่ผูกผู้ชวน เป็นผู้ชวน หรือซื้อ presale · {{ number_format(count($book->nodes)) }} ใบ
@endsection

@section('content')
    <div class="panel">
        <form class="filters" method="GET" action="{{ route('admin.grid.members') }}">
            <label class="field wide"><span>กระเป๋า (ทั้งที่อยู่ หรือขึ้นต้นด้วย)</span><input class="input mono" type="search" name="q" value="{{ $filters['q'] }}" placeholder="0x…" autocomplete="off" spellcheck="false" @if ($filterError) aria-invalid="true" @endif></label>
            <label class="field"><span>ระดับ</span>
                <select class="select" name="rank">
                    <option value="">ทุกระดับ</option>
                    @foreach (G::RANKS as $key => $label)<option value="{{ $key }}" @selected($filters['rank'] === $key)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="field"><span>เรียงตาม</span>
                <select class="select" name="sort">
                    @foreach ($sorts as $key => $label)<option value="{{ $key }}" @selected($filters['sort'] === $key)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <div class="actions">
                <button class="btn btn-primary btn-sm" type="submit">กรอง</button>
                @if ($filters['q'] || $filters['rank'])<a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.members') }}">ล้างตัวกรอง</a>@endif
            </div>
        </form>
        @if ($filterError)<p class="err" role="alert" style="margin:-4px 0 12px">{{ $filterError }}</p>@endif

        @if ($rows->isEmpty())
            <div class="empty"><x-admin.icon name="users"/><b>ยังไม่มีสมาชิก{{ $filters['q'] || $filters['rank'] ? 'ตามตัวกรอง' : '' }}</b><span>สมาชิกเกิดเมื่อมีคนผูกผู้ชวนในหน้า /grid หรือซื้อ presale</span></div>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>กระเป๋า</th><th>ระดับ</th><th>ผู้ชวน</th><th class="r">ซื้อเอง (USDT)</th><th class="r">เพื่อนตรง</th><th class="r">ทีม (คน / USDT)</th><th class="r">ครบระยะ</th><th class="r">รอ</th><th>ผูกเมื่อ</th></tr></thead>
                <tbody>
                    @foreach ($rows as $n)
                        @php $rk = GridLedger::RANKS[$n['rank']]; @endphp
                        <tr>
                            <td><a href="{{ route('admin.grid.member', $n['wallet']) }}" class="mono">{{ F::addr($n['wallet']) }}</a>@if ($n['held']) <span class="gs held">พัก</span>@endif</td>
                            <td><span class="gr {{ $rk }}">{{ G::RANKS[$rk] }}</span></td>
                            <td>@if ($n['referrer'])<a class="mono" href="{{ route('admin.grid.member', $n['referrer']) }}">{{ F::addr($n['referrer']) }}</a>@else<span class="muted">—</span>@endif</td>
                            <td class="r">{{ number_format($n['own_usdt'], 2) }}</td>
                            <td class="r">{{ number_format($n['directs']) }}</td>
                            <td class="r">{{ number_format($n['team_count']) }} / {{ number_format($n['team_usdt'], 2) }}</td>
                            <td class="r">{{ number_format($n['ready']) }}</td>
                            <td class="r">{{ number_format($n['wait']) }}</td>
                            <td class="mono muted">{{ $n['bound_at'] ? F::dateTime($n['bound_at']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            {{ $rows->links('admin.partials.pager') }}
        @endif
    </div>
@endsection

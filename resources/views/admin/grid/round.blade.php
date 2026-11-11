@extends('layouts.admin')
@php
    use App\Http\Controllers\Admin\GridRoundsController as R;
    use App\Models\GridRound;
    use App\Services\Chain\Units;
    use App\Support\AdminFormat as F;
    $ro = ! $me->canEdit('grid_rounds');
    $sameContract = ($chain['ok'] ?? false) && strtolower($chain['address']) === strtolower($round->contract);
    // เหรียญที่ต้องมีในสัญญาก่อนประกาศ = ค้างจ่ายทุกรอบ + รอบนี้
    $need = $sameContract ? Units::add($chain['outstanding'], $round->total) : null;
    $shortBy = $need !== null && Units::cmp($need, $chain['balance']) > 0 ? Units::sub($need, $chain['balance']) : '0';
@endphp

@section('sub')
    {{ R::KIND[$round->kind] ?? $round->kind }} · <span class="mono">{{ $round->root }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.round.export', $round) }}"><x-admin.icon name="download"/>ส่งออก CSV (รวม proof)</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.rounds') }}">กลับไปรอบทั้งหมด</a>
@endsection

@section('content')
    <div class="kpis">
        <div class="kpi"><span>สถานะ</span><b>{{ R::STATUS[$round->status] ?? $round->status }}</b><em class="muted">รอบที่ {{ $round->chain_round }} บนสัญญา</em></div>
        <div class="kpi"><span>IDTC รวม</span><b>{{ number_format($round->total_idtc, 2) }}</b><em class="muted">{{ number_format($round->items) }} กระเป๋า</em></div>
        <div class="kpi"><span>รับได้ตั้งแต่</span><b>{{ $round->unlock_at ? F::dateTime($round->unlock_at) : 'ทันทีที่ประกาศ' }}</b></div>
        <div class="kpi"><span>รับไปแล้ว (บนเชน)</span><b>{{ $on ? F::idtc($on['claimed'], 2) : '—' }}</b></div>
    </div>

    @if ($round->note)
        <div class="flash info section-gap" role="note">{{ $round->note }}</div>
    @endif

    @if (! $sameContract)
        <div class="flash warn section-gap" role="note">รอบนี้สร้างให้สัญญา <span class="mono">{{ $round->contract }}</span> ซึ่งไม่ใช่สัญญาจ่ายรางวัลที่ตั้งอยู่ตอนนี้ (หรืออ่านสัญญาไม่ได้)</div>
    @endif

    @if ($round->status === GridRound::DRAFT && $sameContract)
        <div class="panel section-gap">
            <h3>ประกาศรอบนี้บนเชน</h3>
            <ol class="steps">
                <li>โอน IDTC เข้าสัญญา <x-admin.addr :address="$round->contract" full/> ให้มีอย่างน้อย <b class="mono">{{ F::idtc($need, 2) }}</b> IDTC (ค้างจ่ายทุกรอบ + รอบนี้)
                    @if ($shortBy !== '0')
                        — <b style="color:#f5c86b">ยังขาด {{ F::idtc($shortBy, 2) }} IDTC</b> (มีอยู่ {{ F::idtc($chain['balance'], 2) }}) · โอนจากกระเป๋าโครงการด้วยกระเป๋าของคุณเอง
                    @else
                        — <b style="color:#8ee6a8">มีพอแล้ว</b>
                    @endif
                </li>
                <li>กดประกาศด้วยกระเป๋าเจ้าของสัญญา <x-admin.addr :address="$chain['owner']"/></li>
            </ol>
            @if ($ro)
                <p class="muted">ประกาศได้เฉพาะแอดมิน</p>
            @elseif (! $publishable)
                <p class="muted">ยังประกาศรอบนี้ไม่ได้ — ต้องประกาศรอบที่ {{ (int) $chain['count'] }} ก่อน (ทีละรอบตามลำดับเลขรอบ)</p>
            @else
                <div class="act" x-data="chainAction('rewards.addRound', @js(['id' => (string) $round->chain_round, 'root' => $round->root, 'total' => $round->total, 'unlockAt' => (string) ($round->unlock_at?->getTimestamp() ?? 0), 'totalText' => number_format($round->total_idtc, 2).' IDTC', 'items' => $round->items, 'unlockText' => $round->unlock_at ? F::dateTime($round->unlock_at) : 'ทันที']))">
                    <div class="act-head"><div><b>ประกาศรอบที่ {{ $round->chain_round }}</b><p>หลังประกาศ สมาชิกในรายชื่อกดรับเองได้ที่หน้า Grid — แก้รายชื่อไม่ได้ ทำได้แค่ยกเลิกทั้งรอบ</p></div>
                        <button class="btn btn-primary btn-sm" type="button" @click="run()" :disabled="busy">ประกาศบนเชน</button></div>
                    @include('admin.partials.action-state')
                </div>
            @endif
            @unless ($ro)
                <form class="section-gap" method="POST" action="{{ route('admin.grid.round.discard', $round) }}" x-data="safeForm('ทิ้งรอบนี้?', @js(['รอบที่ '.$round->chain_round.' และรอบที่สร้างต่อจากนั้นจะถูกทิ้ง', 'ยอดกลับไปรอรอบถัดไป ไม่มีอะไรบนเชนเปลี่ยน']), true)" @submit="submit($event)">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit" :disabled="busy">ทิ้งรอบที่ยังไม่ประกาศ</button>
                </form>
            @endunless
        </div>
    @elseif ($round->status === GridRound::LIVE && $sameContract && ! $ro)
        <div class="panel section-gap">
            <h3>ยกเลิกรอบ <span class="meta">ใช้เมื่อพบว่ารายชื่อผิดเท่านั้น</span></h3>
            <div class="act danger" x-data="chainAction('rewards.revokeRound', @js(['id' => (string) $round->chain_round]))">
                <div class="act-head"><div><b>ยกเลิกรอบที่ {{ $round->chain_round }}</b><p>คนที่ยังไม่รับจะรับไม่ได้อีก ส่วนที่ยังไม่รับกลายเป็นส่วนเกินในสัญญา · ยอดของคนที่ยังไม่รับจะกลับมารอรอบถัดไป</p></div>
                    <button class="btn btn-danger btn-sm" type="button" @click="run()" :disabled="busy">ยกเลิกรอบบนเชน</button></div>
                @include('admin.partials.action-state')
            </div>
        </div>
    @endif

    <div class="panel section-gap">
        <h3>รายชื่อ <span class="meta">เรียงจากมากไปน้อย</span></h3>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>กระเป๋า</th><th class="r">IDTC</th><th class="r">หน่วยเล็กสุด</th></tr></thead>
                <tbody>
                    @foreach ($items as $i)
                        <tr><td><a class="mono" href="{{ route('admin.grid.member', $i->wallet) }}">{{ F::addr($i->wallet) }}</a></td><td class="r mono">{{ number_format($i->amount_idtc, 6) }}</td><td class="r mono muted">{{ $i->amount }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('admin.partials.pager', ['paginator' => $items])
    </div>
@endsection

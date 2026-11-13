@extends('layouts.admin')
@php
    use App\Http\Controllers\Admin\GridRoundsController as R;
    use App\Models\GridRound;
    use App\Services\Chain\Units;
    use App\Support\AdminFormat as F;
    $ro = ! $me->canEdit('binary');
    $amt = fn (?string $raw, int $frac = 2) => $raw === null ? '—' : Units::format($raw, $decimals, $frac);
    $min = rtrim(rtrim(number_format($minDue, 2), '0'), '.');
@endphp

@section('sub')
    สร้างรายชื่อจากยอดที่ถึงกำหนด → โอน {{ $unit }} เข้าสัญญา → เจ้าของสัญญาประกาศ root บนเชน → สมาชิกกดรับเองที่หน้า /binary
@endsection

@section('actions')
    <a @class(['btn btn-sm', 'btn-primary' => $slug === 'usdt', 'btn-ghost' => $slug !== 'usdt']) href="{{ route('admin.binary.rounds', 'usdt') }}">รางวัลจับคู่ (USDT)</a>
    <a @class(['btn btn-sm', 'btn-primary' => $slug === 'idtc', 'btn-ghost' => $slug !== 'idtc']) href="{{ route('admin.binary.rounds', 'idtc') }}">ส่ง IDTC ที่ซื้อ</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary') }}">กลับหน้าแผน Binary</a>
@endsection

@section('content')
    <div class="panel">
        <h3>สัญญาจ่าย — {{ $label }} <span class="meta">GridRewards ที่ deploy ให้เหรียญ {{ $unit }}</span></h3>
        @if (! $chain['deployed'])
            <p><span class="pill-wait">รอ deploy</span> ยังไม่ได้ตั้งที่อยู่สัญญาของสายนี้ — deploy สัญญา GridRewards (TPIX-Coin) ด้วยเหรียญ {{ $unit }} แล้วตั้งที่อยู่ที่ <a href="{{ route('admin.settings') }}">ตั้งค่าเหรียญ</a></p>
        @elseif (! $chain['ok'])
            <div class="flash err" role="alert">อ่านสัญญาจ่ายไม่ได้: {{ $chain['error'] }}</div>
        @else
            <div class="kv">
                <div><span>ที่อยู่สัญญา</span><b><x-admin.addr :address="$chain['address']"/></b></div>
                <div><span>เจ้าของ (ประกาศรอบได้)</span><b><x-admin.addr :address="$chain['owner']"/></b></div>
                <div><span>{{ $unit }} ในสัญญา</span><b class="mono">{{ $amt($chain['balance']) }}</b></div>
                <div><span>ค้างจ่าย (ทุกรอบที่ประกาศ)</span><b class="mono">{{ $amt($chain['outstanding']) }}</b></div>
                <div><span>รอบบนเชน</span><b>{{ number_format($chain['count']) }}</b></div>
                <div><span>การรับ</span><b>{{ $chain['paused'] ? 'หยุดชั่วคราว' : 'เปิดอยู่' }}</b></div>
                <div><span>ส่วนเกิน (ถอนได้)</span><b class="mono">{{ $amt($excess) }}</b></div>
            </div>
            @unless ($ro)
                <div class="grid-2 section-gap">
                    @if ($chain['paused'])
                        <div class="act" x-data="chainAction('{{ $action }}.unpause')">
                            <div class="act-head"><div><b>เปิดรับต่อ</b><p>สมาชิกกดรับในรอบที่ประกาศแล้วได้ทันที</p></div>
                                <button class="btn btn-primary btn-sm" type="button" @click="run()" :disabled="busy">เปิดรับต่อ</button></div>
                            @include('admin.partials.action-state')
                        </div>
                    @else
                        <div class="act danger" x-data="chainAction('{{ $action }}.pause')">
                            <div class="act-head"><div><b>หยุดรับชั่วคราว</b><p>ใช้เมื่อพบปัญหา — ยอดทุกคนยังอยู่ครบ</p></div>
                                <button class="btn btn-danger btn-sm" type="button" @click="run()" :disabled="busy">หยุดชั่วคราว</button></div>
                            @include('admin.partials.action-state')
                        </div>
                    @endif
                    @if ($excess !== '0')
                        <form class="act danger" x-data="chainAction('{{ $action }}.withdrawExcess', { to: '', amount: '' })" @submit.prevent="run()">
                            <div class="act-head"><div><b>ถอนส่วนเกิน</b><p>เฉพาะ {{ $unit }} ที่เกินยอดค้างจ่าย (เช่น ส่วนที่ยังไม่มีใครรับของรอบที่ยกเลิก)</p></div></div>
                            <label class="field"><span>ที่อยู่ปลายทาง</span><input class="input mono" x-model="f.to" placeholder="0x…" autocomplete="off" spellcheck="false" required></label>
                            <label class="field"><span>จำนวน {{ $unit }} (ไม่เกิน {{ $amt($excess, 6) }})</span><input class="input mono" x-model="f.amount" inputmode="decimal" autocomplete="off" required></label>
                            <div class="actions"><button class="btn btn-danger btn-sm" type="submit" :disabled="busy">ถอนส่วนเกิน…</button></div>
                            @include('admin.partials.action-state')
                        </form>
                    @endif
                </div>
            @endunless
        @endif
    </div>

    <div class="panel section-gap">
        <h3>ยอดที่จะเข้ารอบถัดไป <span class="meta">{{ $slug === 'usdt' ? 'รางวัลของสัปดาห์ที่ปิดแล้ว' : 'IDTC ของรายการฝากที่นับแล้ว' }} − จัดสรรไปแล้ว · ขั้นต่ำ {{ $min }} {{ $unit }} ต่อกระเป๋า · ไม่รวมกระเป๋าที่พักไว้</span></h3>
        <div class="kpis">
            <div class="kpi"><span>กระเป๋า</span><b>{{ number_format(count($due)) }}</b></div>
            <div class="kpi"><span>{{ $unit }} รวม</span><b>{{ number_format($dueSum, 2) }}</b></div>
        </div>
        @if ($due)
            <div class="table-wrap section-gap">
                <table class="table">
                    <thead><tr><th>กระเป๋า</th><th class="r">ถึงกำหนดทั้งหมด</th><th class="r">จัดสรรแล้ว</th><th class="r">รอบนี้</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($due, 0, 20) as $d)
                            <tr><td class="mono">{{ F::addr($d['wallet']) }}</td>
                                <td class="r mono">{{ number_format($d['ready'], 2) }}</td><td class="r mono">{{ number_format($d['allocated'], 2) }}</td><td class="r mono"><b>{{ number_format($d['due'], 2) }}</b></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (count($due) > 20)<p class="muted">แสดง 20 อันดับแรกจาก {{ number_format(count($due)) }} กระเป๋า</p>@endif
        @endif

        @if ($draft)
            <div class="flash info" role="note">มีรอบที่สร้างแล้วยังไม่ได้ประกาศ — <a href="{{ route('admin.binary.round', $draft) }}">ไปที่รอบที่ {{ $draft->chain_round }}</a> เพื่อประกาศหรือทิ้ง ก่อนสร้างรอบใหม่</div>
        @elseif ($ro)
            <p class="muted">สร้างรอบจ่ายได้เฉพาะผู้มีสิทธิ์แก้ไขแผน Binary</p>
        @elseif (! $chain['ok'])
            <p class="muted">ต้องอ่านสัญญาจ่ายได้ก่อน จึงสร้างรอบได้</p>
        @elseif ($due)
            <form class="form section-gap" method="POST" action="{{ route('admin.binary.rounds.build', $slug) }}"
                  x-data="safeForm('สร้างรอบจ่ายจากยอดนี้?', @js([number_format(count($due)).' กระเป๋า รวม '.number_format($dueSum, 2).' '.$unit, 'ยังไม่มีอะไรขึ้นเชน — ต้องกดประกาศอีกครั้งที่หน้ารอบ']))" @submit="submit($event)">
                @csrf
                <label class="field wide"><span>หมายเหตุ (ไม่บังคับ)</span><input class="input" name="note" maxlength="500" placeholder="เช่น รอบสัปดาห์ที่ 3"></label>
                <button class="btn btn-primary" type="submit" :disabled="busy"><x-admin.icon name="download"/>สร้างรอบจ่าย</button>
            </form>
        @endif
    </div>

    <div class="panel section-gap">
        <h3>รอบทั้งหมดของสายนี้</h3>
        @if ($list->isEmpty())
            <p class="muted">ยังไม่มีรอบจ่าย</p>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>รอบ</th><th>สถานะ</th><th class="r">กระเป๋า</th><th class="r">{{ $unit }}</th><th>สร้างเมื่อ</th></tr></thead>
                    <tbody>
                        @foreach ($list as $r)
                            <tr>
                                <td><a href="{{ route('admin.binary.round', $r) }}">#{{ $r->chain_round }}</a>@if (strtolower($r->contract) !== strtolower((string) ($chain['address'] ?? ''))) <span class="muted" title="{{ $r->contract }}">(สัญญาเดิม)</span>@endif</td>
                                <td><span @class(['pill-ok' => $r->status === GridRound::LIVE, 'pill-wait' => $r->status === GridRound::DRAFT, 'pill-bad' => in_array($r->status, [GridRound::STALE, GridRound::REVOKED], true)])>{{ R::STATUS[$r->status] ?? $r->status }}</span></td>
                                <td class="r">{{ number_format($r->items) }}</td>
                                <td class="r mono">{{ number_format($r->total_idtc, 2) }}</td>
                                <td>{{ F::dateTime($r->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

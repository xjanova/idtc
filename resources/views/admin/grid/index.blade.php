@extends('layouts.admin')
@php
    use App\Http\Controllers\Admin\GridController as G;
    use App\Services\Grid\GridPlan;
    use App\Support\AdminFormat as F;
    $t = $book->totals;
    $big = fn ($v) => number_format((float) $v);
    $pct = fn ($v) => rtrim(rtrim(number_format($v * 100, 2), '0'), '.');
    $budget = max(1.0, (float) $t['budget']);
    $usedPct = min(100, $t['committed'] / $budget * 100);
    $worstPct = min(99.5, $t['worst'] / $budget * 100);
@endphp

@section('sub')
    รางวัลแนะนำเพื่อนซื้อ presale · คำนวณสดจากยอดซื้อบนเชน
    @if ($book->nowWeek >= 0) · ตอนนี้สัปดาห์ขายที่ {{ $book->nowWeek + 1 }} @else · ยังไม่เริ่มขาย (เริ่ม {{ GridPlan::saleStart()->setTimezone('Asia/Bangkok')->format('j/n/Y H:i') }}) @endif
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ \App\Support\SiteLocale::route('grid', [], 'th') }}" target="_blank" rel="noopener"><x-admin.icon name="external"/>เปิดหน้า /grid</a>
@endsection

@section('content')
    <div class="panel switch-row">
        <div>
            <h3 style="margin:0 0 4px">สถานะระบบ
                @if ($enabled) <span class="pill-ok">● เปิดให้ทุกคน</span> @else <span class="pill-bad">ปิดอยู่</span> @endif
            </h3>
            <p class="muted" style="margin:0">{{ $enabled
                ? 'หน้า /grid ลิงก์ชวน /r/… และเมนู "ชวนเพื่อน" ใช้งานได้'
                : 'คนทั่วไปเข้า /grid ไม่ได้ (404) · ผู้ดูแลที่เข้าหลังบ้านไว้เปิดดูตัวอย่างได้ · เปิดเมื่อที่ปรึกษากฎหมายตรวจแผนแล้ว' }}</p>
        </div>
        @if ($me->isOwner())
            <form method="POST" action="{{ route('admin.grid.toggle') }}" x-data="safeForm(@js($enabled ? 'ปิด IDTC Grid?' : 'เปิด IDTC Grid ให้ทุกคน?'), @js($enabled
                ? ['หน้า /grid จะตอบ 404 ลิงก์ชวนพาไปหน้าแรก', 'ข้อมูลการผูกและรางวัลที่เกิดแล้วยังอยู่ครบ']
                : ['หน้า /grid ลิงก์ชวน และเมนู "ชวนเพื่อน" ขึ้นบนเว็บทันที', 'ยืนยันว่าที่ปรึกษากฎหมายตรวจแผนและข้อความแล้ว']), @js($enabled))" @submit="submit($event)">
                @csrf
                <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
                <button class="btn {{ $enabled ? 'btn-danger' : 'btn-primary' }} btn-sm" type="submit" :disabled="busy">{{ $enabled ? 'ปิดระบบ' : 'เปิดระบบ' }}</button>
            </form>
        @else
            <span class="muted">เปิด/ปิดได้เฉพาะเจ้าของ</span>
        @endif
    </div>

    <div class="kpis section-gap">
        <div class="kpi"><span>กระเป๋าที่ผูกผู้ชวน</span><b>{{ number_format($t['linked']) }}</b><em class="muted">ผู้ซื้อทั้งหมด {{ number_format($t['buyers']) }}</em></div>
        <div class="kpi"><span>ครบระยะถือ รอรอบจ่าย</span><b>{{ $big($t['ready']) }}</b><em class="muted">IDTC</em></div>
        <div class="kpi"><span>รอผู้ซื้อถือครบ / Sprint</span><b>{{ $big($t['wait']) }}</b><em class="muted">IDTC</em></div>
        <div class="kpi"><span>พักไว้ (ตรวจโกง)</span><b>{{ $big($t['held']) }}</b><em class="muted">IDTC</em></div>
    </div>

    <div class="grid-2 section-gap">
        <div class="panel">
            <h3>งบรางวัล <span class="meta">กันจากก้อนดำเนินงาน/การตลาด</span></h3>
            <div class="fuse">
                <div class="track"><i style="width:{{ $usedPct }}%"></i><b style="left:{{ $worstPct }}%" title="ภาระสูงสุดถ้าขายหมด"></b></div>
                <div class="row"><span>ผูกพันแล้ว (รอ+ครบ+พัก) <b class="mono">{{ $big($t['committed']) }}</b></span><span>งบ <b class="mono">{{ $big($t['budget']) }}</b> IDTC</span></div>
                <div class="row"><span>ภาระสูงสุดถ้าขายหมด (อัตรารวมสูงสุด {{ $pct($t['max_rate']) }}%)</span><b class="mono" style="color:#f5c86b">{{ $big($t['worst']) }} · {{ number_format($t['worst'] / $budget * 100, 1) }}%</b></div>
                <div class="row"><span>คืนเข้างบ (ไม่มีสาย {{ $big($t['returned_none']) }} · ระดับไม่ถึง {{ $big($t['returned_rank']) }} · ขาย/โอนออกก่อนครบ {{ $big($t['returned_sold'] ?? 0) }} · Sprint แจกไม่หมด {{ $big($t['returned_sprint']) }} · Binary จ่ายไม่ถึงกอง {{ $big($t['returned_binary'] ?? 0) }})</span><b class="mono">{{ $big($t['returned']) }}</b></div>
            </div>
            @php $hold = $t['hold'] ?? ['enabled' => false, 'covered' => null, 'gaps' => []]; @endphp
            @if (! $hold['enabled'])
                <div class="flash warn" role="note" style="margin-top:12px"><b>ยังไม่ตรวจการถือจริง</b> — ยังไม่ได้ตั้งที่อยู่สัญญาเหรียญ IDTC ระบบนับระยะถือจากเวลาอย่างเดียว ไม่รู้ว่าผู้ซื้อขายออกไปแล้วหรือไม่ · ตั้งได้ที่ <a href="{{ route('admin.settings') }}">ตั้งค่า</a></div>
            @elseif ($hold['covered'] === null)
                <div class="flash warn" role="note" style="margin-top:12px"><b>รอดึงการโอนเหรียญ</b> — ยังไม่มีรางวัลใดพร้อมจ่ายจนกว่าระบบจะดึง event Transfer ของเหรียญถึงวันครบระยะถือ (งานตามเวลา idtc:index-sales ทุกนาที)</div>
            @else
                <div class="row" style="margin-top:12px"><span>ตรวจการถือจากการโอนบนเชน ดึงครบถึง</span><b class="mono">{{ F::dateTime($hold['covered']) }}</b></div>
                @if ($hold['gaps'])
                    <div class="flash warn" role="note">{{ number_format(count($hold['gaps'])) }} กระเป๋ามีประวัติการโอนก่อนบล็อกเริ่มดึงไม่ครบ (ยอดคำนวณติดลบ) — ระบบไม่ตัดรางวัลของกระเป๋าเหล่านี้ ตรวจเองในหน้าสมาชิก</div>
                @endif
            @endif
            @if ($t['worst'] > $t['budget'])
                <div class="flash err" role="alert" style="margin-top:12px">ภาระสูงสุดเกินงบ — ลดอัตราในหน้า <a href="{{ route('admin.grid.plan') }}">แผนรางวัล</a> (แผนใหม่มีผลสัปดาห์ถัดไป)</div>
            @endif
        </div>
        <div class="panel">
            <h3>ระดับสมาชิก <span class="meta">คิดจากยอด ณ ตอนนี้</span></h3>
            <div class="kv">
                @foreach (G::RANKS as $key => $label)
                    <div><span class="gr {{ $key }}">{{ $label }}</span><b>{{ number_format($t['ranks'][$key] ?? 0) }}</b></div>
                @endforeach
            </div>
            @if ($pending)
                <div class="flash info" role="note">มีแผนใหม่รอมีผลสัปดาห์ขายที่ {{ $pending['from'] + 1 }} — <a href="{{ route('admin.grid.plan') }}">ดูแผน</a></div>
            @endif
        </div>
    </div>

    <div class="panel section-gap">
        <h3>รายสัปดาห์ขาย <span class="meta">IDTC · รางวัลที่ไม่คืนงบ</span></h3>
        @if (! $book->weeks)
            <div class="empty"><x-admin.icon name="chart"/><b>ยังไม่มียอดซื้อ</b><span>ตัวเลขจะขึ้นเองเมื่อมีการซื้อ presale (ระบบดึงยอดทุกนาที)</span></div>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>สัปดาห์</th><th class="r">USDT</th><th class="r">IDTC ขาย</th><th class="r">ซื้อ / คนใหม่</th><th class="r">วง 1</th><th class="r">วง 2</th><th class="r">วง 3</th><th class="r">ผู้ซื้อ</th><th class="r">Sprint (แจก/กอง)</th><th>Sprint</th><th class="r">Binary (แจก/กอง)</th></tr></thead>
                <tbody>
                    @foreach (array_reverse($book->weeks, true) as $k => $w)
                        <tr>
                            <td>{{ $k + 1 }} <span class="muted">· {{ GridPlan::weekStart($k)->setTimezone('Asia/Bangkok')->format('j/n') }}</span></td>
                            <td class="r">{{ number_format($w['usdt'], 2) }}</td>
                            <td class="r">{{ $big($w['idtc']) }}</td>
                            <td class="r">{{ number_format($w['buys']) }} / {{ number_format($w['first']) }}</td>
                            <td class="r">{{ $big($w['ring'][1]) }}</td>
                            <td class="r">{{ $big($w['ring'][2]) }}</td>
                            <td class="r">{{ $big($w['ring'][3]) }}</td>
                            <td class="r">{{ $big($w['buyer']) }}</td>
                            <td class="r">{{ $big($w['sprint_paid']) }} / {{ $big($w['sprint_pool']) }}</td>
                            <td><span class="gs {{ $w['sprint_status'] }}">{{ $w['sprint_status'] === 'live' ? 'กำลังสะสม' : (G::STATUS[$w['sprint_status']] ?? $w['sprint_status']) }}</span></td>
                            <td class="r">{{ ($w['binary_pool'] ?? 0) > 0 ? $big($w['binary_paid']).' / '.$big($w['binary_pool']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
        <p class="fine">ช่วงนี้เป็นการคำนวณและติดตาม — รอบจ่ายบนเชน (Merkle) และการตรวจว่าผู้ซื้อถือเหรียญครบระยะจริง จะมาในช่วงถัดไป</p>
    </div>
@endsection

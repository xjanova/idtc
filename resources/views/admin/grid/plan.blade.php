@extends('layouts.admin')
@php
    use App\Services\Grid\GridPlan;
    use App\Support\AdminFormat as F;
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v * 100, 3), '0'), '.');
    $ro = ! $me->canEdit('grid_plan');
    $boostRows = $plan['boost'];
    while (count($boostRows) < 3) { $boostRows[] = null; }
    $field = fn (string $name, $value) => old($name, $value);
    $maxNow = GridPlan::maxRate($current);
@endphp

@section('sub')
    อัตราของสัปดาห์ที่กำลังขายไม่เปลี่ยนกลางทาง · แผนที่บันทึกมีผล{{ $nowWeek < 0 ? 'ตั้งแต่เปิดขายสัปดาห์แรก' : 'ตั้งแต่สัปดาห์ขายที่ '.($nextWeek + 1) }}
@endsection

@section('content')
    @if ($pending)
        <div class="flash info" role="note">มีแผนที่รอมีผลสัปดาห์ขายที่ {{ $pending['from'] + 1 }} — ฟอร์มด้านล่างแสดงแผนนั้น บันทึกอีกครั้งจะแก้แผนที่รออยู่ (ไม่ซ้อนเวอร์ชัน)</div>
    @endif

    <div class="grid-2">
        <div class="panel">
            <h3>แผนที่ใช้อยู่ตอนนี้</h3>
            <div class="kv">
                <div><span>วง 1 / 2 / 3</span><b>{{ $pct($current['levels'][0]) }}% / {{ $pct($current['levels'][1]) }}% / {{ $pct($current['levels'][2]) }}%</b></div>
                <div><span>ส่วนผู้ซื้อ</span><b>{{ $pct($current['buyer']) }}%</b></div>
                <div><span>โบนัสวง 1 ช่วงเปิดตัว</span><b>{{ $current['boost'] ? implode(' · ', array_map(fn ($b) => 'สัปดาห์ '.($b['from'] + 1).'–'.$b['to'].' +'.$pct($b['add']).'%', $current['boost'])) : '—' }}</b></div>
                <div><span>Sprint / เพดานต่อคน</span><b>{{ $pct($current['sprint']) }}% / {{ $pct($current['sprint_cap']) }}%</b></div>
                <div><span>Binary: กอง / จับคู่ / เพดานต่อคน</span><b>{{ $current['binary']['pool'] > 0 ? $pct($current['binary']['pool']).'% / '.$pct($current['binary']['pair']).'% / '.$pct($current['binary']['cap']).'%' : 'ปิดอยู่ (กอง 0%)' }}</b></div>
                <div><span>ระยะถือ / รับทันที</span><b>{{ $current['hold_days'] }} วัน / {{ $pct($current['instant']) }}%</b></div>
                <div><span>งบ</span><b>{{ number_format((float) $current['budget']) }} IDTC</b></div>
                <div><span>อัตรารวมสูงสุดต่อการซื้อ</span><b>{{ $pct($maxNow) }}% → ถ้าขายครบ {{ number_format($saleCap) }} IDTC จ่ายไม่เกิน {{ number_format($saleCap * $maxNow) }}</b></div>
            </div>
        </div>
        <div class="panel">
            <h3>ประวัติเวอร์ชัน</h3>
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>มีผลตั้งแต่</th><th>เหตุผล</th><th>โดย</th></tr></thead>
                <tbody>
                    @foreach ($versions as $v)
                        <tr>
                            <td>สัปดาห์ {{ $v['from'] + 1 }}@if ($v['from'] > max(0, $nowWeek)) <span class="gs wait">รอมีผล</span>@endif</td>
                            <td class="cellwrap">{{ $v['note'] }}</td>
                            <td>{!! $v['by'] ? '<span class="mono">'.e(F::addr($v['by'])).'</span>' : '<span class="muted">config</span>' !!}@if ($v['at'])<br><span class="muted">{{ F::dateTime($v['at']) }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    </div>

    @unless ($ro)
        <form class="panel form section-gap" method="POST" action="{{ route('admin.grid.plan.store') }}" x-data="safeForm('บันทึกแผนรางวัลใหม่?', @js([$nowWeek < 0 ? 'มีผลตั้งแต่เปิดขายสัปดาห์แรก' : 'มีผลตั้งแต่สัปดาห์ขายที่ '.($nextWeek + 1).' — การซื้อก่อนหน้านั้นใช้อัตราเดิม', 'ระบบตรวจว่าภาระสูงสุดถ้าขายหมดไม่เกินงบก่อนบันทึก']))" @submit="submit($event)">
            @csrf
            <h3>แก้แผน <span class="meta">หน่วย % ของ IDTC ที่ซื้อ</span></h3>
            <div class="plan-grid">
                @foreach ([['l1', 'วง 1 (%)', $plan['levels'][0]], ['l2', 'วง 2 (%)', $plan['levels'][1]], ['l3', 'วง 3 (%)', $plan['levels'][2]], ['buyer', 'ส่วนผู้ซื้อ (%)', $plan['buyer']], ['sprint', 'กอง Sprint (%)', $plan['sprint']], ['sprint_cap', 'เพดาน Sprint ต่อคน (%)', $plan['sprint_cap']], ['instant', 'รับทันทีเมื่อถึงรอบ (%)', $plan['instant']]] as [$name, $label, $value])
                    <label class="field"><span>{{ $label }}</span><input class="input" name="{{ $name }}" inputmode="decimal" value="{{ $field($name, $pct($value)) }}" @error($name === 'l1' ? 'levels.0' : ($name === 'l2' ? 'levels.1' : ($name === 'l3' ? 'levels.2' : $name))) aria-invalid="true" @enderror></label>
                @endforeach
                <label class="field"><span>ระยะถือ (วัน)</span><input class="input" name="hold_days" inputmode="numeric" value="{{ $field('hold_days', $plan['hold_days']) }}"></label>
                <label class="field"><span>งบ (IDTC)</span><input class="input mono" name="budget" inputmode="numeric" value="{{ $field('budget', $plan['budget']) }}" @error('budget') aria-invalid="true" @enderror></label>
            </div>
            @foreach (['levels.0', 'levels.1', 'levels.2', 'buyer', 'sprint', 'sprint_cap', 'instant', 'hold_days', 'budget'] as $k)
                @error($k)<p class="err">{{ $message }}</p>@enderror
            @endforeach

            <h3 style="margin-top:18px">โบนัสวง 1 ช่วงเปิดตัว <span class="meta">สัปดาห์ขาย เริ่ม–ถึง (นับจาก 1) · เว้นว่าง = ไม่ใช้แถวนั้น</span></h3>
            @foreach ($boostRows as $i => $b)
                <div class="plan-grid" style="margin-bottom:8px">
                    <label class="field"><span>ช่วงที่ {{ $i + 1 }}: สัปดาห์เริ่ม</span><input class="input" name="boost[{{ $i }}][from]" inputmode="numeric" value="{{ old("boost.$i.from", $b ? $b['from'] + 1 : '') }}"></label>
                    <label class="field"><span>ถึงสัปดาห์</span><input class="input" name="boost[{{ $i }}][to]" inputmode="numeric" value="{{ old("boost.$i.to", $b ? $b['to'] : '') }}"></label>
                    <label class="field"><span>เพิ่ม (%)</span><input class="input" name="boost[{{ $i }}][add]" inputmode="decimal" value="{{ old("boost.$i.add", $b ? $pct($b['add']) : '') }}"></label>
                </div>
                @error("boost.$i")<p class="err">{{ $message }}</p>@enderror
            @endforeach
            @error('boost')<p class="err">{{ $message }}</p>@enderror

            <h3 style="margin-top:18px">Binary — จับคู่ยอดสองขา <span class="meta">กอง 0% = ปิด · ตำแหน่งซ้าย/ขวาบันทึกไว้ทุกคนอยู่แล้ว เปิดเมื่อไรก็ใช้ได้</span></h3>
            <div class="plan-grid">
                <label class="field"><span>กอง Binary (% ของ IDTC ที่ขายในสัปดาห์)</span><input class="input" name="bin_pool" inputmode="decimal" value="{{ $field('bin_pool', $pct($plan['binary']['pool'])) }}" @error('binary.pool') aria-invalid="true" @enderror></label>
                <label class="field"><span>อัตราจับคู่ (% ของยอดขาอ่อน)</span><input class="input" name="bin_pair" inputmode="decimal" value="{{ $field('bin_pair', $pct($plan['binary']['pair'])) }}" @error('binary.pair') aria-invalid="true" @enderror></label>
                <label class="field"><span>เพดาน Binary ต่อคน (% ของกอง)</span><input class="input" name="bin_cap" inputmode="decimal" value="{{ $field('bin_cap', $pct($plan['binary']['cap'])) }}" @error('binary.cap') aria-invalid="true" @enderror></label>
                <label class="field"><span>เพื่อนตรงที่ซื้อแล้ว ต่อขา (คน)</span><input class="input" name="bin_directs" inputmode="numeric" value="{{ $field('bin_directs', $plan['binary']['directs']) }}" @error('binary.directs') aria-invalid="true" @enderror></label>
            </div>
            @foreach (['binary.pool', 'binary.pair', 'binary.cap', 'binary.directs'] as $k)
                @error($k)<p class="err">{{ $message }}</p>@enderror
            @endforeach
            <p class="fine">รางวัลจับคู่ของสัปดาห์ = อัตราจับคู่ × ยอดขาที่น้อยกว่า (IDTC) · จ่ายรวมไม่เกินกองของสัปดาห์ ถ้าเกินทุกคนถูกลดลงเท่ากัน ที่จ่ายไม่ถึงคืนเข้างบ · ส่วนเกินของขาที่มากกว่ายกไปสัปดาห์ถัดไป · กองนับรวมในอัตรารวมสูงสุดที่ใช้ตรวจงบ</p>

            <h3 style="margin-top:18px">เงื่อนไขระดับ</h3>
            <div class="plan-grid">
                <label class="field"><span>สปาร์ก: ซื้อเอง (USDT)</span><input class="input" name="spark_own" inputmode="decimal" value="{{ $field('spark_own', $plan['rank']['spark_own'] + 0) }}"></label>
                <label class="field"><span>เซลล์: เพื่อนตรง (คน)</span><input class="input" name="cell_d" inputmode="numeric" value="{{ $field('cell_d', $plan['rank']['cell_d']) }}"></label>
                <label class="field"><span>กริด: เพื่อนตรง (คน)</span><input class="input" name="grid_d" inputmode="numeric" value="{{ $field('grid_d', $plan['rank']['grid_d']) }}"></label>
                <label class="field"><span>กริด: ยอดทีม (USDT)</span><input class="input" name="grid_t" inputmode="decimal" value="{{ $field('grid_t', $plan['rank']['grid_t'] + 0) }}"></label>
                <label class="field"><span>คอร์: เพื่อนตรง (คน)</span><input class="input" name="core_d" inputmode="numeric" value="{{ $field('core_d', $plan['rank']['core_d']) }}"></label>
                <label class="field"><span>คอร์: ยอดทีม (USDT)</span><input class="input" name="core_t" inputmode="decimal" value="{{ $field('core_t', $plan['rank']['core_t'] + 0) }}"></label>
            </div>
            @error('rank')<p class="err">{{ $message }}</p>@enderror
            <p class="fine">ระดับ ระยะถือ และงบ ใช้ค่าล่าสุดกับทุกรายการ (ไม่แยกตามสัปดาห์) · อัตรารางวัลแยกตามสัปดาห์ที่ซื้อ</p>

            <label class="field" style="margin-top:12px"><span>เหตุผลของการแก้ (ขึ้นในบันทึกการทำงาน)</span><input class="input" name="note" minlength="8" maxlength="500" required value="{{ old('note') }}" placeholder="เช่น ลดโบนัสช่วงเปิดตัวตามคำแนะนำที่ปรึกษากฎหมาย" @error('note') aria-invalid="true" @enderror>@error('note')<span class="err">{{ $message }}</span>@enderror</label>
            <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกแผน</button></div>
        </form>
    @endunless
@endsection

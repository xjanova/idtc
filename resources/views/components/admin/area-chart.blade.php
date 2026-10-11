@props(['series', 'field' => 'usdt', 'unit' => 'USDT'])
@php
    // กราฟพื้นที่ (สไตล์เดียวกับต้นแบบ) — คำนวณฝั่งเซิร์ฟเวอร์ ไม่ต้องใช้ไลบรารี
    // เส้น/พื้นเป็น SVG ที่ยืดตามกล่อง · ตัวเลขแกนเป็น HTML จึงคมทุกขนาดจอ
    $values = array_map(fn ($p) => (float) $p[$field], $series);
    $count = count($values);
    $n = max($count, 2);
    $peak = max($values ?: [0]);
    $nice = function (float $v): float {
        if ($v <= 0) {
            return 1.0;
        }
        $exp = floor(log10($v));
        $f = $v / 10 ** $exp;
        $step = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10));

        return $step * 10 ** $exp;
    };
    $max = $nice($peak * 1.1);
    // พิกัดเป็นเปอร์เซ็นต์ของกล่อง (0–100)
    $X = fn ($i) => $count > 1 ? 100 * $i / ($n - 1) : 50;
    $Y = fn ($v) => 100 - 100 * ($max > 0 ? $v / $max : 0);
    $line = '';
    $area = 'M0,100';
    foreach ($values as $i => $v) {
        $p = round($X($i), 3).','.round($Y($v), 3);
        $line .= ($i ? 'L' : 'M').$p;
        $area .= 'L'.$p;
    }
    $area .= 'L100,100Z';
    $fmtTick = fn ($v) => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'K' : rtrim(rtrim(number_format($v, 2), '0'), '.');
    $labelEvery = $count > 16 ? 5 : 2;
    $empty = $peak <= 0;
    $gid = 'sg'.substr(md5(json_encode($series)), 0, 8);
    $last = $count ? $values[$count - 1] : 0;
@endphp
<div class="achart" role="img" aria-label="กราฟยอดขายรายวัน {{ $count }} วันล่าสุด หน่วย {{ $unit }}">
    <div class="achart-y" aria-hidden="true">
        @foreach ([$max, $max / 2, 0] as $tick)
            <span>{{ $fmtTick($tick) }}</span>
        @endforeach
    </div>
    <div class="achart-plot">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <defs><linearGradient id="{{ $gid }}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="rgba(163,230,53,.35)"/><stop offset="1" stop-color="rgba(163,230,53,0)"/></linearGradient></defs>
            @foreach ([0, 50, 100] as $gy)
                <line x1="0" x2="100" y1="{{ $gy }}" y2="{{ $gy }}" stroke="rgba(220,255,240,.08)" vector-effect="non-scaling-stroke"/>
            @endforeach
            @unless ($empty)
                <path d="{{ $area }}" fill="url(#{{ $gid }})"/>
            @endunless
            <path d="{{ $line }}" fill="none" stroke="{{ $empty ? 'rgba(220,255,240,.25)' : '#a3e635' }}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
        </svg>
        @unless ($empty)
            <i class="achart-dot" style="left: {{ round($X($count - 1), 3) }}%; top: {{ round($Y($last), 3) }}%"></i>
        @endunless
        <div class="achart-hits">
            @foreach ($series as $p)
                <span title="{{ $p['label'] }} · {{ number_format((float) $p[$field], 2) }} {{ $unit }} · {{ $p['count'] }} รายการ"></span>
            @endforeach
        </div>
        @if ($empty)
            <div class="chart-empty">ยังไม่มียอดขายในช่วงนี้</div>
        @endif
    </div>
    <div class="achart-x" aria-hidden="true">
        @foreach ($series as $i => $p)
            @if (($count - 1 - $i) % $labelEvery === 0)
                <span style="left: {{ round($X($i), 3) }}%">{{ $p['label'] }}</span>
            @endif
        @endforeach
    </div>
</div>

@extends('layouts.admin')
@php
    use App\Services\Binary\BinaryMap;
    use App\Support\AdminFormat as F;
    use App\Support\Idtc;
    $zero = '0x'.str_repeat('0', 40);
    $root = $slice['root'];
    $top = $slice['nodes'][0] ?? null;          // แถวของกระเป๋าบนสุดที่แสดง — ดูลำดับคอลัมน์ที่ BinaryMap
    $num = fn ($v) => number_format((float) $v);
    $usd = fn ($v) => number_format((float) $v, 2);
    $mq = ['depth' => $depth];
    $crumbs = $slice['crumbs'];
    $long = count($crumbs) > 7;                  // สายบนยาวมาก: แสดงบนสุด 2 + ใกล้สุด 4
    $shownCrumbs = $long ? array_merge(array_slice($crumbs, 0, 2), [null], array_slice($crumbs, -4)) : $crumbs;
    $depths = array_values(array_unique(array_merge(BinaryMap::DEPTHS, [$depth])));
    sort($depths);
    $payload = [
        'root' => $root,
        'nodes' => $slice['nodes'],
        'mapUrl' => route('admin.binary.map', $mq),
        'treeUrl' => route('admin.binary.tree'),
        'memberUrl' => $canMember ? route('admin.grid.member', $zero) : null,
        'zero' => $zero,
    ];
@endphp

@section('sub')
    ต้นไม้สองขาของแผน Binary — ขาซ้ายอยู่ซ้าย ขาขวาอยู่ขวา · ตัวเลขเป็นยอดฝาก USDT ของแผน Binary · หน้านี้ดูอย่างเดียว จัด/ย้ายสายทำที่เมนู "จัดสาย · ย้ายสาย"
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary') }}">← แผน Binary</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.tree', array_filter(['root' => $asked])) }}"><x-admin.icon name="users"/>จัดสาย · ย้ายสาย</a>
    @if ($asked)
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.map', $mq) }}"><x-admin.icon name="tree"/>ผังหลัก</a>
    @endif
@endsection

@section('content')
    @if ($missing)
        <p class="flash err" role="alert">ไม่พบกระเป๋า <b class="mono">{{ $missing }}</b> ในแผน Binary — ตรวจที่อยู่อีกครั้ง (ด้านล่างเป็นผังหลัก)</p>
    @endif

    @if ($root === null)
        <div class="panel" data-testid="bmap-empty">
            <p class="muted">ยังไม่มีผัง — ยังไม่มีใครถูกวางในผัง Binary</p>
            <p class="section-gap"><a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.tree') }}">ไปหน้าจัดสาย</a></p>
        </div>
    @else
        <div class="kpis">
            <div class="kpi"><span>กระเป๋าในผังนี้</span><b class="num">{{ $num($slice['total']) }}</b><em class="muted">นับจาก <span class="mono">{{ Idtc::short($root) }}</span> ลงไปทั้งหมด</em></div>
            <div class="kpi bmap-kpi l"><span>ขาซ้าย</span><b class="num">{{ $num($top[5]) }} <small>คน</small></b><em class="muted">ยอดขารวม {{ $usd($top[7]) }} USDT</em></div>
            <div class="kpi bmap-kpi r"><span>ขาขวา</span><b class="num">{{ $num($top[6]) }} <small>คน</small></b><em class="muted">ยอดขารวม {{ $usd($top[8]) }} USDT</em></div>
            <div class="kpi"><span>ผังแยกอื่น</span><b class="num">{{ $num($slice['others']) }}</b><em class="muted">{{ $slice['others'] > 0 ? 'ยังไม่ต่อกับผังนี้ — ดูรายการด้านล่าง' : 'ทุกคนที่มีสายอยู่ในผังเดียวกัน' }}</em></div>
        </div>

        <div class="panel" x-data="binaryMap()" data-testid="bmap">
            <nav class="crumbs" aria-label="สายบน">
                @if ($crumbs === [])
                    <b class="mono bmap-root">{{ F::addr($root) }}</b><span class="muted">· บนสุดของผัง</span>
                @else
                    @foreach ($shownCrumbs as $c)
                        @if ($c === null)
                            <span>… {{ $num(count($crumbs) - 6) }} ชั้น …</span><span>›</span>
                        @else
                            <a class="mono" href="{{ route('admin.binary.map', ['root' => $c] + $mq) }}" title="{{ $c }}">{{ Idtc::short($c) }}</a><span>›</span>
                        @endif
                    @endforeach
                    <b class="mono bmap-root">{{ F::addr($root) }}</b>
                @endif
            </nav>

            <form class="map-bar bmap-bar" method="GET" action="{{ route('admin.binary.map') }}" @submit.prevent="find()">
                <label class="field bmap-search"><span>ไปที่กระเป๋า</span>
                    <input class="input mono" type="text" name="root" x-model="q" placeholder="0x… (ใส่บางส่วนเพื่อหาในผังที่แสดง)" autocomplete="off" spellcheck="false" maxlength="64">
                </label>
                <button class="btn btn-ghost btn-sm" type="submit">ค้นหา</button>
                <label class="field"><span>ความลึก</span>
                    <select class="input" name="depth" onchange="location.href = this.dataset.url.replace('__D__', this.value)"
                            data-url="{{ route('admin.binary.map', array_filter(['root' => $asked]) + ['depth' => '__D__']) }}">
                        @foreach ($depths as $d)<option value="{{ $d }}" @selected($d === $depth)>{{ $d }} ชั้น</option>@endforeach
                    </select>
                </label>
                <div class="bmap-nav">
                @if ($crumbs !== [])
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.map', ['root' => $crumbs[count($crumbs) - 1]] + $mq) }}">↑ ขึ้นหนึ่งชั้น</a>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.map', ['root' => $slice['top']] + $mq) }}">⤒ บนสุดของผัง</a>
                @endif
                @if ($slice['edge']['L'])
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.map', ['root' => $slice['edge']['L']] + $mq) }}">↙ ล่างสุดขาซ้าย</a>
                @endif
                @if ($slice['edge']['R'])
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.map', ['root' => $slice['edge']['R']] + $mq) }}">↘ ล่างสุดขาขวา</a>
                @endif
                </div>
            </form>
            <p class="bmap-note" x-show="note" x-cloak role="status" x-text="note"></p>
            <p class="muted bmap-count">แสดง {{ $num($slice['shown']) }} จาก {{ $num($slice['total']) }} กระเป๋า{{ $slice['shown'] < $slice['total'] ? ' — ส่วนที่ลึกกว่านี้พับไว้เป็น +N ใต้กระเป๋าต้นสาย (ดับเบิลคลิกเพื่อเปิด)' : '' }} · ซูมเข้าเพื่ออ่านตัวเลขบนการ์ด · คลิกกระเป๋าเพื่อดูข้อมูลสองขา</p>

            <div class="map-wrap bmap section-gap" @keydown.window.escape="unpin(); draw()">
                <script type="application/json" data-bmap>@json($payload)</script>
                <canvas x-ref="cv" tabindex="0" role="img"
                        aria-label="ผังสายงาน Binary {{ $num($slice['shown']) }} กระเป๋า เริ่มจาก {{ Idtc::short($root) }} — ขาซ้าย {{ $num($top[5]) }} คน ขาขวา {{ $num($top[6]) }} คน · ลูกศร = เลื่อน, + − = ซูม, 0 = พอดีจอ"
                        @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up($event)" @pointercancel="up($event)" @pointerleave="leave()"
                        @wheel.prevent="wheel($event)" @dblclick="dive($event)" @keydown="key($event)"></canvas>
                <div class="map-tools">
                    <button class="btn btn-ghost btn-sm" type="button" @click="zoomBy(1.25)" aria-label="ขยาย">+</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="zoomBy(0.8)" aria-label="ย่อ">−</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="fit()">พอดีจอ</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="home()">บนสุด</button>
                </div>
                <div class="map-tip bmap-tip" x-ref="tip" x-show="tip" x-cloak :class="tip && tip.pinned ? 'pinned' : ''" :style="tip ? `left:${tip.x}px;top:${tip.y}px` : ''">
                    <template x-if="tip">
                        <div>
                            <b class="mono" x-text="tip.short"></b>
                            <span x-text="tip.where"></span>
                            <span class="bmap-state" :class="tip.tone" x-text="tip.state"></span>
                            <span x-show="tip.held" class="bad">พักรางวัลไว้ — ยอดไม่นับให้สายบนและไม่เข้ารอบจ่าย</span>
                            <span>ฝากเอง <b x-text="tip.own"></b> USDT</span>
                            <table class="bmap-legs">
                                <thead><tr><th></th><th class="l">ขาซ้าย</th><th class="r">ขาขวา</th></tr></thead>
                                <tbody>
                                    <tr><th>คนในขา</th><td x-text="tip.nl"></td><td x-text="tip.nr"></td></tr>
                                    <tr><th>ยอดขารวม (USDT)</th><td x-text="tip.l"></td><td x-text="tip.r"></td></tr>
                                    <tr><th>ยอดยกมา ยังไม่ได้จับคู่</th><td x-text="tip.cl"></td><td x-text="tip.cr"></td></tr>
                                    <tr><th>เพื่อนตรงที่ฝากถึงขั้นต่ำ</th><td x-text="tip.dl"></td><td x-text="tip.dr"></td></tr>
                                </tbody>
                            </table>
                            <span x-show="tip.fold" x-text="'สายล่างที่พับไว้ ' + tip.fold + ' กระเป๋า'"></span>
                            <em x-show="!tip.pinned">คลิกเพื่อเลือก · ดับเบิลคลิก = เริ่มผังจากกระเป๋านี้</em>
                            <span class="map-tip-act" x-show="tip.pinned">
                                <a class="btn btn-ghost btn-sm" :href="tip.map" x-show="!tip.root">เริ่มผังจากที่นี่</a>
                                <a class="btn btn-ghost btn-sm" :href="tip.tree">จัดสาย</a>
                                <a class="btn btn-ghost btn-sm" :href="tip.member" x-show="tip.member">ข้อมูลกระเป๋า</a>
                                <button class="btn btn-ghost btn-sm" type="button" @click="copy()" x-text="copied ? 'คัดลอกแล้ว' : 'คัดลอกที่อยู่'"></button>
                            </span>
                        </div>
                    </template>
                </div>
            </div>
            <ul class="bmap-legend" aria-label="ความหมายของสีในผัง">
                <li><i class="ln l"></i>สายขาซ้าย</li>
                <li><i class="ln r"></i>สายขาขวา</li>
                <li><i class="q"></i>ผ่านเงื่อนไขรับรางวัลจับคู่</li>
                <li><i class="a"></i>ฝากถึงขั้นต่ำ ({{ rtrim(rtrim(number_format($min, 2), '0'), '.') }} USDT)</li>
                <li><i></i>ยังฝากไม่ถึงขั้นต่ำ</li>
                <li><i class="held"></i>พักรางวัล</li>
                <li><i class="fold"></i>+N = สายล่างที่พับไว้</li>
            </ul>
        </div>
    @endif

    @if ($slice['tops'] !== [])
        <div class="panel section-gap" data-testid="bmap-tops">
            <h3>ผังแยกอื่น <span class="meta">กระเป๋าบนสุดของผังที่ยังไม่ต่อกับผังนี้ · ใหญ่สุดก่อน{{ $slice['others'] > count($slice['tops']) ? ' · แสดง '.count($slice['tops']).' จาก '.$num($slice['others']) : '' }}</span></h3>
            <div class="bmap-tops">
                @foreach ($slice['tops'] as $t)
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.map', ['root' => $t['wallet']] + $mq) }}"><span class="mono" title="{{ $t['wallet'] }}">{{ Idtc::short($t['wallet']) }}</span> · {{ $num($t['size']) }} คน</a>
                @endforeach
            </div>
        </div>
    @endif
@endsection

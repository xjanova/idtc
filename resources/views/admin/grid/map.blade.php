@extends('layouts.admin')
@php
    use App\Http\Controllers\Admin\GridController as G;
    use App\Services\Grid\GridLedger;
    use App\Support\AdminFormat as F;
    $zero = '0x'.str_repeat('0', 40);
    $rootNode = $root !== null ? $book->node($root) : null;
    $payload = [
        'root' => $root,
        'rootRank' => $rootNode ? (int) $rootNode['rank'] : null,
        'rootOwn' => $rootNode ? round((float) $rootNode['own_usdt'], 2) : null,
        'nodes' => $slice['nodes'],
        'ranks' => array_values(array_map(fn ($k) => G::RANKS[$k], GridLedger::RANKS)),
        'memberUrl' => route('admin.grid.member', $zero),
        'mapUrl' => route('admin.grid.map', ['depth' => $depth]),
        'zero' => $zero,
    ];
@endphp

@section('sub')
    วงกลมแต่ละชั้น = วงสายงานนับจาก{{ $root ? 'กระเป๋าที่เลือก' : 'ผู้แนะนำบนสุด' }} · วง 1–3 ได้รางวัล · ขนาดจุดตามยอดซื้อเอง · สีตามระดับ
@endsection

@section('actions')
    @if ($root)
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.member', $root) }}"><x-admin.icon name="wallet"/>ข้อมูลกระเป๋านี้</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.map', ['depth' => $depth]) }}">ดูทั้งระบบ</a>
    @endif
@endsection

@section('content')
    <div class="panel">
        <nav class="crumbs" aria-label="สายบน">
            <a href="{{ route('admin.grid.map', ['depth' => $depth]) }}">ทั้งระบบ</a>
            @foreach ($upline as $u)
                <span>›</span><a class="mono" href="{{ route('admin.grid.map', ['root' => $u, 'depth' => $depth]) }}">{{ F::addr($u) }}</a>
            @endforeach
            @if ($root)<span>›</span><b class="mono">{{ F::addr($root) }}</b>@endif
        </nav>

        <form class="map-bar" method="GET" action="{{ route('admin.grid.map') }}">
            @if ($root)<input type="hidden" name="root" value="{{ $root }}">@endif
            <label class="field"><span>ความลึก</span>
                <select class="input" name="depth" onchange="this.form.submit()">
                    @foreach ([3, 4, 6, 8] as $d)<option value="{{ $d }}" @selected($d === $depth)>{{ $d }} วง</option>@endforeach
                </select>
            </label>
            <p class="muted">แสดง {{ number_format($slice['shown']) }} จาก {{ number_format($slice['total']) }} กระเป๋า{{ $slice['shown'] < $slice['total'] ? ' — ส่วนที่เหลือพับไว้เป็นตัวเลข +N ที่จุดต้นสาย' : '' }}</p>
        </form>

        @if ($slice['nodes'] === [])
            <p class="muted section-gap">{{ $root ? 'กระเป๋านี้ยังไม่มีสายล่าง' : 'ยังไม่มีสมาชิกในระบบ' }}</p>
        @else
            <div class="map-wrap section-gap" x-data="gridMap()" @keydown.window.escape="tip = null">
                <script type="application/json" data-map>@json($payload)</script>
                <canvas x-ref="cv" role="img" aria-label="ผังสายงาน {{ number_format($slice['shown']) }} กระเป๋า — ใช้ตารางสมาชิกแทนได้ที่เมนูสมาชิกและสายงาน"
                        @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up($event)" @pointercancel="up($event)" @pointerleave="leave()"
                        @wheel.prevent="wheel($event)" @dblclick="dive($event)"></canvas>
                <div class="map-tools">
                    <button class="btn btn-ghost btn-sm" type="button" @click="zoomBy(1.25)" aria-label="ขยาย">+</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="zoomBy(0.8)" aria-label="ย่อ">−</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="fit()">พอดีจอ</button>
                </div>
                <div class="map-tip" x-show="tip" x-cloak :class="tip && tip.pinned ? 'pinned' : ''" :style="tip ? `left:${tip.x}px;top:${tip.y}px` : ''">
                    <template x-if="tip">
                        <div>
                            <b class="mono" x-text="tip.short"></b>
                            <span x-text="tip.rank"></span>
                            <span>ซื้อเอง <b x-text="tip.own"></b> USDT · ทีม <b x-text="tip.team"></b> USDT (<span x-text="tip.count"></span> กระเป๋า)</span>
                            <span x-show="tip.fold" x-text="'สายล่างที่พับไว้ ' + tip.fold + ' กระเป๋า'"></span>
                            <span x-show="tip.held" class="bad">พักรางวัลไว้</span>
                            <em x-show="!tip.pinned">คลิกเพื่อเลือก · ดับเบิลคลิก = เริ่มผังจากกระเป๋านี้</em>
                            <span class="map-tip-act" x-show="tip.pinned">
                                <a class="btn btn-ghost btn-sm" :href="tip.member">ข้อมูลกระเป๋า</a>
                                <a class="btn btn-ghost btn-sm" :href="tip.map">เริ่มผังจากที่นี่</a>
                            </span>
                        </div>
                    </template>
                </div>
                <ul class="map-legend" aria-label="สีตามระดับ">
                    @foreach (GridLedger::RANKS as $i => $k)
                        <li><i data-rank="{{ $i }}"></i>{{ G::RANKS[$k] }}</li>
                    @endforeach
                    <li><i class="held"></i>พักรางวัล</li>
                </ul>
            </div>
        @endif
    </div>
@endsection

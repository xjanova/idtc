@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    use App\Services\Chain\Units;
    use App\Support\Idtc;

    $salePrice = (float) config('idtc.sale.price_usd');
    $names = ['pair_usdt' => 'IDTC / USDT', 'pair_tpix' => 'IDTC / TPIX'];
@endphp

@section('sub')
    พูลบน TPIX DEX (Uniswap V2) · ราคาคำนวณจากปริมาณเหรียญในพูล (reserves) · แคช 15 วินาที
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="https://tpix.online" target="_blank" rel="noopener"><x-admin.icon name="external"/>เปิด TPIX DEX</a>
@endsection

@section('content')
    <div class="grid-2">
        @foreach ($names as $key => $name)
            @php $pool = $pools[$key]; @endphp
            <div class="panel">
                <h3>{{ $name }}
                    @if (! $pool['deployed']) <span class="pill-wait">รอ deploy</span>
                    @elseif ($pool['ok']) <span class="pill-ok">● อ่านได้</span>
                    @else <span class="pill-bad">อ่านไม่ได้</span> @endif
                </h3>

                @if (! $pool['deployed'])
                    <x-admin.pending-deploy what="พูล {{ $name }}"/>
                    @if ($pool['suggested'] ?? null)
                        <div class="flash info" style="margin:12px 0 0">พบพูลนี้บน DEX factory แล้ว: <x-admin.addr :address="$pool['suggested']"/> — ตรวจแล้วนำไปตั้งในหน้า <a href="{{ route('admin.settings') }}">ตั้งค่าเหรียญ</a></div>
                    @endif
                @elseif (! $pool['ok'])
                    <p class="err" role="alert">{{ $pool['error'] }}</p>
                    <div class="kv" style="margin-top:12px"><div><span>ที่อยู่พูล</span><b><x-admin.addr :address="$pool['address']"/></b></div></div>
                @else
                    @php
                        $q = Units::toFloat($pool['reserve_quote'], $pool['decimals']);
                        $i = Units::toFloat($pool['reserve_idtc'], 18);
                    @endphp
                    <div class="kv">
                        <div><span>ที่อยู่พูล</span><b><x-admin.addr :address="$pool['address']"/></b></div>
                        <div><span>IDTC ในพูล</span><b>{{ F::idtc($pool['reserve_idtc']) }}</b></div>
                        <div><span>{{ $pool['symbol'] }} ในพูล</span><b>{{ Units::format($pool['reserve_quote'], $pool['decimals'], $pool['symbol'] === 'USDT' ? 2 : 4) }}</b></div>
                        @if ($key === 'pair_usdt')
                            <div><span>ราคา IDTC</span><b>${{ F::price($pool['price']) }}</b></div>
                            @if ($pool['price'])
                                @php $dev = ($pool['price'] - $salePrice) / $salePrice * 100; @endphp
                                <div><span>เทียบราคา presale ${{ config('idtc.sale.price_usd') }}</span><b @class(['ok' => abs($dev) < 5, 'bad' => abs($dev) >= 5])>{{ $dev >= 0 ? '+' : '' }}{{ number_format($dev, 2) }}%</b></div>
                            @endif
                            <div><span>มูลค่าฝั่ง USDT</span><b>${{ F::money($q) }}</b></div>
                        @else
                            <div><span>ราคา IDTC</span><b>{{ F::price($pool['price']) }} TPIX</b></div>
                            @if (isset($pool['price_usd']))
                                <div><span>ราคาเป็น USD (ผ่านพูล TPIX/USDT)</span><b>${{ F::price($pool['price_usd']) }}</b></div>
                                <div><span>ราคา TPIX บน DEX</span><b>${{ F::price($pool['tpix_usd']) }}</b></div>
                            @endif
                        @endif
                        <div><span>อัปเดตล่าสุดในพูล</span><b>{{ $pool['updated_at'] ? F::dateTime($pool['updated_at']) : '—' }}</b></div>
                    </div>
                    @if ($i <= 0 || $q <= 0)
                        <p class="fine">พูลยังไม่มีสภาพคล่อง — ราคาจะมีเมื่อเติมเหรียญทั้งสองฝั่ง</p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="panel section-gap">
        <h3>การเติม/ถอนสภาพคล่อง</h3>
        <p class="fine">ทำบน TPIX DEX ด้วยกระเป๋าของโครงการโดยตรง — หลังบ้านไม่ถือเหรียญและไม่ส่งคำสั่งแทน</p>
        <div class="kv" style="margin-top:12px">
            @foreach (config('idtc.allocation', []) as $a)
                @if (str_contains($a['name'], 'สภาพคล่อง') || str_contains($a['name'], 'ดูแลราคา'))
                    <div><span>แผน: {{ $a['name'] }}</span><b>{{ number_format((float) $a['amount']) }} IDTC</b></div>
                @endif
            @endforeach
            <div><span>DEX factory</span><b><x-admin.addr :address="Idtc::contract('dex_factory')"/></b></div>
            <div><span>DEX router</span><b><x-admin.addr :address="Idtc::contract('dex_router')"/></b></div>
            <div><span>USDT (TPIX Chain)</span><b><x-admin.addr :address="Idtc::contract('usdt')"/></b></div>
            <div><span>WTPIX</span><b><x-admin.addr :address="Idtc::contract('wtpix')"/></b></div>
        </div>
    </div>
@endsection

@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    use App\Support\Idtc;
    $hasToken = Idtc::isDeployed('token');
@endphp

@section('sub')
    ยอดคงเหลือจริงบนเชน (แคช 15 วินาที) · TPIX = เหรียญหลักของเชน · IDTC แสดงเมื่อเหรียญขึ้นเชนแล้ว
@endsection

@section('content')
    @if (! $bal['ok'])
        <div class="flash err" role="alert">อ่านยอดจากเชนไม่ได้: {{ $bal['error'] }}</div>
    @endif

    <div class="panel">
        <h3>กระเป๋าและยอดคงเหลือ</h3>
        @if ($rows === [])
            <div class="empty"><x-admin.icon name="wallet"/><b>ยังไม่มีกระเป๋าให้แสดง</b><span>เพิ่มกระเป๋าโครงการด้านล่าง — กระเป๋ารับเงิน presale และผู้รับเหรียญปลดล็อกจะขึ้นเองเมื่อสัญญาขึ้นเชน</span></div>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>ชื่อ</th><th>ที่อยู่</th><th class="r">TPIX</th><th class="r">USDT</th><th class="r">IDTC</th><th>ที่มา</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $b = $bal['balances'][strtolower($row['address'])] ?? null; @endphp
                        <tr>
                            <td class="cellwrap">{{ $row['label'] }}</td>
                            <td><x-admin.addr :address="$row['address']"/></td>
                            <td class="r">{{ $b ? F::tpix($b['tpix']) : '—' }}</td>
                            <td class="r">{{ $b ? F::usdt($b['usdt']) : '—' }}</td>
                            <td class="r">{{ $hasToken ? ($b ? F::idtc($b['idtc']) : '—') : 'รอ deploy' }}</td>
                            <td class="muted">{{ $row['source'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <div class="panel section-gap">
        <h3>รายชื่อกระเป๋าโครงการ <span class="meta">ไม่เกิน {{ $max }} กระเป๋า</span></h3>
        @if (! $me->canWrite())
            <p class="readonly-note">สิทธิ์ของคุณดูได้อย่างเดียว</p>
        @else
            <form method="POST" action="{{ route('admin.wallets.update') }}" class="form" x-data="walletList(@js(old('address') ? collect(old('address'))->map(fn ($a, $i) => ['label' => old('label')[$i] ?? '', 'address' => $a])->values()->all() : $configured), {{ $max }})" @submit="if ($el.dataset.busy) { $event.preventDefault() } else { $el.dataset.busy = 1 }">
                @csrf
                <template x-for="(row, i) in rows" :key="i">
                    <div class="inline-form">
                        <label class="field"><span x-text="'ชื่อเรียก #' + (i + 1)"></span><input class="input" name="label[]" x-model="row.label" maxlength="60" placeholder="เช่น กระเป๋าการตลาด"></label>
                        <label class="field" style="flex:2 1 300px"><span>ที่อยู่</span><input class="input mono" name="address[]" x-model="row.address" placeholder="0x…" autocomplete="off" spellcheck="false"></label>
                        <button class="btn btn-ghost btn-sm" type="button" @click="remove(i)" aria-label="ลบแถว">ลบ</button>
                    </div>
                </template>
                <div class="actions">
                    <button class="btn btn-ghost btn-sm" type="button" @click="add()" :disabled="rows.length >= max">+ เพิ่มกระเป๋า</button>
                    <button class="btn btn-primary btn-sm" type="submit">บันทึกรายชื่อ</button>
                </div>
                <p class="hint">บันทึกเฉพาะชื่อและที่อยู่สาธารณะ — ห้ามใส่คีย์หรือซีดใด ๆ · ทุกการแก้ไขลงบันทึกการทำงาน</p>
            </form>
        @endif
    </div>
@endsection

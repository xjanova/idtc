@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    use App\Support\Idtc;
    $ro = ! $me->canWrite();
    $fixedLabels = ['usdt' => 'USDT (TPIX Chain)', 'wtpix' => 'WTPIX', 'dex_factory' => 'TPIX DEX factory', 'dex_router' => 'TPIX DEX router'];
@endphp

@section('sub')
    ที่อยู่สัญญาที่ตั้งตรงนี้แก้ทับค่าใน .env/config และหน้าเว็บสาธารณะใช้ทันที · ก่อนบันทึก ระบบตรวจว่ามีสัญญาอยู่จริงบน TPIX Chain
@endsection

@section('content')
    @if ($ro)
        <p class="readonly-note" style="margin-bottom:16px">สิทธิ์ของคุณดูได้อย่างเดียว — แก้ไขไม่ได้</p>
    @endif

    <div class="panel">
        <h3>ที่อยู่สัญญาของ IDTC <span class="meta">ว่าง = ยังไม่ deploy (หน้าเว็บแสดง "รอ deploy")</span></h3>
        <div class="contract-grid">
            @foreach ($rows as $key => $row)
                <form class="act" method="POST" action="{{ route('admin.settings.contracts') }}" data-target-field="address" data-target-label="ที่อยู่ใหม่ของ{{ $row['label'] }}" x-data="safeForm(@js('เปลี่ยนที่อยู่'.$row['label'].'?'), @js(['ระบบจะตรวจกับเชนก่อนว่ามีสัญญาอยู่จริงที่ที่อยู่นั้น', 'หน้าเว็บสาธารณะและหลังบ้านใช้ค่าใหม่ทันทีหลังบันทึก', 'เว้นช่องว่าง = ล้างค่าที่ตั้งจากหลังบ้าน (กลับไปใช้ค่าใน .env)']))" @submit="submit($event)">
                    @csrf
                    <input type="hidden" name="key" value="{{ $key }}">
                    <div class="act-head">
                        <div><b>{{ $row['label'] }}</b><p>{{ $row['hint'] }}</p></div>
                        @if ($row['effective'])
                            <span class="pill-ok">● ใช้งาน{{ $row['override'] ? ' (ตั้งจากหลังบ้าน)' : ' (จาก .env)' }}</span>
                        @else
                            <span class="pill-wait">รอ deploy</span>
                        @endif
                    </div>
                    <div class="kv" style="margin:0">
                        <div><span>ค่าที่ใช้อยู่</span><b><x-admin.addr :address="$row['effective']" full/></b></div>
                        @if ($row['override'] && $row['default'])
                            <div><span>ค่าใน .env (ถูกแก้ทับ)</span><b class="mono" style="font-size:12.5px;color:var(--fg-3)">{{ $row['default'] }}</b></div>
                        @endif
                    </div>
                    @unless ($ro)
                        <div class="inline-form">
                            <label class="field">
                                <span>ที่อยู่ใหม่</span>
                                <input class="input mono" name="address" value="{{ old('key') === $key ? old('address') : '' }}" placeholder="0x… (เว้นว่างแล้วกดล้าง = กลับไปใช้ค่าใน .env)" autocomplete="off" spellcheck="false" @error("address_$key") aria-invalid="true" @enderror>
                                @error("address_$key")<span class="err">{{ $message }}</span>@enderror
                            </label>
                            <div class="actions">
                                <button class="btn btn-primary btn-sm" type="submit" :disabled="busy">ตรวจกับเชนแล้วบันทึก</button>
                            </div>
                        </div>
                        @if ($row['override'])
                            <p class="hint">ล้างค่าที่ตั้งจากหลังบ้าน: เว้นช่องว่างแล้วกดบันทึก</p>
                        @endif
                    @endunless
                </form>
            @endforeach
        </div>
    </div>

    <div class="grid-2 section-gap">
        <form class="panel form" method="POST" action="{{ route('admin.settings.indexer') }}" x-data="safeForm('ตั้งบล็อกเริ่มดึงยอดขายใหม่?', @js(['รอบถัดไปจะดึง event Purchased ใหม่ตั้งแต่บล็อกนี้', 'รายการที่มีอยู่แล้วไม่ถูกลบและไม่เกิดซ้ำ']))" @submit="submit($event)">
            @csrf
            <h3>ตัวดึงยอดขาย <span class="meta">event Purchased ของสัญญา presale</span></h3>
            <div class="kv" style="margin:0">
                <div><span>บล็อกเริ่ม</span><b>{{ $startBlock !== null ? number_format($startBlock) : 'ยังไม่ตั้ง (ใช้บล็อกปัจจุบันตอนเริ่ม)' }}</b></div>
                <div><span>ดึงถึงบล็อก</span><b>{{ $indexState?->last_block !== null ? number_format($indexState->last_block) : '—' }}</b></div>
                <div><span>รันล่าสุด</span><b>{{ $indexState?->last_run_at ? F::dateTime($indexState->last_run_at) : 'ยังไม่เคยรัน' }}</b></div>
                @if ($indexState?->last_error)<div><span>ข้อผิดพลาดล่าสุด</span><b class="bad">{{ $indexState->last_error }}</b></div>@endif
            </div>
            @unless ($ro)
                <label class="field">
                    <span>ตั้งบล็อกเริ่มใหม่ (ดึงย้อนหลังตั้งแต่บล็อกนี้)</span>
                    <input class="input mono" name="start_block" inputmode="numeric" value="{{ old('start_block', $startBlock) }}" placeholder="เช่น บล็อกที่ deploy สัญญา presale" @error('start_block') aria-invalid="true" @enderror>
                    @error('start_block')<span class="err">{{ $message }}</span>@enderror
                    <small>ใส่บล็อกที่ deploy สัญญา presale (ดูจาก explorer) เพื่อดึงยอดตั้งแต่ต้น — ดึงซ้ำได้ ไม่เกิดรายการซ้ำ</small>
                </label>
                <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกบล็อกเริ่ม</button></div>
            @endunless
        </form>

        <div class="panel">
            <h3>การเชื่อมต่อเชน
                @if ($rpc['ok']) <span class="pill-ok">● ปกติ</span> @else <span class="pill-bad">มีปัญหา</span> @endif
            </h3>
            <div class="kv">
                <div><span>RPC (ฝั่งเซิร์ฟเวอร์)</span><b class="mono" style="font-size:12.5px">{{ config('idtc.chain.rpc') }}</b></div>
                <div><span>Chain ID</span><b>{{ $rpc['chain_id'] ?? '—' }} {{ $rpc['chain_id'] === (int) config('idtc.chain.id') ? '✓' : '' }}</b></div>
                <div><span>บล็อกล่าสุด</span><b>{{ $rpc['block'] !== null ? number_format($rpc['block']) : '—' }}</b></div>
                <div><span>เวลาตอบ</span><b>{{ $rpc['ms'] !== null ? number_format($rpc['ms']).' ms' : '—' }}</b></div>
                @if ($rpc['error'])<div><span>ปัญหา</span><b class="bad">{{ $rpc['error'] }}</b></div>@endif
            </div>
            <h3 style="margin-top:6px">สัญญาของเชน TPIX <span class="meta">คงที่ ไม่ต้องตั้ง</span></h3>
            <div class="kv" style="margin:0">
                @foreach ($fixed as $key => $address)
                    <div><span>{{ $fixedLabels[$key] ?? $key }}</span><b><x-admin.addr :address="$address"/></b></div>
                @endforeach
            </div>
        </div>
    </div>
@endsection

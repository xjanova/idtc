@extends('layouts.admin')

@section('sub')
    ข้อความบนหน้าเว็บสาธารณะ — บันทึกแล้วแสดงทันที · ทุกการแก้ไขลงบันทึกการทำงาน (ค่าเดิม → ค่าใหม่)
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('home') }}" target="_blank" rel="noopener"><x-admin.icon name="external"/>ดูหน้าเว็บ</a>
@endsection

@section('content')
    @php $ro = ! $me->canWrite(); @endphp
    @if ($ro)
        <p class="readonly-note" style="margin-bottom:16px">สิทธิ์ของคุณดูได้อย่างเดียว — แก้ไขไม่ได้</p>
    @endif
    <div class="grid-2">
        <form class="panel form" method="POST" action="{{ route('admin.content.update') }}" x-data="{ text: @js(old('announcement', $announcement)), textEn: @js(old('announcement_en', $announcementEn)), max: {{ $max }}, busy: false }" @submit="if (busy) { $event.preventDefault(); return; } busy = true">
            @csrf
            <input type="hidden" name="section" value="announcement">
            <h3>แถบประกาศบนหน้าเว็บ <span class="meta">เว้นว่าง = ไม่แสดง</span></h3>
            <label class="field">
                <span>ข้อความประกาศ (บรรทัดเดียว)</span>
                <textarea class="textarea" name="announcement" x-model="text" :maxlength="max" rows="3" placeholder="เช่น presale รอบแรกเปิดขาย 1 พ.ย. เวลา 10:00 น." @disabled($ro)></textarea>
                <small><span x-text="text.length"></span> / {{ $max }} ตัวอักษร · แสดงเป็นข้อความล้วน (ไม่รองรับ HTML)</small>
                @error('announcement')<span class="err">{{ $message }}</span>@enderror
            </label>
            <label class="field">
                <span>ข้อความประกาศภาษาอังกฤษ (หน้า /en)</span>
                <textarea class="textarea" name="announcement_en" x-model="textEn" :maxlength="max" rows="2" placeholder="e.g. Presale round 1 opens 1 Nov at 10:00 (UTC+7)" lang="en" @disabled($ro)></textarea>
                <small><span x-text="textEn.length"></span> / {{ $max }} ตัวอักษร · เว้นว่าง = หน้าภาษาอังกฤษไม่แสดงแถบประกาศ (ไม่เอาข้อความไทยไปแสดงแทน)</small>
                @error('announcement_en')<span class="err">{{ $message }}</span>@enderror
            </label>
            <div>
                <p class="hint" style="margin-bottom:6px">ตัวอย่างบนหน้าเว็บ</p>
                <div class="announce" style="border-radius:12px;border:1px solid var(--line)"><div class="wrap" style="padding-inline:14px"><span x-text="text.trim() || '(ไม่แสดงแถบประกาศ)'"></span></div></div>
            </div>
            <p class="hint">ห้ามสัญญาผลตอบแทนหรือกำไร (กฎ ก.ล.ต. สินทรัพย์ดิจิทัล) — ใช้แจ้งข่าว วันเวลา และลิงก์ทางการเท่านั้น</p>
            @unless ($ro)
                <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกประกาศ</button></div>
            @endunless
        </form>

        <form class="panel form" method="POST" action="{{ route('admin.content.update') }}" x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault(); return; } busy = true">
            @csrf
            <input type="hidden" name="section" value="social">
            <h3>ลิงก์โซเชียลทางการ <span class="meta">https:// เท่านั้น · เว้นว่าง = ไม่แสดง</span></h3>
            @foreach ($networks as $key => $name)
                <label class="field">
                    <span>{{ $name }}</span>
                    <input class="input" type="url" name="social[{{ $key }}]" value="{{ old("social.$key", $social[$key] ?? '') }}" placeholder="https://" maxlength="300" autocomplete="off" @disabled($ro) @error("social.$key") aria-invalid="true" @enderror>
                    @error("social.$key")<span class="err">{{ $message }}</span>@enderror
                </label>
            @endforeach
            @unless ($ro)
                <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกลิงก์</button></div>
            @endunless
        </form>
    </div>
@endsection

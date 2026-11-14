@extends('layouts.admin')
@php
    use App\Models\BinaryDeposit;
    use App\Services\Binary\BinaryPlan;
    use App\Services\Chain\Address;
    use App\Services\Grid\GridPlan;
    use App\Support\AdminFormat as F;
    use App\Support\Idtc;
    $t = $book['totals'];
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v * 100, 3), '0'), '.');
    $usd = fn ($v) => number_format((float) $v, 2);
    $big = fn ($v) => number_format((float) $v);
    $ro = ! $me->canEdit('binary');
    $owner = $me->isOwner();
    $quotaPct = $t['quota'] > 0 ? min(100, $t['idtc'] / $t['quota'] * 100) : 0;
@endphp

@section('sub')
    แผนโปรโมชั่น แยกจาก presale และ IDTC Grid · สมาชิกโอน USDT (เชน TPIX) เข้ากระเป๋า Binary = ซื้อ IDTC ทันที · รางวัลจับคู่จ่ายเป็น USDT จากกองของแต่ละสัปดาห์เท่านั้น
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.map') }}"><x-admin.icon name="tree"/>ผังสายงาน</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.tree') }}"><x-admin.icon name="users"/>จัดสาย · ย้ายสาย</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.rounds', 'usdt') }}"><x-admin.icon name="download"/>รอบจ่าย USDT</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary.rounds', 'idtc') }}"><x-admin.icon name="download"/>รอบส่ง IDTC</a>
    @unless ($ro)
        <form method="POST" action="{{ route('admin.binary.sync') }}" x-data="{ busy: false }" @submit="if (busy) { $event.preventDefault(); return; } busy = true">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit" :disabled="busy"><x-admin.icon name="refresh"/>อ่านยอดฝากตอนนี้</button>
        </form>
    @endunless
@endsection

@section('content')
    <div @class(['flash', 'ok' => $ready, 'warn' => ! $ready]) role="status">
        <b>{{ $ready ? 'แผนเปิดรับฝากอยู่' : ($enabled ? 'เปิดไว้ แต่ยังรับฝากไม่ได้' : 'แผนปิดอยู่') }}</b>
        @if ($missing) — {{ implode(' · ', $missing) }}@endif
        @if (! $enabled && ! $missing) — ตั้งค่าครบแล้ว เจ้าของกดเปิดได้@endif
        @if ($owner)
            <form method="POST" action="{{ route('admin.binary.toggle') }}" style="display:inline;margin-left:10px"
                  x-data="safeForm(@js($enabled ? 'ปิดแผน Binary?' : 'เปิดแผน Binary รับ USDT จริง?'), @js($enabled
                      ? ['USDT ที่โอนเข้ามาหลังจากนี้ไม่นับ และต้องคืนเงินเอง', 'ยอดเดิมและรางวัลที่เกิดแล้วยังอยู่ครบ']
                      : ['สมาชิกโอน USDT เข้ากระเป๋า '.($wallet ? F::addr($wallet) : '—').' แล้วนับเป็นยอดซื้อทันที', 'ตรวจที่อยู่กระเป๋า ราคา และโควตาให้ถูกก่อนเปิด', 'ให้ที่ปรึกษากฎหมายตรวจแผนก่อนเปิดรับเงินจริง']), @js(! $enabled))" @submit="submit($event)">
                @csrf
                <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
                <button class="btn btn-sm {{ $enabled ? 'btn-danger' : 'btn-primary' }}" type="submit" :disabled="busy">{{ $enabled ? 'ปิดแผน' : 'เปิดแผน' }}</button>
            </form>
        @endif
    </div>

    <div class="kpis section-gap">
        <div class="kpi"><span>USDT ที่ฝากเข้าแผน</span><b>{{ $usd($t['usdt']) }}</b><em class="muted">{{ $big($t['deposits']) }} รายการ · {{ $big($t['depositors']) }} กระเป๋า</em></div>
        <div class="kpi"><span>IDTC ที่ขายในแผน</span><b>{{ $big($t['idtc']) }}</b><em class="muted">โควตา {{ $big($t['quota']) }} · ใช้ไป {{ number_format($quotaPct, 1) }}%</em></div>
        <div class="kpi"><span>กองรางวัลจับคู่ (USDT)</span><b>{{ $usd($t['pool']) }}</b><em class="muted">พร้อมจ่าย {{ $usd($t['ready']) }} · ประมาณการสัปดาห์นี้ {{ $usd($t['wait']) }}</em></div>
        <div class="kpi"><span>พักไว้ / กองที่ไม่ถูกแจก</span><b>{{ $usd($t['held']) }}</b><em class="muted">ไม่ถูกแจก {{ $usd($t['unpaid']) }} USDT (อยู่กับโครงการ)</em></div>
    </div>

    <div class="grid-2 section-gap">
        <div class="panel">
            <h3>การรับเงิน <span class="meta">มีผลทันที · เจ้าของเท่านั้น</span></h3>
            <div class="kv">
                <div><span>กระเป๋า Binary (รับ USDT)</span><b>@if ($wallet)<x-admin.addr :address="$wallet" full/>@else<span class="muted">ยังไม่ตั้ง</span>@endif</b></div>
                <div><span>สัญญา USDT ที่นับ</span><b>@if ($usdt)<x-admin.addr :address="$usdt"/>@else<span class="muted">ยังไม่ตั้ง</span>@endif</b></div>
                <div><span>โควตา IDTC / ก้อนที่มา</span><b>{{ $big($quota) }} · {{ $source ? BinaryPlan::allocation($source)['name'] : 'ยังไม่ระบุ' }}</b></div>
                <div><span>USDT ส่วนที่ไม่เข้ากองรางวัล</span><b>{{ BinaryPlan::REST[$rest] }}</b></div>
                <div><span>สิ้นสุดแผน</span><b>{{ $ends ? F::dateTime($ends) : 'ไม่กำหนด' }}</b></div>
                <div><span>อ่านเชนล่าสุด</span><b>{{ $state?->last_block ? 'บล็อก '.number_format($state->last_block).($state->last_success_at ? ' · '.F::dateTime($state->last_success_at) : '') : 'ยังไม่เคยอ่าน' }}@if ($state?->last_error) <span class="pill-bad">ผิดพลาด</span>@endif</b></div>
            </div>
            @if ($state?->last_error)<p class="err">{{ $state->last_error }}</p>@endif
            @if ($owner)
                <form class="form" style="margin-top:14px" method="POST" action="{{ route('admin.binary.settings') }}" data-target-field="wallet"
                      x-data="safeForm('บันทึกค่าการรับเงินของแผน Binary?', @js(['USDT ของสมาชิกจะถูกส่งเข้าที่อยู่นี้ — ตรวจให้ครบทุกตัวอักษร', 'ต้องเป็นกระเป๋าที่คุณถือคีย์เอง (เซิร์ฟเวอร์ไม่ถือคีย์ และโอนคืนแทนไม่ได้)', 'เปลี่ยนกระเป๋า = นับเฉพาะการโอนเข้ากระเป๋าใหม่ตั้งแต่ตอนนี้']), true)" @submit="submit($event)">
                    @csrf
                    <label class="field"><span>ที่อยู่กระเป๋า Binary</span><input class="input mono" name="wallet" value="{{ old('wallet', $wallet ? Address::checksum($wallet) : '') }}" placeholder="0x…" autocomplete="off" spellcheck="false" required @error('wallet') aria-invalid="true" @enderror>@error('wallet')<span class="err">{{ $message }}</span>@enderror</label>
                    <div class="plan-grid">
                        <label class="field"><span>โควตา IDTC (หน่วยเต็ม)</span><input class="input mono" name="quota" inputmode="numeric" value="{{ old('quota', $quota > 0 ? number_format($quota, 0, '.', '') : '') }}" required @error('quota') aria-invalid="true" @enderror></label>
                        <label class="field"><span>IDTC มาจากก้อน</span>
                            <select class="select" name="source" required>
                                <option value="">— เลือก —</option>
                                @foreach ($sources as $a)<option value="{{ $a['key'] }}" @selected(old('source', $source) === $a['key'])>{{ $a['name'] }} ({{ $big($a['amount']) }})</option>@endforeach
                            </select>
                        </label>
                        <label class="field"><span>USDT ที่เหลือจากกองรางวัล</span>
                            <select class="select" name="rest">@foreach (BinaryPlan::REST as $k => $label)<option value="{{ $k }}" @selected(old('rest', $rest) === $k)>{{ $label }}</option>@endforeach</select>
                        </label>
                        <label class="field"><span>วันสิ้นสุด (เว้นว่างได้)</span><input class="input" type="date" name="ends" value="{{ old('ends', $ends?->setTimezone('Asia/Bangkok')->format('Y-m-d')) }}"></label>
                    </div>
                    @foreach (['quota', 'source', 'rest', 'ends'] as $k)@error($k)<p class="err">{{ $message }}</p>@enderror @endforeach
                    <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกการรับเงิน</button></div>
                    <p class="fine">ข้อความบนหน้า /binary จะบอกตามที่ตั้งไว้ที่นี่ว่าเงินส่วนที่เหลือไปที่ไหน — ตั้งให้ตรงกับที่ทำจริง</p>
                </form>
            @else
                <p class="fine">เฉพาะเจ้าของ (Owner) แก้กระเป๋า โควตา และเปิด/ปิดแผนได้</p>
            @endif
        </div>

        <div class="panel">
            <h3>อัตราของแผน <span class="meta">มีผลสัปดาห์ถัดไป · ยังไม่มียอดฝาก = มีผลทันที</span></h3>
            <div class="kv">
                <div><span>ราคา</span><b>{{ $current['price'] }} USDT ต่อ 1 IDTC</b></div>
                <div><span>ขั้นต่ำต่อครั้ง</span><b>{{ $usd($current['min_usdt']) }} USDT</b></div>
                <div><span>เข้ากองรางวัลจับคู่</span><b>{{ $pct($current['pool']) }}% ของ USDT ที่ฝาก</b></div>
                <div><span>จับคู่ / เพดานต่อคน</span><b>{{ $pct($current['pair']) }}% ของยอดขาอ่อน / {{ $pct($current['cap']) }}% ของกอง</b></div>
                <div><span>เพื่อนตรงที่ฝากแล้ว ต่อขา</span><b>{{ $current['directs'] }} คน</b></div>
            </div>
            @if ($pending)<p class="fine">มีอัตราใหม่รอมีผลสัปดาห์ที่ {{ $pending['from'] + 1 }} — ฟอร์มด้านล่างแสดงค่านั้น</p>@endif
            @unless ($ro)
                <form class="form" style="margin-top:14px" method="POST" action="{{ route('admin.binary.plan') }}" x-data="safeForm('บันทึกอัตราของแผน Binary?', @js(['อัตราใหม่ไม่กระทบยอดฝากที่บันทึกไปแล้ว', 'จ่ายรางวัลรวมไม่เกินกองของแต่ละสัปดาห์เสมอ']))" @submit="submit($event)">
                    @csrf
                    <div class="plan-grid">
                        <label class="field"><span>ราคา (USDT ต่อ 1 IDTC)</span><input class="input mono" name="price" inputmode="decimal" value="{{ old('price', $plan['price']) }}" @error('price') aria-invalid="true" @enderror></label>
                        <label class="field"><span>ขั้นต่ำต่อครั้ง (USDT)</span><input class="input" name="min_usdt" inputmode="decimal" value="{{ old('min_usdt', $plan['min_usdt'] + 0) }}"></label>
                        <label class="field"><span>เข้ากองรางวัล (% ของ USDT ที่ฝาก)</span><input class="input" name="pool" inputmode="decimal" value="{{ old('pool', $pct($plan['pool'])) }}"></label>
                        <label class="field"><span>อัตราจับคู่ (% ของยอดขาอ่อน)</span><input class="input" name="pair" inputmode="decimal" value="{{ old('pair', $pct($plan['pair'])) }}"></label>
                        <label class="field"><span>เพดานต่อคน (% ของกองสัปดาห์)</span><input class="input" name="cap" inputmode="decimal" value="{{ old('cap', $pct($plan['cap'])) }}"></label>
                        <label class="field"><span>เพื่อนตรงที่ฝากแล้ว ต่อขา (คน)</span><input class="input" name="directs" inputmode="numeric" value="{{ old('directs', $plan['directs']) }}"></label>
                    </div>
                    @foreach (['price', 'min_usdt', 'pool', 'pair', 'cap', 'directs'] as $k)@error($k)<p class="err">{{ $message }}</p>@enderror @endforeach
                    <label class="field" style="margin-top:10px"><span>เหตุผลของการแก้ (ขึ้นในบันทึกการทำงาน)</span><input class="input" name="note" minlength="8" maxlength="500" required value="{{ old('note') }}" @error('note') aria-invalid="true" @enderror>@error('note')<span class="err">{{ $message }}</span>@enderror</label>
                    <div class="actions"><button class="btn btn-primary btn-sm" type="submit" :disabled="busy">บันทึกอัตรา</button></div>
                </form>
            @endunless
        </div>
    </div>

    <div class="panel section-gap">
        <h3>รายการที่ไม่นับ — ต้องคืนเงิน <span class="meta">ระบบไม่มีคีย์ คืนเองไม่ได้ · โอนคืนจากกระเป๋า Binary แล้วบันทึก tx</span></h3>
        @if ($refunds->isEmpty())
            <p class="muted">ไม่มี</p>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>เวลา</th><th>ผู้โอน</th><th class="r">USDT</th><th>เหตุที่ไม่นับ</th><th>การคืนเงิน</th></tr></thead>
                <tbody>
                    @foreach ($refunds as $d)
                        <tr>
                            <td>{{ F::dateTime($d->block_time) }}<br><a class="mono muted" href="{{ Idtc::explorerTx($d->tx_hash) }}" target="_blank" rel="noopener">{{ substr($d->tx_hash, 0, 12) }}…</a></td>
                            <td><x-admin.addr :address="$d->wallet"/></td>
                            <td class="r">{{ $usd($d->usdt) }}</td>
                            <td>{{ BinaryDeposit::REASONS[$d->status] ?? $d->status }}</td>
                            <td>
                                @if ($d->refunded_at)
                                    <span class="pill-ok">คืนแล้ว</span> <a class="mono muted" href="{{ Idtc::explorerTx($d->refund_tx) }}" target="_blank" rel="noopener">{{ substr($d->refund_tx, 0, 12) }}…</a>
                                @elseif ($ro)
                                    <span class="pill-warn">ยังไม่คืน</span>
                                @else
                                    <form method="POST" action="{{ route('admin.binary.refund', $d) }}" class="actions" style="flex-wrap:nowrap" x-data="safeForm('บันทึกว่าคืนเงินรายการนี้แล้ว?', @js(['ผู้โอน '.F::addr($d->wallet).' · '.number_format($d->usdt, 2).' USDT', 'ใส่ tx ของการโอนคืนที่ทำจากกระเป๋า Binary แล้วเท่านั้น']))" @submit="submit($event)">
                                        @csrf
                                        <input class="input mono" name="tx_hash" placeholder="tx ของการโอนคืน 0x…" required style="min-width:220px" autocomplete="off" spellcheck="false">
                                        <button class="btn btn-ghost btn-sm" type="submit" :disabled="busy">บันทึกว่าคืนแล้ว</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <div class="panel section-gap">
        <h3>รายสัปดาห์ <span class="meta">ปฏิทินสัปดาห์เดียวกับ presale</span></h3>
        @if ($t['deposits'] === 0)
            <p class="muted">ยังไม่มียอดฝาก</p>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>สัปดาห์</th><th class="r">USDT ฝาก</th><th class="r">IDTC</th><th class="r">รายการ</th><th class="r">กองรางวัล</th><th class="r">จับคู่ตั้งต้น</th><th class="r">จ่าย</th><th class="r">ตัวคูณ</th><th>สถานะ</th></tr></thead>
                <tbody>
                    @foreach (array_reverse($book['weeks'], true) as $k => $w)
                        @continue($w['deposits'] === 0 && $w['given'] <= 0)
                        <tr>
                            <td>{{ $k + 1 }} <span class="muted">· {{ GridPlan::weekStart($k)->setTimezone('Asia/Bangkok')->format('j/n') }}</span></td>
                            <td class="r">{{ $usd($w['usdt']) }}</td>
                            <td class="r">{{ $big($w['idtc']) }}</td>
                            <td class="r">{{ $big($w['deposits']) }}</td>
                            <td class="r">{{ $usd($w['pool']) }}</td>
                            <td class="r">{{ $usd($w['nominal']) }}</td>
                            <td class="r">{{ $usd($w['given']) }}</td>
                            <td class="r">{{ $w['factor'] < 1 ? number_format($w['factor'], 3) : '—' }}</td>
                            <td><span class="{{ $w['state'] === 'closed' ? 'pill-ok' : 'pill-wait' }}">{{ $w['state'] === 'closed' ? 'ปิดแล้ว' : 'กำลังสะสม' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <p class="fine">ตัวคูณ &lt; 1 = ยอดจับคู่รวมเกินกอง ทุกคนถูกลดลงเท่ากัน · จ่าย USDT จริงที่หน้า "รอบจ่าย USDT" · ส่ง IDTC ที่ซื้อที่หน้า "รอบส่ง IDTC" (เจ้าของเซ็นประกาศรอบเอง)</p>
        @endif
    </div>

    <div class="panel section-gap">
        <h3>ยอดฝากล่าสุด <span class="meta">50 รายการ</span></h3>
        @if ($recent->isEmpty())
            <p class="muted">ยังไม่มี</p>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>เวลา</th><th>ผู้ฝาก</th><th class="r">USDT</th><th class="r">IDTC</th><th class="r">เข้ากอง (USDT)</th><th>สัปดาห์</th><th>tx</th></tr></thead>
                <tbody>
                    @foreach ($recent as $d)
                        <tr>
                            <td>{{ F::dateTime($d->block_time) }}</td>
                            <td><x-admin.addr :address="$d->wallet"/></td>
                            <td class="r">{{ $usd($d->usdt) }}</td>
                            <td class="r">{{ $big($d->idtc) }}</td>
                            <td class="r">{{ $usd($d->pool_usdt) }}</td>
                            <td>{{ $d->week + 1 }}</td>
                            <td><a class="mono muted" href="{{ Idtc::explorerTx($d->tx_hash) }}" target="_blank" rel="noopener">{{ substr($d->tx_hash, 0, 12) }}…</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
@endsection

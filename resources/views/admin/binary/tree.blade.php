@extends('layouts.admin')
@php
    $modes = $treeJs['modes'];
    $canEdit = $treeJs['canEdit'];
    $owner = $treeJs['owner'];
@endphp

@section('sub')
    ผังสองขาตัวเดียวกับที่ IDTC Grid ใช้ · ตัวเลขในผังเป็นยอดฝาก USDT ของแผน Binary · จัด/ย้าย/ต่อสาย มีผลตั้งแต่สัปดาห์ขายปัจจุบัน — สัปดาห์ที่ปิดแล้วคิดด้วยผังเดิมเสมอ
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.binary') }}">← แผน Binary</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.grid.map', ['tree' => 'binary']) }}"><x-admin.icon name="users"/>ผังวงกลมทั้งระบบ</a>
@endsection

@section('content')
<div x-data="binaryTree(@js($treeJs))" data-testid="admin-tree">
    <div class="kpis">
        <div class="kpi"><span>รอจัดสาย</span><b class="num" x-text="num(lists.queue.length)">{{ number_format($counts['waiting']) }}</b><em class="muted">คนที่แม่ทีมเลือก "รอให้ฉันจัดเอง"</em></div>
        <div class="kpi"><span>นอกผัง (ต่อสายได้)</span><b class="num" x-text="num(lists.outside.length)">—</b><em class="muted">บนสุดของผังแยก + กระเป๋าเดี่ยว</em></div>
        <div class="kpi"><span>ประวัติจัด/ย้าย</span><b class="num">{{ number_format($counts['changes']) }}</b><em class="muted">ทุกรายการมีผู้ทำและเหตุผล</em></div>
        <div class="kpi"><span>สิทธิ์ของคุณ</span><b>{{ $owner ? 'เจ้าของ' : ($canEdit ? 'จัด/ย้ายได้' : 'ดูอย่างเดียว') }}</b><em class="muted">{{ $owner ? 'เปลี่ยนผู้แนะนำได้' : 'เปลี่ยนผู้แนะนำ = เจ้าของเท่านั้น' }}</em></div>
    </div>

    <div class="grid-main">
        {{-- ผัง --}}
        <div class="panel">
            <h3>ผังสายงาน</h3>
            <div class="filters">
                <label class="field wide"><span>ไปที่กระเป๋า</span><input class="input mono" type="text" x-model="q" @keydown.enter.prevent="go()" placeholder="0x…" autocomplete="off" spellcheck="false"></label>
                <label class="field"><span>ความลึก</span>
                    <select class="select" x-model.number="depth" @change="load(win ? win.root : null)">
                        <option value="2">2 ชั้น</option>
                        <option value="3">3 ชั้น</option>
                        <option value="4">4 ชั้น</option>
                    </select>
                </label>
                <button class="btn btn-ghost btn-sm" type="button" @click="go()">ไปที่กระเป๋านี้</button>
                <button class="btn btn-ghost btn-sm" type="button" @click="load(null)">ผังหลัก</button>
            </div>

            <nav class="crumbs" aria-label="สายบน" x-show="win" x-cloak>
                <template x-for="c in (win ? win.crumbs : [])" :key="c.wallet">
                    <span><a class="mono" href="#" @click.prevent="load(c.wallet)" x-text="c.short"></a> ›</span>
                </template>
                <b class="mono" x-text="win ? short(win.root) : ''"></b>
            </nav>
            <div class="btree-nav section-gap" x-show="win" x-cloak>
                <button class="btn btn-ghost btn-sm" type="button" x-show="win && win.up" @click="load(win.up)" :disabled="loading">↑ ขึ้นหนึ่งชั้น</button>
                <button class="btn btn-ghost btn-sm" type="button" x-show="win && win.edge.L" @click="load(win.edge.L)" :disabled="loading">↙ ล่างสุดขาซ้าย</button>
                <button class="btn btn-ghost btn-sm" type="button" x-show="win && win.edge.R" @click="load(win.edge.R)" :disabled="loading">↘ ล่างสุดขาขวา</button>
                <button class="btn btn-ghost btn-sm" type="button" @click="reload()" :disabled="loading">โหลดใหม่</button>
            </div>

            <p class="err section-gap" x-show="err" x-cloak role="alert" x-text="err"></p>
            <p class="muted section-gap" x-show="loading && !win">กำลังโหลดผัง…</p>
            <p class="muted section-gap" x-show="!loading && !win && !err" x-cloak>ยังไม่มีผัง — ยังไม่มีใครถูกวางในผัง Binary (ต่อสายจากแท็บ "นอกผัง" ด้านล่างได้)</p>
            <p class="flash warn section-gap" x-show="arming" x-cloak>
                กำลังเลือกตำแหน่งให้ <b class="mono" x-text="short(mv.wallet)"></b> — แตะช่องว่าง (+) ในผัง
                <button class="btn btn-ghost btn-sm" type="button" @click="cancelMove()">ยกเลิก</button>
            </p>

            <div class="btree section-gap" :class="{ picking: arming }" :style="`--cols:${cols}`" x-show="win" x-cloak data-testid="admin-btree">
                <template x-for="(row, i) in levels" :key="i">
                    <div class="btree-row" :style="`--n:${row.length}`">
                        <template x-for="(c, j) in row" :key="i + ':' + j">
                            <div class="btree-cell" :class="{ none: !c }">
                                <template x-if="c && !c.empty">
                                    <button class="btree-node" type="button" @click="pick(c)" @dblclick="load(c.wallet)" :class="{ top: i === 0, off: !c.active, sel: sel && same(sel.wallet, c.wallet) }" :title="c.wallet">
                                        <b class="mono" x-text="c.short"></b>
                                        <span class="btree-tags">
                                            <em :class="c.active ? 'ok' : ''" x-text="c.active ? 'ฝากแล้ว' : 'ยังไม่ฝาก'"></em>
                                            <em class="ok" x-show="c.q">ผ่านเงื่อนไข</em>
                                            <em class="warn" x-show="c.waiting" x-text="'รอจัด ' + c.waiting"></em>
                                            <em class="bad" x-show="c.held">พัก</em>
                                        </span>
                                        <span class="btree-legs num"><span x-text="num(c.nl)"></span> · <span x-text="num(c.nr)"></span> คน</span>
                                    </button>
                                </template>
                                <template x-if="c && c.empty">
                                    <button class="btree-slot" type="button" :disabled="!cfg.canEdit || mv.busy" @click="slot(c)"
                                            :class="{ target: same(mv.parent, c.parent) && mv.leg === c.leg }"
                                            :aria-label="'ช่องว่าง ' + legText(c.leg) + ' ของ ' + short(c.parent)">
                                        <span x-text="arming ? '+' : 'ว่าง'"></span>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <p class="fine section-gap">คลิก = เลือกกระเป๋า · ดับเบิลคลิก = เริ่มผังจากกระเป๋านั้น · ตัวเลข = จำนวนคนในขาซ้าย · ขาขวา</p>
        </div>

        <div class="stack">
            {{-- กระเป๋าที่เลือก --}}
            <div class="panel" x-show="sel" x-cloak data-testid="tree-focus">
                <h3>กระเป๋าที่เลือก</h3>
                <template x-if="sel">
                    <div>
                        <div class="kv">
                            <div><span>กระเป๋า</span><b class="mono" x-text="sel.wallet"></b></div>
                            <div><span>ตำแหน่ง</span><b x-text="sel.parent ? legText(sel.leg) + 'ของ ' + short(sel.parent) : 'บนสุดของผัง'"></b></div>
                            <div><span>ผู้แนะนำ</span><b class="mono" x-text="sel.referrer ? short(sel.referrer) : 'ไม่มี'"></b></div>
                            <div><span>ฝากเอง</span><b x-text="usd(sel.usdt) + ' USDT'"></b></div>
                            <div><span>คนในขา ซ้าย / ขวา</span><b x-text="num(sel.nl) + ' / ' + num(sel.nr)"></b></div>
                            <div><span>ยอดขา ซ้าย / ขวา</span><b x-text="usd(sel.l) + ' / ' + usd(sel.r) + ' USDT'"></b></div>
                            <div><span>ยอดยกไป ซ้าย / ขวา</span><b x-text="usd(sel.cl) + ' / ' + usd(sel.cr) + ' USDT'"></b></div>
                            <div><span>เพื่อนตรงที่ฝาก ซ้าย / ขวา</span><b x-text="num(sel.dl) + ' / ' + num(sel.dr)"></b></div>
                            <div><span>รางวัลจับคู่</span><b :class="sel.q ? 'ok' : ''" x-text="sel.q ? 'ผ่านเงื่อนไขแล้ว' : 'ยังไม่ผ่านเงื่อนไข'"></b></div>
                            <div x-show="sel.waiting"><span>คนที่เขาชวน รอจัดสาย</span><b x-text="num(sel.waiting)"></b></div>
                            <div x-show="sel.held"><span>สถานะ</span><b class="bad">พักรางวัลไว้</b></div>
                        </div>
                        <div class="actions">
                            <button class="btn btn-ghost btn-sm" type="button" x-show="win && !same(sel.wallet, win.root)" @click="load(sel.wallet)">เริ่มผังจากที่นี่</button>
                            <a class="btn btn-ghost btn-sm" :href="memberUrl(sel.wallet)">ข้อมูลกระเป๋า</a>
                            @if ($canEdit)
                                <button class="btn btn-ghost btn-sm" type="button" @click="startMove(sel.wallet)" data-testid="tree-move">ย้ายกระเป๋านี้</button>
                            @endif
                        </div>

                        <label class="field section-gap"><span>วิธีวางคนใหม่ที่กระเป๋านี้ชวน</span>
                            <select class="select" :disabled="{{ $canEdit ? 'modeBusy' : 'true' }}" @change="setMode(sel.wallet, $event.target.value)">
                                @foreach ($modes as $k => $label)
                                    <option value="{{ $k }}" :selected="sel.mode === '{{ $k }}'">{{ $label }}</option>
                                @endforeach
                            </select>
                            <small>ลิงก์ที่ระบุขายังลงขานั้นเสมอ · เปลี่ยนแล้วไม่ย้ายคนที่วางไปแล้ว</small>
                        </label>

                        <div class="field section-gap"><span>ลิงก์ชวน Binary ของกระเป๋านี้</span>
                            <div class="actions">
                                <button class="btn btn-ghost btn-sm" type="button" @click="showQr(sel.wallet)" data-testid="tree-qr">QR ลิงก์หลัก</button>
                                <button class="btn btn-ghost btn-sm" type="button" @click="showQr(sel.wallet, 'l')">QR ขาซ้าย</button>
                                <button class="btn btn-ghost btn-sm" type="button" @click="showQr(sel.wallet, 'r')">QR ขาขวา</button>
                                <button class="btn btn-ghost btn-sm" type="button" @click="$store.toast.copy(refLink(sel.wallet))">คัดลอกลิงก์</button>
                            </div>
                        </div>

                        @if ($owner)
                            <div class="act section-gap" data-testid="tree-referrer">
                                <b>เปลี่ยนผู้แนะนำ <span class="muted">(เจ้าของเท่านั้น)</span></b>
                                <label class="field"><span>ผู้แนะนำใหม่</span>
                                    <input class="input mono" type="text" x-model="rf.referrer" @input.debounce.400ms="checkRef()" placeholder="0x…" autocomplete="off" spellcheck="false">
                                </label>
                                <p class="fine" x-show="rf.checking">กำลังตรวจ…</p>
                                <template x-if="rf.check">
                                    <div class="tcheck" :class="rf.check.ok ? 'ok' : 'bad'">
                                        <b x-text="rf.check.ok ? 'เปลี่ยนได้ — มีผลกับการซื้อหลังจากนี้' : rf.check.error"></b>
                                        <template x-for="w in rf.check.warnings" :key="w"><p class="warn" x-text="'⚠ ' + w"></p></template>
                                    </div>
                                </template>
                                <label class="field"><span>เหตุผล (8–300 ตัวอักษร)</span><textarea class="textarea" x-model="rf.reason" maxlength="300"></textarea></label>
                                <div class="actions">
                                    <button class="btn btn-danger btn-sm" type="button" :disabled="!rf.check || !rf.check.ok || rf.busy || rf.checking" @click="submitRef()">เปลี่ยนผู้แนะนำ…</button>
                                </div>
                                <p class="fine">รางวัลของการซื้อ/ฝากก่อนหน้านี้ยังเป็นของสายเดิม · ตำแหน่งในผังไม่ย้ายตาม (ย้ายแยกได้)</p>
                            </div>
                        @endif
                    </div>
                </template>
            </div>

            {{-- จัด / ย้าย / ต่อสาย --}}
            @if ($canEdit)
                <div class="panel" id="treeMove" data-testid="tree-move-panel">
                    <h3>จัดสาย · ย้ายสาย · ต่อสาย</h3>
                    <p class="fine">1) เลือกกระเป๋า  2) แตะช่องว่าง (+) ในผัง หรือพิมพ์ที่อยู่  3) อ่านผลตรวจ  4) เขียนเหตุผลแล้วยืนยัน — ย้ายแล้วทีมด้านล่างไปด้วยทั้งหมด</p>
                    <div class="form section-gap">
                        <label class="field"><span>กระเป๋าที่จะจัด/ย้าย</span>
                            <input class="input mono" type="text" x-model="mv.wallet" @input.debounce.400ms="check()" placeholder="0x…" autocomplete="off" spellcheck="false" :disabled="mv.busy">
                        </label>
                        <div class="form-row">
                            <label class="field"><span>วางใต้</span>
                                <input class="input mono" type="text" x-model="mv.parent" @input.debounce.400ms="check()" placeholder="0x…" autocomplete="off" spellcheck="false" :disabled="mv.busy">
                            </label>
                            <label class="field"><span>ขา</span>
                                <select class="select" x-model="mv.leg" @change="check()" :disabled="mv.busy">
                                    <option value="L">ขาซ้าย</option>
                                    <option value="R">ขาขวา</option>
                                </select>
                            </label>
                        </div>
                        <p class="fine" x-show="mv.checking">กำลังตรวจ…</p>
                        <template x-if="mv.check">
                            <div class="tcheck" :class="mv.check.ok ? 'ok' : 'bad'" data-testid="tree-check">
                                <b x-text="mv.check.ok ? (mv.check.info.kind === 'move' ? 'ย้ายได้' : 'จัด/ต่อสายได้') : mv.check.error"></b>
                                <template x-if="mv.check.ok">
                                    <ul><template x-for="l in moveLines(mv.check.info, mv.reason.trim() || '—')" :key="l"><li x-text="l"></li></template></ul>
                                </template>
                                <template x-for="w in mv.check.warnings" :key="w"><p class="warn" x-text="'⚠ ' + w"></p></template>
                            </div>
                        </template>
                        <label class="field"><span>เหตุผล (4–300 ตัวอักษร · ขึ้นในประวัติ)</span><textarea class="textarea" x-model="mv.reason" maxlength="300" :disabled="mv.busy"></textarea></label>
                        <div class="actions">
                            <button class="btn btn-primary btn-sm" type="button" :disabled="!mv.check || !mv.check.ok || mv.busy || mv.checking || mv.reason.trim().length < 4" @click="submitMove()">บันทึก…</button>
                            <button class="btn btn-ghost btn-sm" type="button" @click="cancelMove()" :disabled="mv.busy">ล้าง</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- คิว · นอกผัง · ประวัติ --}}
    <div class="panel section-gap">
        <div class="filters">
            <div class="seg" role="tablist">
                <button type="button" role="tab" :class="{ on: tab === 'queue' }" :aria-selected="(tab === 'queue').toString()" @click="tab = 'queue'">คิวรอจัดสาย <span x-text="'(' + lists.queue.length + ')'"></span></button>
                <button type="button" role="tab" :class="{ on: tab === 'outside' }" :aria-selected="(tab === 'outside').toString()" @click="tab = 'outside'">นอกผัง <span x-text="'(' + lists.outside.length + ')'"></span></button>
                <button type="button" role="tab" :class="{ on: tab === 'history' }" :aria-selected="(tab === 'history').toString()" @click="tab = 'history'">ประวัติ</button>
            </div>
            <label class="field wide"><span>กรอง</span><input class="input" type="search" x-model="filter" placeholder="ที่อยู่กระเป๋า / เหตุผล" autocomplete="off"></label>
            @if ($canEdit)
                <label class="field" x-show="tab === 'queue'"><span>วิธีวางของปุ่ม "ให้ระบบจัด"</span>
                    <select class="select" x-model="autoMode">
                        @foreach ($modes as $k => $label)
                            @continue($k === 'manual')
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <button class="btn btn-ghost btn-sm" type="button" @click="loadLists()" :disabled="listsBusy">โหลดใหม่</button>
        </div>
        <p class="err" x-show="listsErr" x-cloak role="alert" x-text="listsErr"></p>

        {{-- คิวรอจัดสาย --}}
        <div x-show="tab === 'queue'" data-testid="tree-queue">
            <p class="fine">จัดก่อนสัปดาห์ขายปิด = ยอดฝากของสัปดาห์นั้นนับให้สายบนครบ · คนที่รอข้ามสัปดาห์ ยอดของสัปดาห์ที่ปิดไปแล้วไม่นับให้สายบน</p>
            <p class="muted section-gap" x-show="!lists.queue.length">ไม่มีใครรอจัดสาย</p>
            <div class="table-scroll section-gap" x-show="lists.queue.length">
                <table class="tbl">
                    <thead><tr><th>กระเป๋า</th><th>ผู้ชวน</th><th>รอตั้งแต่</th><th>มาจากลิงก์</th><th class="r">ฝากแล้ว (USDT)</th><th>จัดก่อน</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="q in filtered(lists.queue)" :key="q.wallet">
                            <tr>
                                <td><a class="mono" :href="memberUrl(q.wallet)" :title="q.wallet" x-text="q.short"></a></td>
                                <td><a class="mono" href="#" @click.prevent="load(q.referrer)" :title="q.referrer" x-text="q.referrer_short || '—'"></a></td>
                                <td x-text="when(q.since)"></td>
                                <td x-text="q.side === 'L' ? 'ขาซ้าย' : q.side === 'R' ? 'ขาขวา' : 'ลิงก์หลัก'"></td>
                                <td class="r" x-text="usd(q.usdt)"></td>
                                <td x-text="when(q.week_ends)"></td>
                                <td class="r">
                                    @if ($canEdit)
                                        <button class="btn btn-ghost btn-sm" type="button" @click="startMove(q.wallet, q.referrer)">เลือกช่อง</button>
                                        <button class="btn btn-ghost btn-sm" type="button" @click="autoPlace(q)" :disabled="!!autoBusy" x-text="autoBusy === q.wallet ? 'กำลังจัด…' : 'ให้ระบบจัด'">ให้ระบบจัด</button>
                                    @endif
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- นอกผัง --}}
        <div x-show="tab === 'outside'" x-cloak data-testid="tree-outside">
            <p class="fine">บนสุดของผังแยก (มีทีมของตัวเอง) และกระเป๋าที่ยังไม่มีตำแหน่ง — "ต่อสาย" = วางลงใต้กระเป๋าอื่น ทีมด้านล่างไปด้วยทั้งหมด</p>
            <p class="muted section-gap" x-show="!lists.outside.length">ไม่มีกระเป๋านอกผัง</p>
            <div class="table-scroll section-gap" x-show="lists.outside.length">
                <table class="tbl">
                    <thead><tr><th>กระเป๋า</th><th class="r">ทีม (คน)</th><th class="r">ฝากแล้ว (USDT)</th><th>ผู้แนะนำ</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="o in filtered(lists.outside)" :key="o.wallet">
                            <tr>
                                <td><a class="mono" :href="memberUrl(o.wallet)" :title="o.wallet" x-text="o.short"></a></td>
                                <td class="r" x-text="num(o.team)"></td>
                                <td class="r" x-text="usd(o.usdt)"></td>
                                <td class="mono" x-text="o.referrer ? short(o.referrer) : '—'"></td>
                                <td class="r">
                                    <button class="btn btn-ghost btn-sm" type="button" x-show="o.team > 1" @click="load(o.wallet)">ดูผัง</button>
                                    @if ($canEdit)
                                        <button class="btn btn-ghost btn-sm" type="button" @click="startMove(o.wallet, o.referrer)">ต่อสาย</button>
                                    @endif
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ประวัติ --}}
        <div x-show="tab === 'history'" x-cloak data-testid="tree-history">
            <p class="fine">ทุกการจัด/ย้าย/เปลี่ยนผู้แนะนำ มีผลตั้งแต่สัปดาห์ขายที่ระบุ — สัปดาห์ก่อนหน้าคิดด้วยผังเดิม (100 รายการล่าสุด)</p>
            <p class="muted section-gap" x-show="!lists.history.length">ยังไม่มีประวัติ</p>
            <div class="table-scroll section-gap" x-show="lists.history.length">
                <table class="tbl">
                    <thead><tr><th>เวลา</th><th>สัปดาห์</th><th>รายการ</th><th>กระเป๋า</th><th>จาก</th><th>ไป</th><th class="r">ทีม</th><th>ผู้ทำ</th><th>เหตุผล</th></tr></thead>
                    <tbody>
                        <template x-for="h in filtered(lists.history)" :key="h.id">
                            <tr>
                                <td x-text="when(h.at)"></td>
                                <td x-text="h.week"></td>
                                <td x-text="kindText(h)"></td>
                                <td><a class="mono" href="#" @click.prevent="load(h.wallet)" :title="h.wallet" x-text="h.short"></a></td>
                                <td class="mono" x-text="h.kind === 'referrer' ? (h.from_referrer ? short(h.from_referrer) : 'ไม่มี') : (h.from_parent ? legText(h.from_leg) + ' ' + short(h.from_parent) : 'นอกผัง')"></td>
                                <td class="mono" x-text="h.kind === 'referrer' ? short(h.to_referrer) : legText(h.to_leg) + ' ' + short(h.to_parent)"></td>
                                <td class="r" x-text="h.kind === 'referrer' ? '—' : num(h.team)"></td>
                                <td><span class="mono" :title="h.actor" x-text="h.actor ? short(h.actor) : '—'"></span> <span class="muted" x-text="roleText(h.role)"></span></td>
                                <td class="cellwrap" x-text="h.reason || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

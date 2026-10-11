@extends('layouts.admin')
@php
    use App\Support\AdminFormat as F;
    $tabs = ['' => 'ทั้งหมด', 'chain' => 'คำสั่งบนเชน', 'settings' => 'ค่าตั้ง/เนื้อหา', 'admins' => 'ผู้ดูแล', 'auth' => 'เข้า/ออกระบบ'];
@endphp

@section('sub')
    ทุกการเปลี่ยนแปลงในหลังบ้าน · ธุรกรรมบนเชนตรวจผลจากเชนเอง (รอยืนยัน → สำเร็จ/ล้มเหลว)
@endsection

@section('content')
    <div class="panel">
        <div class="seg" role="tablist" aria-label="ประเภท" style="margin-bottom:14px;flex-wrap:wrap">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('admin.audit', array_filter(['type' => $key])) }}" @class(['on' => $type === $key]) role="tab" aria-selected="{{ $type === $key ? 'true' : 'false' }}">{{ $label }}</a>
            @endforeach
        </div>

        @if ($logs->isEmpty())
            <div class="empty"><x-admin.icon name="log"/><b>ยังไม่มีบันทึก</b><span>การเข้าระบบ การแก้ค่าตั้ง และคำสั่งบนเชนจะถูกบันทึกที่นี่</span></div>
        @else
            <div class="table-scroll"><table class="tbl">
                <thead><tr><th>เวลา (ไทย)</th><th>ใคร</th><th>ทำอะไร</th><th>รายละเอียด</th><th>ธุรกรรม</th><th>IP</th></tr></thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td class="mono" style="vertical-align:top">{{ F::dateTime($log->created_at) }}</td>
                            <td style="vertical-align:top">
                                @if ($log->actor)<x-admin.addr :address="$log->actor"/>@endif
                                <div class="muted">{{ $log->actorLabel() }}</div>
                            </td>
                            <td class="cellwrap" style="vertical-align:top">{{ $log->label() }}</td>
                            <td class="cellwrap" style="vertical-align:top;min-width:260px">
                                @foreach (F::auditLines($log) as [$k, $val])
                                    <div style="font-size:13px"><span class="muted">{{ $k }}:</span>
                                        @if (preg_match('/^0x[0-9a-fA-F]{40}$/', $val))
                                            <x-admin.addr :address="$val"/>
                                        @else
                                            <span style="overflow-wrap:anywhere">{{ $val }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                            <td style="vertical-align:top">
                                @if ($log->tx_hash)
                                    <x-admin.tx-status :status="$log->tx_status"/>
                                    <div style="margin-top:4px"><x-admin.addr :address="$log->tx_hash" tx/></div>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="mono muted" style="vertical-align:top">{{ $log->ip }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            {{ $logs->links('admin.partials.pager') }}
        @endif
    </div>
@endsection

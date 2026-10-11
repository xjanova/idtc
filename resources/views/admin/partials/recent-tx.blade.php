@php use App\Support\AdminFormat as F; @endphp
<div class="panel">
    <h3>คำสั่งบนเชนล่าสุด <a class="meta" href="{{ route('admin.audit', ['type' => 'chain']) }}">ทั้งหมด →</a></h3>
    @if ($txs->isEmpty())
        <p class="fine">ยังไม่มีคำสั่งที่ส่งจากหลังบ้าน</p>
    @else
        <div class="table-scroll"><table class="tbl">
            <thead><tr><th>เวลา</th><th>คำสั่ง</th><th>สถานะ</th><th>ธุรกรรม</th></tr></thead>
            <tbody>
                @foreach ($txs as $log)
                    <tr>
                        <td class="mono">{{ F::dateTime($log->created_at) }}</td>
                        <td>{{ $log->label() }}</td>
                        <td><x-admin.tx-status :status="$log->tx_status"/></td>
                        <td><x-admin.addr :address="$log->tx_hash" tx/></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
</div>

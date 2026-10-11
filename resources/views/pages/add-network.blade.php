@extends('layouts.site')

@section('content')
    <div class="page-head">
        <div class="wrap">
            <p class="kicker">เพิ่มเชน</p>
            <h1>เพิ่มเชน TPIX และเหรียญ IDTC <span class="grad">ลงกระเป๋าของคุณ</span></h1>
            <p class="lead">ถ้าเปิดหน้านี้ในแอปกระเป๋าอยู่แล้ว ระบบจะถามให้เพิ่มเชนทันที ถ้ายังไม่ขึ้น ให้กดปุ่มเพิ่มเชนด้านล่าง</p>
        </div>
    </div>
    <div class="wrap" style="padding-block: 8px 80px">
        @include('partials.add-network')
    </div>
@endsection

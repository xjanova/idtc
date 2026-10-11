@php
    use App\Support\Idtc;
    $adminJs = [
        'csrf' => csrf_token(),
        'routes' => ['nonce' => route('admin.auth.nonce'), 'verify' => route('admin.auth.verify')],
    ];
@endphp
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#050a09">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · หลังบ้าน IDTC</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700&family=Noto+Sans+Thai:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap">
    <script>window.IDTC = @json(Idtc::publicConfig()); window.IDTC_ADMIN = @json($adminJs);</script>
    @vite(['resources/css/app.css', 'resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="adm login">
    <main class="login-card" x-data="adminLogin">
        <a class="brand" href="{{ route('home') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Admin</small></span></a>
        <div>
            <h1>เข้าสู่หลังบ้าน</h1>
            <p class="lead" style="margin-top:8px">เข้าด้วยการเซ็นข้อความในกระเป๋าที่มีสิทธิ์ ไม่มีรหัสผ่าน ไม่มีค่าธรรมเนียม และไม่มีการโอนเงิน</p>
        </div>

        @if (session('status'))
            <div class="flash ok" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err" role="alert">{{ session('error') }}</div>
        @endif
        @unless ($bootstrapped)
            <div class="flash warn" role="note">ยังไม่ได้ตั้งกระเป๋าเจ้าของ — ใส่ที่อยู่กระเป๋าใน <code>IDTC_ADMIN_WALLETS</code> ของไฟล์ .env บนเซิร์ฟเวอร์ (ตัวแรกคือเจ้าของ) แล้วกลับมาที่หน้านี้</div>
        @endunless

        <ol class="steps" aria-label="ขั้นตอน">
            <li :class="stepClass(1)"><i>1</i>เชื่อมกระเป๋า</li>
            <li :class="stepClass(2)"><i>2</i>เซ็นข้อความ "เข้าสู่หลังบ้าน IDTC"</li>
            <li :class="stepClass(3)"><i>3</i>ระบบตรวจลายเซ็นและสิทธิ์</li>
        </ol>

        <button class="btn btn-primary btn-block" type="button" @click="login()" :disabled="busy">
            <span x-show="busy" class="spin" aria-hidden="true"></span>
            <span x-text="busy ? 'กำลังดำเนินการ…' : (account ? 'เซ็นเพื่อเข้าสู่ระบบ' : 'เชื่อมกระเป๋าและเข้าสู่ระบบ')">เชื่อมกระเป๋าและเข้าสู่ระบบ</span>
        </button>
        <p class="toast" :class="kind" x-text="msg" role="status" aria-live="polite" style="margin:0;text-align:center"></p>
        <p x-show="account" x-cloak class="hint" style="text-align:center;font-size:12.5px;color:var(--fg-3)">กระเป๋าที่เชื่อม: <span class="mono" x-text="account"></span></p>

        <p style="text-align:center;font-size:12.5px;color:var(--fg-3);margin:0">
            <x-admin.icon name="shield" style="width:14px;height:14px;stroke:var(--gold);fill:none;stroke-width:2.2;display:inline;vertical-align:-2px;margin-right:4px"/>ตรวจว่าข้อความในกระเป๋าขึ้นต้นด้วย <b class="mono" style="color:var(--fg);white-space:nowrap">{{ request()->getHttpHost() }}</b> ก่อนกดเซ็น
        </p>
    </main>
</body>
</html>

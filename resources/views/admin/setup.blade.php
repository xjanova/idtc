@php
    use App\Services\Admin\AdminPasswords as P;
    use App\Support\Idtc;
    $adminJs = [
        'csrf' => csrf_token(),
        'routes' => ['nonce' => route('admin.auth.nonce'), 'setup' => route('admin.setup.store')],
        'password' => ['min' => P::MIN_LENGTH, 'maxBytes' => P::MAX_BYTES],
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
    <main class="login-card" x-data="adminSetup">
        <a class="brand" href="{{ route('home') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Admin</small></span></a>
        <div>
            <h1>ตั้งค่าหลังบ้านครั้งแรก</h1>
            <p class="lead" style="margin-top:8px">ยังไม่มีผู้ดูแลเลย — กระเป๋าที่ตั้งค่าสำเร็จเป็นคนแรกจะเป็น <b>เจ้าของ (Owner)</b> ของหลังบ้านนี้</p>
        </div>

        <div class="flash warn" role="note">ใครเปิดหน้านี้ก่อนก็ตั้งตัวเองเป็นเจ้าของได้ — ทำทันทีหลังขึ้นเว็บ · ตั้งเสร็จแล้วหน้านี้จะหายไป (เพิ่มผู้ดูแลคนอื่นได้จากหน้า "ผู้ดูแล")</div>

        <ol class="steps" aria-label="ขั้นตอน">
            <li :class="stepClass(1)"><i>1</i>ตั้งรหัสผ่านหลังบ้าน</li>
            <li :class="stepClass(2)"><i>2</i>เชื่อมกระเป๋าที่จะเป็นเจ้าของ</li>
            <li :class="stepClass(3)"><i>3</i>เซ็นข้อความ "เข้าสู่หลังบ้าน IDTC"</li>
            <li :class="stepClass(4)"><i>4</i>บันทึกเป็นเจ้าของและเข้าสู่ระบบ</li>
        </ol>

        <form class="form" style="display:grid;gap:12px" @submit.prevent="run()">
            <label class="field"><span>รหัสผ่านหลังบ้าน</span>
                <input class="input" type="password" x-model="pw" autocomplete="new-password" minlength="{{ P::MIN_LENGTH }}" required>
            </label>
            <label class="field"><span>ยืนยันรหัสผ่าน</span>
                <input class="input" type="password" x-model="pw2" autocomplete="new-password" minlength="{{ P::MIN_LENGTH }}" required>
            </label>
            <p class="hint" style="margin:0">อย่างน้อย {{ P::MIN_LENGTH }} ตัวอักษร ไม่เกิน 72 ไบต์ (อักษรไทยราว 24 ตัว) · ต้องใส่ทุกครั้งหลังเซ็นกระเป๋า</p>
            <label style="display:flex;gap:10px;align-items:flex-start;font-size:14px;cursor:pointer">
                <input type="checkbox" x-model="ack" style="width:18px;height:18px;margin-top:3px;accent-color:var(--lime);flex:none">
                <span>เข้าใจแล้วว่ากระเป๋าที่เซ็นจะเป็นเจ้าของหลังบ้าน IDTC และรหัสผ่านนี้กู้คืนไม่ได้ (รีเซ็ตได้จากเซิร์ฟเวอร์เท่านั้น)</span>
            </label>
            <button class="btn btn-primary btn-block" type="submit" :disabled="busy || !ack">
                <span x-show="busy" class="spin" aria-hidden="true"></span>
                <span x-text="busy ? 'กำลังดำเนินการ…' : 'เชื่อมกระเป๋า เซ็น และตั้งเป็นเจ้าของ'">เชื่อมกระเป๋า เซ็น และตั้งเป็นเจ้าของ</span>
            </button>
        </form>

        <p class="toast" :class="kind" x-text="msg" role="status" aria-live="polite" style="margin:0;text-align:center"></p>
        <p x-show="account" x-cloak class="hint" style="text-align:center;font-size:12.5px;color:var(--fg-3)">กระเป๋าที่เชื่อม: <span class="mono" x-text="account"></span></p>

        <p style="text-align:center;font-size:12.5px;color:var(--fg-3);margin:0">
            <x-admin.icon name="shield" style="width:14px;height:14px;stroke:var(--gold);fill:none;stroke-width:2.2;display:inline;vertical-align:-2px;margin-right:4px"/>ตรวจว่าข้อความในกระเป๋าขึ้นต้นด้วย <b class="mono" style="color:var(--fg);white-space:nowrap">{{ request()->getHttpHost() }}</b> ก่อนกดเซ็น
        </p>
    </main>
</body>
</html>

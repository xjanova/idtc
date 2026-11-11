@php
    use App\Services\Admin\AdminPasswords as P;
    use App\Support\Idtc;
    $adminJs = [
        'csrf' => csrf_token(),
        'routes' => ['nonce' => route('admin.auth.nonce'), 'verify' => route('admin.auth.verify'), 'password' => route('admin.auth.password')],
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
    <main class="login-card" x-data="adminLogin">
        <a class="brand" href="{{ route('home') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Admin</small></span></a>
        <div>
            <h1>เข้าสู่หลังบ้าน</h1>
            <p class="lead" style="margin-top:8px">เซ็นข้อความในกระเป๋าที่มีสิทธิ์ แล้วใส่รหัสผ่านหลังบ้าน — ไม่มีค่าธรรมเนียม และไม่มีการโอนเงิน</p>
        </div>

        @if (session('status'))
            <div class="flash ok" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="flash err" role="alert">{{ session('error') }}</div>
        @endif

        <ol class="steps" aria-label="ขั้นตอน">
            <li :class="stepClass(1)"><i>1</i>เชื่อมกระเป๋า</li>
            <li :class="stepClass(2)"><i>2</i>เซ็นข้อความ "เข้าสู่หลังบ้าน IDTC"</li>
            <li :class="stepClass(3)"><i>3</i>ระบบตรวจลายเซ็นและสิทธิ์</li>
            <li :class="stepClass(4)"><i>4</i><span x-text="need === 'set_password' ? 'ตั้งรหัสผ่านหลังบ้าน' : 'ใส่รหัสผ่านหลังบ้าน (ถ้าตั้งไว้)'">ใส่รหัสผ่านหลังบ้าน (ถ้าตั้งไว้)</span></li>
        </ol>

        <template x-if="!need">
            <button class="btn btn-primary btn-block" type="button" @click="login()" :disabled="busy">
                <span x-show="busy" class="spin" aria-hidden="true"></span>
                <span x-text="busy ? 'กำลังดำเนินการ…' : (account ? 'เซ็นเพื่อเข้าสู่ระบบ' : 'เชื่อมกระเป๋าและเข้าสู่ระบบ')">เชื่อมกระเป๋าและเข้าสู่ระบบ</span>
            </button>
        </template>

        <form x-show="need" x-cloak class="form" style="display:grid;gap:12px" @submit.prevent="sendPassword()" autocomplete="on">
            <input type="text" name="username" :value="account" autocomplete="username" hidden readonly>
            <label class="field"><span x-text="need === 'set_password' ? 'รหัสผ่านใหม่' : 'รหัสผ่านหลังบ้าน'">รหัสผ่านหลังบ้าน</span>
                <input class="input" type="password" x-ref="pw" x-model="pw" :autocomplete="need === 'set_password' ? 'new-password' : 'current-password'" required>
            </label>
            <label class="field" x-show="need === 'set_password'"><span>ยืนยันรหัสผ่านใหม่</span>
                <input class="input" type="password" x-model="pw2" autocomplete="new-password" :required="need === 'set_password'">
            </label>
            <p class="hint" x-show="need === 'set_password'" style="margin:0">อย่างน้อย {{ P::MIN_LENGTH }} ตัวอักษร ไม่เกิน 72 ไบต์ (อักษรไทยราว 24 ตัว) · ต้องใส่ทุกครั้งที่เข้าระบบ เก็บไว้ในที่ปลอดภัย</p>
            <button class="btn btn-primary btn-block" type="submit" :disabled="busy || locked > 0">
                <span x-show="busy" class="spin" aria-hidden="true"></span>
                <span x-text="busy ? 'กำลังตรวจ…' : (need === 'set_password' ? 'ตั้งรหัสผ่านและเข้าสู่ระบบ' : 'เข้าสู่ระบบ')">เข้าสู่ระบบ</span>
            </button>
            <button class="btn btn-ghost btn-sm" type="button" @click="restart()" :disabled="busy">เริ่มใหม่ (เซ็นอีกครั้ง)</button>
        </form>

        <p class="toast" :class="kind" x-text="msg" role="status" aria-live="polite" style="margin:0;text-align:center"></p>
        <p x-show="account" x-cloak class="hint" style="text-align:center;font-size:12.5px;color:var(--fg-3)">กระเป๋าที่เชื่อม: <span class="mono" x-text="account"></span></p>

        <p style="text-align:center;font-size:12.5px;color:var(--fg-3);margin:0">
            <x-admin.icon name="shield" style="width:14px;height:14px;stroke:var(--gold);fill:none;stroke-width:2.2;display:inline;vertical-align:-2px;margin-right:4px"/>ตรวจว่าข้อความในกระเป๋าขึ้นต้นด้วย <b class="mono" style="color:var(--fg);white-space:nowrap">{{ request()->getHttpHost() }}</b> ก่อนกดเซ็น
        </p>
    </main>
</body>
</html>

@php
    use App\Support\Idtc;
    $nav = [
        ['route' => 'admin.dashboard', 'label' => 'ภาพรวม', 'icon' => 'grid'],
        ['route' => 'admin.sales', 'label' => 'ยอดขาย', 'icon' => 'chart'],
        'ควบคุมเหรียญ',
        ['route' => 'admin.presale', 'label' => 'Presale', 'icon' => 'bolt'],
        ['route' => 'admin.vesting', 'label' => 'สัญญาล็อก', 'icon' => 'lock'],
        ['route' => 'admin.pools', 'label' => 'พูลสภาพคล่อง', 'icon' => 'pool'],
        ['route' => 'admin.wallets', 'label' => 'กระเป๋าโครงการ', 'icon' => 'wallet'],
        'เว็บไซต์',
        ['route' => 'admin.content', 'label' => 'เนื้อหาและประกาศ', 'icon' => 'layout'],
        ['route' => 'admin.settings', 'label' => 'ตั้งค่าเหรียญ', 'icon' => 'gear'],
        ['route' => 'admin.admins', 'label' => 'ผู้ดูแล', 'icon' => 'users', 'owner' => true],
        ['route' => 'admin.audit', 'label' => 'บันทึกการทำงาน', 'icon' => 'log'],
    ];
    $adminJs = [
        'csrf' => csrf_token(),
        'me' => ['address' => $me->checksum(), 'role' => $me->role, 'canWrite' => $me->canWrite()],
        'routes' => [
            'tx' => route('admin.tx.store'),
            'txCheck' => url('/admin/tx/__ID__/check'),
        ],
        'head' => $head['block'] ?? null,
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
    <title>{{ ($title ?? 'หลังบ้าน') }} · หลังบ้าน IDTC</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/idtc-192.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700&family=Noto+Sans+Thai:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap">
    <script>window.IDTC = @json(Idtc::publicConfig()); window.IDTC_ADMIN = @json($adminJs);</script>
    @vite(['resources/css/app.css', 'resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="adm" x-data="adminShell" @keydown.escape.window="nav = false">
    <header class="mbar">
        <a class="brand" href="{{ route('admin.dashboard') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="30" height="30"><span>IDTC<small>Admin</small></span></a>
        <button class="btn btn-ghost btn-sm" type="button" @click="nav = true" :aria-expanded="nav.toString()" aria-controls="adminSide" aria-label="เปิดเมนู"><x-admin.icon name="menu"/>เมนู</button>
    </header>

    <div class="shell">
        <aside class="side" id="adminSide" :class="{ open: nav }" aria-label="เมนูหลังบ้าน">
            <a class="brand" href="{{ route('admin.dashboard') }}"><img src="{{ asset('images/brand/idtc-64.png') }}" alt="" width="36" height="36"><span>IDTC<small>Admin</small></span></a>
            <button class="side-close" type="button" @click="nav = false" aria-label="ปิดเมนู"><x-admin.icon name="close"/></button>
            <nav>
                @foreach ($nav as $item)
                    @if (is_string($item))
                        <p class="grp">{{ $item }}</p>
                    @elseif (empty($item['owner']) || $me->isOwner())
                        <a href="{{ route($item['route']) }}" @class(['on' => request()->routeIs($item['route'])]) @if (request()->routeIs($item['route'])) aria-current="page" @endif><x-admin.icon :name="$item['icon']"/>{{ $item['label'] }}</a>
                    @endif
                @endforeach
            </nav>
            <a class="site-link" href="{{ route('home') }}" target="_blank" rel="noopener">← ดูหน้าเว็บสาธารณะ</a>
            <div class="who">
                <span style="color:var(--fg-3)">เข้าระบบด้วยกระเป๋า</span>
                <span class="mono" style="color:var(--fg)" title="{{ $me->checksum() }}">{{ Idtc::short($me->checksum()) }}</span>
                <span @class(['role', 'viewer' => ! $me->canWrite()])>● {{ $me->roleLabel() }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm btn-block" type="submit"><x-admin.icon name="logout"/>ออกจากระบบ</button>
                </form>
            </div>
        </aside>
        <div class="scrim" x-show="nav" x-cloak @click="nav = false"></div>

        <main class="main" id="main">
            <div class="topbar">
                <div>
                    <h1>{{ $title ?? 'หลังบ้าน' }}</h1>
                    @hasSection('sub')<p class="sub">@yield('sub')</p>@endif
                </div>
                <div class="right">
                    @yield('actions')
                    @isset($head)
                        <span @class(['net', 'bad' => ! $head['ok']]) title="{{ $head['error'] ?? 'เชื่อมต่อเชนได้' }}">
                            <i></i>
                            @if ($head['ok'])
                                TPIX Chain · บล็อก <span class="mono" x-text="blockText">{{ number_format($head['block']) }}</span>
                            @else
                                เชื่อมต่อเชนไม่ได้
                            @endif
                        </span>
                    @endisset
                    <span class="wallet-pill" x-cloak>
                        <template x-if="account">
                            <span :title="account"><span x-text="short(account)"></span> <span x-show="wrongChain" style="color:var(--gold)">· เชนไม่ใช่ TPIX</span></span>
                        </template>
                        <template x-if="!account"><span>ยังไม่เชื่อมกระเป๋า</span></template>
                        <button class="btn btn-ghost btn-sm" type="button" @click="connect()" x-text="account ? (wrongChain ? 'สลับเชน' : 'เปลี่ยน') : 'เชื่อม'">เชื่อม</button>
                    </span>
                </div>
            </div>

            @if (session('status'))
                <div class="flash ok" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="flash err" role="alert">{{ session('error') }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    {{-- กล่องยืนยันกลาง: ทวนผลของคำสั่งและที่อยู่ปลายทางก่อนเปิดกระเป๋าเซ็น --}}
    <div class="modal" x-show="$store.confirm.open" x-cloak @keydown.escape.window="$store.confirm.open && $store.confirm.answer(false)" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="modal-card" :class="{ danger: $store.confirm.danger }" @click.outside="$store.confirm.answer(false)">
            <h2 id="confirmTitle" x-text="$store.confirm.title"></h2>
            <ul><template x-for="line in $store.confirm.lines" :key="line"><li x-text="line"></li></template></ul>
            <template x-if="$store.confirm.target">
                <div class="target"><span x-text="$store.confirm.targetLabel"></span><code x-text="$store.confirm.target"></code></div>
            </template>
            <template x-for="w in $store.confirm.warnings" :key="w"><p class="warn" x-text="'⚠ ' + w"></p></template>
            <label class="ack" x-show="$store.confirm.needAck">
                <input type="checkbox" x-model="$store.confirm.ack">
                <span x-text="$store.confirm.ackText"></span>
            </label>
            <div class="actions">
                <button class="btn btn-ghost" type="button" x-ref="cancelBtn" @click="$store.confirm.answer(false)">ยกเลิก</button>
                <button class="btn" :class="$store.confirm.danger ? 'btn-danger' : 'btn-primary'" type="button" :disabled="$store.confirm.needAck && !$store.confirm.ack" @click="$store.confirm.answer(true)" x-text="$store.confirm.okText"></button>
            </div>
        </div>
    </div>

    <div class="toasts" aria-live="polite">
        <template x-for="t in $store.toast.items" :key="t.id"><div class="toast-item" :class="t.kind" x-text="t.text"></div></template>
    </div>
</body>
</html>

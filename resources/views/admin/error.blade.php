<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · หลังบ้าน IDTC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anuphan:wght@600;700&family=Noto+Sans+Thai:wght@400;500&display=swap">
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: #050a09; color: #eaf4ef; font-family: "Noto Sans Thai", system-ui, sans-serif; }
        main { width: min(460px, 100%); border-radius: 24px; border: 1px solid rgba(220, 255, 240, .16); background: #08100e; padding: 30px; text-align: center; display: grid; gap: 14px; }
        b.code { font-family: "Anuphan", sans-serif; font-size: 44px; color: #a3e635; line-height: 1; }
        h1 { font-family: "Anuphan", sans-serif; font-size: 22px; margin: 0; color: #fff; }
        p { margin: 0; color: #b0c3bb; font-size: 15px; line-height: 1.7; }
        a { display: inline-flex; justify-content: center; align-items: center; min-height: 44px; padding: 0 20px; border-radius: 999px; background: linear-gradient(95deg, #a3e635, #10b981); color: #03130c; font-family: "Anuphan", sans-serif; font-weight: 700; text-decoration: none; justify-self: center; }
    </style>
</head>
<body>
    <main>
        <b class="code">{{ $status }}</b>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <a href="{{ url('/admin') }}">กลับหน้าหลังบ้าน</a>
    </main>
</body>
</html>

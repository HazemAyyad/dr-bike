<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>دخول مركز الأمان | Doctor Bike</title>
    <style>
        *{box-sizing:border-box} body{margin:0;min-height:100vh;display:grid;place-items:center;background:#07111f;color:#e8eef7;font-family:Tahoma,Arial,sans-serif;padding:20px}
        .card{width:min(440px,100%);background:#101d2e;border:1px solid #24364d;border-radius:22px;padding:30px;box-shadow:0 22px 70px #0008}
        .logo{width:58px;height:58px;border-radius:17px;display:grid;place-items:center;background:#143c35;color:#55e0ad;font-size:28px;margin-bottom:18px}
        h1{margin:0 0 8px;font-size:25px}.hint{margin:0 0 24px;color:#9eb0c6;line-height:1.7}
        label{display:block;margin-bottom:8px;font-weight:bold}.input{width:100%;background:#091422;border:1px solid #30455f;color:white;border-radius:12px;padding:13px 14px;font-size:16px;outline:none}.input:focus{border-color:#39c995}
        button{width:100%;margin-top:16px;padding:13px;border:0;border-radius:12px;background:#36c892;color:#052016;font-size:16px;font-weight:bold;cursor:pointer}
        .error{background:#3b1720;border:1px solid #793242;color:#ffbac6;padding:11px 13px;border-radius:10px;margin-bottom:16px}
        .small{font-size:12px;color:#758aa3;margin-top:16px;line-height:1.7}
    </style>
</head>
<body>
<main class="card">
    <div class="logo">⌾</div>
    <h1>مركز الأمان</h1>
    <p class="hint">مراقبة دخول تطبيق Doctor Bike وإدارة عناوين IP المحظورة من Laravel.</p>
    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('security-center.login.submit') }}">
        @csrf
        <label for="token">رمز الدخول</label>
        <input class="input" id="token" type="password" name="token" autocomplete="current-password" required autofocus>
        <button type="submit">دخول آمن</button>
    </form>
    <div class="small">يتم ضبط الرمز في السيرفر من خلال SECURITY_CENTER_WEB_TOKEN ولا يظهر داخل الرابط.</div>
</main>
</body>
</html>

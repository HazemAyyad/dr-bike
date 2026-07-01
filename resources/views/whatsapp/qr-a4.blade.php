<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22mm; }
        body { font-family: DejaVu Sans, sans-serif; text-align: center; color: #12372f; }
        .frame { border: 4px solid #075e54; border-radius: 24px; padding: 34px; height: 235mm; }
        h1 { font-size: 34px; margin: 14px 0 4px; }
        p { font-size: 22px; margin: 8px; }
        img { width: 145mm; height: 145mm; margin-top: 15mm; }
        .phone { direction: ltr; font-size: 28px; font-weight: bold; color: #075e54; }
    </style>
</head>
<body>
<div class="frame">
    <h1>تواصل مع دكتور بايك عبر واتساب</h1>
    <p>امسح الرمز لبدء المحادثة</p>
    <img src="data:image/svg+xml;base64,{{ $qr }}" alt="WhatsApp QR">
    <p class="phone">+{{ $phone }}</p>
</div>
</body>
</html>

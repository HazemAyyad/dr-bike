<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product['name'] }} | Doctor Bike</title>
    <meta name="description" content="{{ $product['description_excerpt'] }}">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="canonical" href="{{ $product['canonical_url'] }}">
    <meta property="og:locale" content="ar_PS">
    <meta property="og:type" content="product">
    <meta property="og:site_name" content="Doctor Bike">
    <meta property="og:title" content="{{ $product['name'] }}">
    <meta property="og:description" content="{{ $product['description_excerpt'] }}">
    <meta property="og:url" content="{{ $product['canonical_url'] }}">
    <meta property="og:image" content="{{ $product['main_image'] }}">
    <meta property="og:image:alt" content="{{ $product['name'] }}">
    <meta property="product:price:amount" content="{{ number_format($product['price'], 2, '.', '') }}">
    <meta property="product:price:currency" content="ILS">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $product['name'] }}">
    <meta name="twitter:description" content="{{ $product['description_excerpt'] }}">
    <meta name="twitter:image" content="{{ $product['main_image'] }}">
    <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <style>
        :root {
            --primary: #6558cb;
            --primary-dark: #5144b7;
            --primary-soft: #f0effc;
            --ink: #171a33;
            --muted: #74798b;
            --surface: #ffffff;
            --background: #f7f8fb;
            --border: #e6e8ef;
            --success: #148657;
            --success-soft: #eaf8f1;
            --danger: #b42332;
            --shadow: 0 18px 48px rgba(31, 35, 61, .08);
            --radius-lg: 22px;
            --radius-md: 15px;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            color: var(--ink);
            background: var(--background);
            font-family: "Tajawal", "Segoe UI", Tahoma, Arial, sans-serif;
            line-height: 1.65;
        }
        button, input { font: inherit; }
        a { color: inherit; text-decoration: none; }
        .container { width: min(1480px, calc(100% - 48px)); margin-inline: auto; }
        .site-header {
            position: sticky;
            top: 0;
            z-index: 40;
            background: rgba(255, 255, 255, .96);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border);
        }
        .header-row {
            min-height: 82px;
            display: grid;
            grid-template-columns: 180px minmax(380px, 1fr) minmax(280px, 520px) 150px;
            align-items: center;
            gap: 24px;
        }
        .brand { display: inline-flex; align-items: center; justify-content: flex-start; }
        .brand img { width: 126px; height: 66px; object-fit: contain; }
        .main-nav { display: flex; align-items: center; gap: clamp(18px, 2.5vw, 42px); font-weight: 700; }
        .main-nav a { transition: color .18s ease; white-space: nowrap; }
        .main-nav a:hover { color: var(--primary); }
        .search-shell {
            height: 48px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-inline: 18px;
            color: var(--muted);
            background: #f4f5f8;
            border: 1px solid #eceef4;
            border-radius: 999px;
        }
        .search-shell input { width: 100%; border: 0; outline: 0; background: transparent; color: var(--muted); }
        .header-actions { display: flex; justify-content: flex-end; gap: 8px; direction: ltr; }
        .icon-button {
            width: 43px;
            height: 43px;
            display: inline-grid;
            place-items: center;
            color: var(--ink);
            background: transparent;
            border: 0;
            border-radius: 50%;
        }
        .icon-button svg, .share-icon svg { width: 23px; height: 23px; fill: none; stroke: currentColor; stroke-width: 1.9; }
        .breadcrumb { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding-block: 22px 16px; color: var(--muted); font-size: 14px; }
        .breadcrumb a { color: var(--primary); font-weight: 700; }
        .product-grid { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(420px, .92fr); gap: 18px; align-items: stretch; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow); }
        .gallery-card { min-height: 590px; display: grid; grid-template-columns: 106px minmax(0, 1fr); gap: 18px; padding: 18px; direction: ltr; }
        .thumbs { display: flex; flex-direction: column; gap: 12px; overflow-y: auto; scrollbar-width: thin; }
        .thumb {
            width: 100%;
            aspect-ratio: 1;
            padding: 6px;
            overflow: hidden;
            cursor: pointer;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 13px;
            transition: border-color .18s, box-shadow .18s;
        }
        .thumb[aria-current="true"] { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(101, 88, 203, .12); }
        .thumb img { width: 100%; height: 100%; object-fit: contain; }
        .main-media { position: relative; min-height: 540px; display: grid; place-items: center; overflow: hidden; background: #fff; border: 1px solid #eef0f5; border-radius: 17px; }
        .main-media img { width: 100%; height: 100%; max-height: 550px; object-fit: contain; transition: opacity .18s ease; }
        .media-count { position: absolute; bottom: 14px; left: 14px; padding: 5px 10px; background: rgba(23, 26, 51, .78); color: #fff; border-radius: 999px; font-size: 12px; direction: ltr; }
        .product-panel { display: flex; flex-direction: column; padding: clamp(22px, 3vw, 38px); }
        .product-title { margin: 0; font-size: clamp(27px, 2.3vw, 40px); line-height: 1.35; letter-spacing: -.3px; }
        .product-code { margin-top: 8px; color: var(--muted); font-size: 14px; }
        .rating-row { min-height: 34px; display: flex; align-items: center; gap: 10px; margin-top: 11px; }
        .stars-meter { position: relative; display: inline-block; font-size: 20px; line-height: 1; letter-spacing: 2px; direction: ltr; }
        .stars-base { color: #dfe1e8; }
        .stars-fill { position: absolute; inset: 0 auto 0 0; width: calc(var(--rating) / 5 * 100%); overflow: hidden; color: #f5b51b; white-space: nowrap; }
        .rating-copy { color: var(--muted); font-size: 14px; }
        .price-row { display: flex; align-items: baseline; gap: 12px; margin-top: 16px; }
        .price { color: var(--primary); font-size: clamp(32px, 3vw, 45px); font-weight: 800; direction: ltr; }
        .old-price { color: #9ba0ae; text-decoration: line-through; direction: ltr; }
        .discount { padding: 4px 9px; color: var(--danger); background: #fff0f2; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .stock-row { display: flex; align-items: center; gap: 10px; margin-top: 4px; }
        .stock-pill { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px; border-radius: 999px; font-weight: 700; font-size: 13px; }
        .stock-pill.available { color: var(--success); background: var(--success-soft); }
        .stock-pill.unavailable { color: var(--danger); background: #fff0f2; }
        .stock-pill::before { content: ""; width: 8px; height: 8px; background: currentColor; border-radius: 50%; }
        .quantity-line { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-top: 23px; padding-top: 18px; border-top: 1px solid var(--border); }
        .quantity { display: grid; grid-template-columns: 40px 50px 40px; overflow: hidden; border: 1px solid var(--border); border-radius: 12px; direction: ltr; }
        .quantity button, .quantity output { height: 40px; display: grid; place-items: center; border: 0; background: #fff; }
        .quantity button { cursor: pointer; color: var(--ink); font-size: 20px; }
        .quantity button:hover { background: var(--primary-soft); color: var(--primary); }
        .quantity output { border-inline: 1px solid var(--border); font-weight: 700; }
        .cta-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 12px; margin-top: 20px; }
        .btn { min-height: 52px; display: inline-flex; align-items: center; justify-content: center; gap: 9px; padding: 12px 20px; cursor: pointer; border: 1px solid transparent; border-radius: 13px; font-weight: 800; transition: transform .15s, box-shadow .15s, background .15s; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { color: #fff; background: linear-gradient(135deg, var(--primary), #786ade); box-shadow: 0 12px 24px rgba(101, 88, 203, .22); }
        .btn-outline { color: var(--primary); background: #fff; border-color: var(--primary); }
        .btn:disabled { cursor: not-allowed; opacity: .55; transform: none; box-shadow: none; }
        .cta-note { margin: 9px 0 0; color: var(--muted); text-align: center; font-size: 12px; }
        .secondary-actions { display: flex; justify-content: center; gap: 28px; margin-top: 18px; }
        .link-action { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; color: #4f5364; background: none; border: 0; font-weight: 700; }
        .link-action:hover { color: var(--primary); }
        .trust-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: auto; padding-top: 24px; }
        .trust-item { padding: 12px 9px; text-align: center; background: #fafafe; border: 1px solid #efeff7; border-radius: 12px; }
        .trust-item strong { display: block; font-size: 13px; }
        .trust-item span { display: block; margin-top: 2px; color: var(--muted); font-size: 11px; }
        .app-banner { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; margin-top: 18px; padding: 18px 20px; background: var(--primary-soft); border: 1px solid #e3e0fb; border-radius: var(--radius-md); }
        .app-banner strong { display: block; }
        .app-banner p { margin: 3px 0 0; color: var(--muted); font-size: 13px; }
        .app-banner .btn { min-height: 44px; }
        .details-card { margin-top: 20px; padding: 0 22px 24px; }
        .tabs { display: flex; gap: clamp(20px, 6vw, 90px); border-bottom: 1px solid var(--border); }
        .tab { position: relative; padding: 20px 5px 15px; cursor: pointer; color: var(--muted); background: transparent; border: 0; font-weight: 800; }
        .tab[aria-selected="true"] { color: var(--primary); }
        .tab[aria-selected="true"]::after { content: ""; position: absolute; inset-inline: 0; bottom: -1px; height: 3px; background: var(--primary); border-radius: 999px; }
        .tab-panel { display: none; padding-top: 22px; }
        .tab-panel.active { display: block; }
        .description { margin: 0; color: #54596c; white-space: pre-line; }
        .spec-table { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px 28px; }
        .spec-row { display: grid; grid-template-columns: minmax(110px, .8fr) 1.2fr; gap: 15px; padding: 13px 15px; background: #fafbfc; border-bottom: 1px solid #eff0f4; }
        .spec-row dt { font-weight: 800; }
        .spec-row dd { margin: 0; color: var(--muted); }
        .policy-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 18px; }
        .policy { padding: 15px; color: #54596c; background: #fafafe; border: 1px solid #ececf5; border-radius: 12px; }
        .policy strong { display: block; margin-bottom: 4px; color: var(--ink); }
        .empty-copy { padding: 24px; text-align: center; color: var(--muted); }
        .related-section { padding-block: 27px 50px; }
        .section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .section-head h2 { margin: 0; font-size: 27px; }
        .section-head span { color: var(--muted); font-size: 13px; }
        .products { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .product-card { position: relative; display: grid; grid-template-columns: 145px minmax(0, 1fr); gap: 14px; min-height: 170px; padding: 14px; background: #fff; border: 1px solid var(--border); border-radius: 17px; transition: transform .18s, box-shadow .18s; }
        .product-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
        .product-card img { width: 100%; height: 142px; object-fit: contain; background: #fafafa; border-radius: 12px; }
        .product-card-content { display: flex; flex-direction: column; justify-content: center; min-width: 0; }
        .product-card h3 { margin: 0; font-size: 17px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .product-card .card-price { margin-top: 12px; color: var(--primary); font-size: 20px; font-weight: 800; direction: ltr; text-align: right; }
        .toast { position: fixed; left: 50%; bottom: 30px; z-index: 80; transform: translate(-50%, 30px); padding: 11px 18px; color: #fff; background: #20243c; border-radius: 12px; opacity: 0; pointer-events: none; transition: .2s; }
        .toast.visible { transform: translate(-50%, 0); opacity: 1; }
        footer { padding: 25px 0; color: var(--muted); text-align: center; border-top: 1px solid var(--border); background: #fff; font-size: 13px; }
        @media (max-width: 1180px) {
            .header-row { grid-template-columns: 140px 1fr 300px auto; gap: 14px; }
            .main-nav { gap: 16px; font-size: 14px; }
            .product-grid { grid-template-columns: 1fr 420px; }
            .gallery-card { min-height: 520px; }
            .main-media { min-height: 470px; }
            .trust-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 900px) {
            .container { width: min(100% - 28px, 760px); }
            .header-row { min-height: 70px; grid-template-columns: 110px 1fr auto; }
            .brand img { width: 100px; height: 54px; }
            .main-nav { display: none; }
            .search-shell { grid-column: 1 / -1; grid-row: 2; margin-bottom: 12px; }
            .product-grid { grid-template-columns: 1fr; }
            .gallery-card { min-height: 0; grid-template-columns: 1fr; }
            .thumbs { order: 2; flex-direction: row; }
            .thumb { flex: 0 0 78px; }
            .main-media { min-height: 390px; }
            .trust-grid { grid-template-columns: repeat(3, 1fr); }
            .products { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .container { width: min(100% - 20px, 520px); }
            .header-row { grid-template-columns: 90px 1fr; gap: 8px; }
            .header-actions { grid-column: 2; grid-row: 1; }
            .icon-button { width: 38px; height: 38px; }
            .breadcrumb { padding-block: 14px 10px; font-size: 12px; }
            .gallery-card { padding: 10px; border-radius: 16px; }
            .main-media { min-height: 320px; }
            .product-panel { padding: 20px 16px; }
            .product-title { font-size: 27px; }
            .cta-grid { grid-template-columns: 1fr; }
            .secondary-actions { gap: 16px; }
            .trust-grid, .policy-grid, .spec-table { grid-template-columns: 1fr; }
            .app-banner { grid-template-columns: 1fr; text-align: center; }
            .tabs { justify-content: space-between; gap: 4px; }
            .details-card { padding-inline: 14px; }
            .section-head h2 { font-size: 23px; }
            .product-card { grid-template-columns: 116px 1fr; min-height: 148px; }
            .product-card img { height: 120px; }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="{{ url('/') }}" aria-label="Doctor Bike">
            <img src="{{ asset('assets/doctor-bike-logo.png') }}" alt="شعار Doctor Bike" width="126" height="66">
        </a>
        <nav class="main-nav" aria-label="التنقل الرئيسي">
            <a href="{{ url('/') }}">الرئيسية</a>
            <a href="#related-products">الأقسام</a>
            <a href="#related-products">العروض</a>
            <a href="{{ url('/') }}">تواصل معنا</a>
        </nav>
        <label class="search-shell" title="البحث الكامل متاح داخل تطبيق Doctor Bike">
            <svg aria-hidden="true" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.6-3.6"></path></svg>
            <input type="text" value="" placeholder="ابحث عن منتج أو قسم" readonly aria-label="البحث متاح داخل التطبيق">
        </label>
        <div class="header-actions" aria-label="روابط التطبيق">
            <button class="icon-button" type="button" data-open-app title="فتح الحساب في التطبيق" aria-label="الحساب">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4.5 21a7.5 7.5 0 0 1 15 0"></path></svg>
            </button>
            <button class="icon-button" type="button" data-open-app title="فتح المفضلة في التطبيق" aria-label="المفضلة">
                <svg viewBox="0 0 24 24"><path d="M20.8 4.6a5.4 5.4 0 0 0-7.6 0L12 5.8l-1.2-1.2a5.4 5.4 0 0 0-7.6 7.6L12 21l8.8-8.8a5.4 5.4 0 0 0 0-7.6Z"></path></svg>
            </button>
            <button class="icon-button" type="button" data-open-app title="فتح السلة في التطبيق" aria-label="السلة">
                <svg viewBox="0 0 24 24"><circle cx="9" cy="20" r="1"></circle><circle cx="19" cy="20" r="1"></circle><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 8H6"></path></svg>
            </button>
        </div>
    </div>
</header>

<main class="container">
    <nav class="breadcrumb" aria-label="مسار التنقل">
        <a href="{{ url('/') }}">الرئيسية</a><span>/</span>
        @foreach($product['categories'] as $category)
            <span>{{ $category }}</span><span>/</span>
        @endforeach
        <span aria-current="page">{{ $product['name'] }}</span>
    </nav>

    <section class="product-grid" aria-labelledby="product-title">
        <div class="card gallery-card">
            <div class="thumbs" aria-label="صور المنتج">
                @foreach($product['media'] as $index => $media)
                    <button class="thumb" type="button" data-image="{{ $media['url'] }}" data-alt="{{ $media['alt'] }}" aria-current="{{ $index === 0 ? 'true' : 'false' }}" aria-label="عرض الصورة {{ $index + 1 }}">
                        <img src="{{ $media['url'] }}" alt="" loading="{{ $index === 0 ? 'eager' : 'lazy' }}">
                    </button>
                @endforeach
            </div>
            <div class="main-media">
                <img id="main-product-image" src="{{ $product['main_image'] }}" alt="{{ $product['name'] }}" fetchpriority="high">
                @if(count($product['media']) > 1)
                    <span class="media-count"><span id="media-index">1</span> / {{ count($product['media']) }}</span>
                @endif
            </div>
        </div>

        <article class="card product-panel">
            <h1 class="product-title" id="product-title">{{ $product['name'] }}</h1>
            <div class="product-code">رمز المنتج: <bdi>{{ $product['code'] }}</bdi></div>
            <div class="rating-row" aria-label="تقييم المنتج">
                @if($product['review_count'] > 0)
                    <span class="stars-meter" style="--rating: {{ min(5, max(0, $product['rating'])) }}" aria-hidden="true">
                        <span class="stars-base">★★★★★</span>
                        <span class="stars-fill">★★★★★</span>
                    </span>
                    <span class="rating-copy"><strong>{{ number_format($product['rating'], 1) }}</strong> ({{ $product['review_count'] }} تقييم)</span>
                @else
                    <span class="rating-copy">لا توجد تقييمات منشورة بعد</span>
                @endif
            </div>
            <div class="price-row">
                <span class="price">{{ number_format($product['price'], 2) }} ₪</span>
                @if($product['old_price'] !== null)
                    <span class="old-price">{{ number_format($product['old_price'], 2) }} ₪</span>
                    <span class="discount">خصم {{ $product['discount_percent'] }}%</span>
                @endif
            </div>
            <div class="stock-row">
                <span class="stock-pill {{ $product['purchasable'] ? 'available' : 'unavailable' }}">
                    {{ $product['purchasable'] ? 'متوفر للطلب' : 'غير متوفر حالياً' }}
                </span>
            </div>
            <div class="quantity-line">
                <strong>الكمية</strong>
                <div class="quantity" aria-label="الكمية المطلوبة">
                    <button type="button" id="quantity-minus" aria-label="تقليل الكمية">−</button>
                    <output id="quantity-value" aria-live="polite">1</output>
                    <button type="button" id="quantity-plus" aria-label="زيادة الكمية">+</button>
                </div>
            </div>
            <div class="cta-grid">
                <button class="btn btn-primary" type="button" data-open-app {{ $product['purchasable'] ? '' : 'disabled' }}>
                    أضف إلى السلة
                </button>
                <button class="btn btn-outline" type="button" data-open-app {{ $product['purchasable'] ? '' : 'disabled' }}>
                    اشترِ الآن
                </button>
            </div>
            <p class="cta-note">سيتم فتح تطبيق Doctor Bike لإكمال اختيار الخيارات والطلب بأمان.</p>
            <div class="secondary-actions">
                <button class="link-action" type="button" data-open-app>
                    <span class="share-icon"><svg viewBox="0 0 24 24"><path d="M20.8 4.6a5.4 5.4 0 0 0-7.6 0L12 5.8l-1.2-1.2a5.4 5.4 0 0 0-7.6 7.6L12 21l8.8-8.8a5.4 5.4 0 0 0 0-7.6Z"></path></svg></span>
                    إضافة للمفضلة
                </button>
                <button class="link-action" type="button" id="share-product">
                    <span class="share-icon"><svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"></path></svg></span>
                    مشاركة
                </button>
            </div>
            <div class="trust-grid">
                <div class="trust-item"><strong>الدفع عند الاستلام</strong><span>أكمل طلبك بأمان</span></div>
                <div class="trust-item"><strong>توصيل للمناطق المتاحة</strong><span>يُحسب داخل التطبيق</span></div>
                <div class="trust-item"><strong>دعم ما بعد البيع</strong><span>فريق Doctor Bike معك</span></div>
            </div>
            <aside class="app-banner">
                <div><strong>تجربة أسرع عبر تطبيق Doctor Bike</strong><p>الخيارات والسلة وإتمام الطلب متاحة داخل التطبيق.</p></div>
                <button class="btn btn-outline" type="button" data-open-app>فتح التطبيق</button>
            </aside>
        </article>
    </section>

    <section class="card details-card" aria-label="تفاصيل المنتج">
        <div class="tabs" role="tablist">
            <button class="tab" type="button" role="tab" aria-selected="true" aria-controls="specifications">المواصفات</button>
            <button class="tab" type="button" role="tab" aria-selected="false" aria-controls="description">الوصف</button>
            <button class="tab" type="button" role="tab" aria-selected="false" aria-controls="reviews">التقييمات</button>
        </div>
        <div class="tab-panel active" id="specifications" role="tabpanel">
            <dl class="spec-table">
                <div class="spec-row"><dt>الموديل</dt><dd>{{ $product['code'] }}</dd></div>
                @if($product['categories'] !== [])
                    <div class="spec-row"><dt>التصنيف</dt><dd>{{ implode('، ', $product['categories']) }}</dd></div>
                @endif
                <div class="spec-row"><dt>حالة التوفر</dt><dd>{{ $product['purchasable'] ? 'متوفر للطلب' : 'غير متوفر حالياً' }}</dd></div>
                @foreach($product['quick_specs'] as $spec)
                    <div class="spec-row"><dt>{{ $spec['label'] }}</dt><dd>{{ $spec['value'] }}</dd></div>
                @endforeach
            </dl>
            @if($product['shipping_warranty'] !== '' || $product['return_policy'] !== '')
                <div class="policy-grid">
                    @if($product['shipping_warranty'] !== '')
                        <div class="policy"><strong>الشحن والضمان</strong>{{ $product['shipping_warranty'] }}</div>
                    @endif
                    @if($product['return_policy'] !== '')
                        <div class="policy"><strong>سياسة الإرجاع</strong>{{ $product['return_policy'] }}</div>
                    @endif
                </div>
            @endif
        </div>
        <div class="tab-panel" id="description" role="tabpanel">
            @if($product['description'] !== '')
                <p class="description">{{ $product['description'] }}</p>
            @else
                <div class="empty-copy">لا يوجد وصف إضافي لهذا المنتج.</div>
            @endif
        </div>
        <div class="tab-panel" id="reviews" role="tabpanel">
            <div class="empty-copy">
                @if($product['review_count'] > 0)
                    تقييم المنتج {{ number_format($product['rating'], 1) }} من 5 بناءً على {{ $product['review_count'] }} تقييم منشور. افتح التطبيق لقراءة التقييمات.
                @else
                    لا توجد تقييمات منشورة لهذا المنتج بعد.
                @endif
            </div>
        </div>
    </section>

    @if($relatedProducts !== [])
        <section class="related-section" id="related-products" aria-labelledby="related-title">
            <div class="section-head"><h2 id="related-title">قد يعجبك أيضاً</h2><span>منتجات منشورة ومتاحة في المتجر</span></div>
            <div class="products">
                @foreach($relatedProducts as $related)
                    <a class="product-card" href="{{ $related['canonical_url'] }}">
                        <img src="{{ $related['main_image'] }}" alt="{{ $related['name'] }}" loading="lazy">
                        <div class="product-card-content">
                            <h3>{{ $related['name'] }}</h3>
                            @if($related['review_count'] > 0)
                                <span class="rating-copy">★ {{ number_format($related['rating'], 1) }} ({{ $related['review_count'] }})</span>
                            @endif
                            <span class="card-price">{{ number_format($related['price'], 2) }} ₪</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</main>

<footer>© {{ date('Y') }} Doctor Bike — Boost | Repair | Sell</footer>
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
(() => {
    const canonicalUrl = @json($product['canonical_url']);
    const appDeepLink = @json($product['app_deep_link']);
    const productName = @json($product['name']);
    const maximum = Math.max(1, @json($product['available_quantity']));
    const mainImage = document.getElementById('main-product-image');
    const mediaIndex = document.getElementById('media-index');
    const quantityValue = document.getElementById('quantity-value');
    const toast = document.getElementById('toast');
    let quantity = 1;
    let toastTimer;

    const notify = (message) => {
        clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.add('visible');
        toastTimer = setTimeout(() => toast.classList.remove('visible'), 2300);
    };

    document.querySelectorAll('.thumb').forEach((thumb, index) => {
        thumb.addEventListener('click', () => {
            document.querySelectorAll('.thumb').forEach(item => item.setAttribute('aria-current', 'false'));
            thumb.setAttribute('aria-current', 'true');
            mainImage.style.opacity = '.35';
            mainImage.src = thumb.dataset.image;
            mainImage.alt = thumb.dataset.alt || productName;
            mainImage.onload = () => mainImage.style.opacity = '1';
            if (mediaIndex) mediaIndex.textContent = String(index + 1);
        });
    });

    document.getElementById('quantity-minus').addEventListener('click', () => {
        quantity = Math.max(1, quantity - 1);
        quantityValue.textContent = String(quantity);
    });
    document.getElementById('quantity-plus').addEventListener('click', () => {
        quantity = Math.min(maximum, quantity + 1);
        quantityValue.textContent = String(quantity);
    });

    document.querySelectorAll('[data-open-app]').forEach(button => {
        button.addEventListener('click', () => {
            window.location.href = `${appDeepLink}?quantity=${quantity}`;
        });
    });

    document.getElementById('share-product').addEventListener('click', async () => {
        const data = { title: productName, text: `شاهد ${productName} على Doctor Bike`, url: canonicalUrl };
        try {
            if (navigator.share) {
                await navigator.share(data);
            } else {
                await navigator.clipboard.writeText(canonicalUrl);
                notify('تم نسخ رابط المنتج');
            }
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            try {
                await navigator.clipboard.writeText(canonicalUrl);
                notify('تم نسخ رابط المنتج');
            } catch (_) {
                notify('تعذرت مشاركة الرابط');
            }
        }
    });

    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(item => item.setAttribute('aria-selected', 'false'));
            document.querySelectorAll('.tab-panel').forEach(panel => panel.classList.remove('active'));
            tab.setAttribute('aria-selected', 'true');
            document.getElementById(tab.getAttribute('aria-controls')).classList.add('active');
        });
    });
})();
</script>
</body>
</html>

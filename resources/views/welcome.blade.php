<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="دكتور بايك للألعاب والدراجات - بيع الدراجات الهوائية وقطعها وملحقاتها بالجملة في نابلس.">
    <title>دكتور بايك | Doctor Bike</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --db-primary: #6c63b7;
            --db-primary-dark: #45408a;
            --db-ink: #20232d;
            --db-muted: #667085;
            --db-line: #e7e9f1;
            --db-surface: #f6f7fb;
            --db-accent: #17a2a4;
        }

        body {
            font-family: "Tahoma", "Arial", sans-serif;
            color: var(--db-ink);
            background: #fff;
        }

        .navbar {
            border-bottom: 1px solid rgba(231, 233, 241, .85);
            backdrop-filter: blur(12px);
        }

        .brand-logo {
            width: 54px;
            height: 54px;
            object-fit: contain;
        }

        .hero {
            min-height: 76vh;
            display: flex;
            align-items: center;
            background:
                linear-gradient(115deg, rgba(255, 255, 255, .96) 0%, rgba(255, 255, 255, .9) 45%, rgba(246, 247, 251, .7) 100%),
                url("{{ asset('assets/doctor-bike-logo.png') }}") left 8% center / min(520px, 62vw) no-repeat;
            border-bottom: 1px solid var(--db-line);
        }

        .hero-logo {
            width: min(270px, 64vw);
            max-height: 220px;
            object-fit: contain;
            filter: drop-shadow(0 18px 35px rgba(32, 35, 45, .14));
        }

        .eyebrow {
            color: var(--db-primary-dark);
            font-weight: 700;
            letter-spacing: 0;
        }

        .hero-title {
            font-size: clamp(2.2rem, 5vw, 4.8rem);
            font-weight: 800;
            line-height: 1.08;
            letter-spacing: 0;
        }

        .hero-copy {
            max-width: 690px;
            color: var(--db-muted);
            font-size: 1.18rem;
            line-height: 1.9;
        }

        .btn-db {
            --bs-btn-bg: var(--db-primary);
            --bs-btn-border-color: var(--db-primary);
            --bs-btn-hover-bg: var(--db-primary-dark);
            --bs-btn-hover-border-color: var(--db-primary-dark);
            --bs-btn-color: #fff;
            --bs-btn-hover-color: #fff;
            border-radius: 8px;
            font-weight: 700;
            padding: .82rem 1.25rem;
        }

        .btn-outline-db {
            --bs-btn-color: var(--db-primary-dark);
            --bs-btn-border-color: var(--db-primary);
            --bs-btn-hover-bg: var(--db-primary);
            --bs-btn-hover-border-color: var(--db-primary);
            --bs-btn-hover-color: #fff;
            border-radius: 8px;
            font-weight: 700;
            padding: .82rem 1.25rem;
        }

        .section {
            padding: 84px 0;
        }

        .section-soft {
            background: var(--db-surface);
        }

        .section-title {
            font-weight: 800;
            letter-spacing: 0;
        }

        .service-card,
        .info-card,
        .contact-card {
            height: 100%;
            background: #fff;
            border: 1px solid var(--db-line);
            border-radius: 8px;
            padding: 28px;
            box-shadow: 0 16px 42px rgba(31, 35, 45, .06);
        }

        .service-icon {
            width: 48px;
            height: 48px;
            display: inline-grid;
            place-items: center;
            color: #fff;
            background: var(--db-primary);
            border-radius: 8px;
            font-size: 1.4rem;
            margin-bottom: 18px;
        }

        .muted {
            color: var(--db-muted);
        }

        .stat-strip {
            border-top: 1px solid var(--db-line);
            border-bottom: 1px solid var(--db-line);
            background: #fff;
        }

        .stat-item {
            padding: 22px 10px;
        }

        .stat-label {
            color: var(--db-muted);
            font-size: .92rem;
        }

        .stat-value {
            font-weight: 800;
            color: var(--db-primary-dark);
        }

        .contact-link {
            color: var(--db-ink);
            text-decoration: none;
            font-weight: 700;
        }

        .contact-link:hover {
            color: var(--db-primary-dark);
        }

        .footer {
            background: #20232d;
            color: rgba(255, 255, 255, .78);
            padding: 26px 0;
        }

        @media (max-width: 991.98px) {
            .hero {
                min-height: auto;
                padding: 92px 0 56px;
                background: linear-gradient(180deg, #fff 0%, var(--db-surface) 100%);
            }

            .section {
                padding: 58px 0;
            }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="#top" aria-label="دكتور بايك">
            <img class="brand-logo" src="{{ asset('assets/doctor-bike-logo.png') }}" alt="شعار دكتور بايك">
            <span>دكتور بايك</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="فتح القائمة">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="#services">الخدمات</a></li>
                <li class="nav-item"><a class="nav-link" href="#company">معلومات الشركة</a></li>
                <li class="nav-item"><a class="nav-link" href="#contact">تواصل معنا</a></li>
            </ul>
            <a class="btn btn-db btn-sm" href="tel:0123456789">اتصل الآن</a>
        </div>
    </div>
</nav>

<main id="top">
    <section class="hero">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <p class="eyebrow mb-3">بيع، صيانة، وقطع دراجات</p>
                    <h1 class="hero-title mb-4">دكتور بايك للألعاب والدراجات</h1>
                    <p class="hero-copy mb-4">
                        شركة فلسطينية متخصصة في بيع الدراجات الهوائية وقطعها وملحقاتها بالجملة، مع اهتمام عملي بالجودة والسرعة وخدمة التجار والعملاء في نابلس.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <a class="btn btn-db" href="#contact">معلومات التواصل</a>
                        <a class="btn btn-outline-db" href="#company">عرض بيانات التسجيل</a>
                    </div>
                </div>
                <div class="col-lg-5 text-center">
                    <img class="hero-logo" src="{{ asset('assets/doctor-bike-logo.png') }}" alt="Doctor Bike">
                </div>
            </div>
        </div>
    </section>

    <section class="stat-strip">
        <div class="container">
            <div class="row text-center">
                <div class="col-6 col-lg-3 stat-item">
                    <div class="stat-value">نابلس</div>
                    <div class="stat-label">المحافظة والمدينة</div>
                </div>
                <div class="col-6 col-lg-3 stat-item">
                    <div class="stat-value">565025020</div>
                    <div class="stat-label">رقم سجل الشركات</div>
                </div>
                <div class="col-6 col-lg-3 stat-item">
                    <div class="stat-value">2025-09-25</div>
                    <div class="stat-label">تاريخ التسجيل</div>
                </div>
                <div class="col-6 col-lg-3 stat-item">
                    <div class="stat-value">بيع بالجملة</div>
                    <div class="stat-label">النشاط الأساسي</div>
                </div>
            </div>
        </div>
    </section>

    <section id="services" class="section">
        <div class="container">
            <div class="row mb-4">
                <div class="col-lg-8">
                    <h2 class="section-title mb-3">ماذا نقدم؟</h2>
                    <p class="muted mb-0">حلول عملية لمتاجر الدراجات والعملاء، من المنتجات والقطع إلى المتابعة بعد البيع.</p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="service-card">
                        <div class="service-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                        <h3 class="h5 fw-bold mb-3">دراجات وملحقات</h3>
                        <p class="muted mb-0">توفير الدراجات الهوائية وقطعها وملحقاتها للتجار والعملاء باختيارات مناسبة للسوق المحلي.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="service-card">
                        <div class="service-icon"><i class="bi bi-box-seam-fill"></i></div>
                        <h3 class="h5 fw-bold mb-3">توريد واستيراد</h3>
                        <p class="muted mb-0">نشاط استيراد وتصدير للحساب الخاص لدعم توفر المنتجات والقطع المطلوبة باستمرار.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="service-card">
                        <div class="service-icon"><i class="bi bi-telephone-fill"></i></div>
                        <h3 class="h5 fw-bold mb-3">خدمة وتواصل</h3>
                        <p class="muted mb-0">قنوات تواصل مباشرة للاستفسارات، الطلبات، والمتابعة مع فريق دكتور بايك.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="company" class="section section-soft">
        <div class="container">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-5">
                    <h2 class="section-title mb-3">معلومات الشركة</h2>
                    <p class="muted mb-4">
                        البيانات التالية مبنية على شهادة التسجيل المرفقة من سجل الشركات الفلسطيني.
                    </p>
                    <div class="info-card">
                        <dl class="row mb-0 gy-3">
                            <dt class="col-5 muted">الاسم</dt>
                            <dd class="col-7 fw-bold mb-0">شركة دكتور بايك للألعاب والدراجات ش.م.خ</dd>
                            <dt class="col-5 muted">رأس المال</dt>
                            <dd class="col-7 fw-bold mb-0">100,000 دينار أردني</dd>
                            <dt class="col-5 muted">مدة الشركة</dt>
                            <dd class="col-7 fw-bold mb-0">غير محددة المدة</dd>
                            <dt class="col-5 muted">العنوان</dt>
                            <dd class="col-7 fw-bold mb-0">نابلس، شارع عصيرة الرئيسي</dd>
                        </dl>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="info-card">
                        <h3 class="h5 fw-bold mb-4">الغايات المسجلة</h3>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="p-3 border rounded-2 h-100">
                                    <p class="muted small mb-2">الغاية الأساسية</p>
                                    <p class="fw-bold mb-0">بيع الدراجات الهوائية وقطعها وملحقاتها بالجملة</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 border rounded-2 h-100">
                                    <p class="muted small mb-2">الغاية الثانية</p>
                                    <p class="fw-bold mb-0">أنشطة الاستيراد والتصدير للحساب الخاص</p>
                                </div>
                            </div>
                        </div>
                        <hr class="my-4">
                        <p class="muted mb-0">
                            رقم التسجيل: <strong class="text-dark">565025020</strong>، تاريخ التسجيل: <strong class="text-dark">2025-09-25</strong>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="section">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <h2 class="section-title mb-3">تواصل معنا</h2>
                    <p class="muted mb-0">للطلبات والاستفسارات، استخدم إحدى القنوات التالية.</p>
                </div>
                <div class="col-lg-8">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="contact-card">
                                <p class="muted mb-2">الهاتف</p>
                                <a class="contact-link fs-5" href="tel:0123456789">0123456789</a>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="contact-card">
                                <p class="muted mb-2">البريد الإلكتروني</p>
                                <a class="contact-link fs-5" href="mailto:example@gmail.com">example@gmail.com</a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <a class="btn btn-outline-db w-100" href="https://wa.me/" target="_blank" rel="noopener">واتساب</a>
                        </div>
                        <div class="col-md-4">
                            <a class="btn btn-outline-db w-100" href="https://www.instagram.com/" target="_blank" rel="noopener">إنستغرام</a>
                        </div>
                        <div class="col-md-4">
                            <a class="btn btn-outline-db w-100" href="https://www.x.com/" target="_blank" rel="noopener">X</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
        <span>© {{ date('Y') }} دكتور بايك. جميع الحقوق محفوظة.</span>
        <span>نابلس، فلسطين</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

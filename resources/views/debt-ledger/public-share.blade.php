<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $person['name'] ?? 'دفتر الديون' }} - دكتور بايك</title>
    <style>
        :root { --purple:#6b65bd; --ink:#263238; --soft:#f4f3fc; --line:#d9dce5; --muted:#667085; --green:#15803d; --red:#b91c1c; }
        * { box-sizing:border-box; }
        body { margin:0; padding:22px 12px; direction:rtl; color:var(--ink); background:#eef0f5; font-family:Tahoma,Arial,sans-serif; }
        .report { width:min(850px,100%); margin:auto; padding:28px; background:#fff; border-radius:12px; box-shadow:0 8px 30px rgba(31,41,55,.09); }
        .brand { display:flex; align-items:center; justify-content:space-between; gap:18px; padding-bottom:10px; margin-bottom:18px; border-bottom:2px solid var(--purple); }
        .brand-name { color:var(--purple); font-size:25px; font-weight:800; letter-spacing:.4px; }
        .brand-subtitle { margin-top:3px; font-size:13px; font-weight:700; }
        .brand img { width:auto; height:58px; object-fit:contain; }
        h1 { margin:0 0 16px; color:var(--purple); text-align:center; font-size:21px; }
        .meta { display:grid; grid-template-columns:1fr 1fr; gap:7px 22px; margin-bottom:14px; }
        .meta div { font-size:13px; color:var(--muted); }
        .meta strong { color:var(--ink); }
        .summary { display:grid; grid-template-columns:repeat(3,1fr); gap:1px; padding:12px; margin:16px 0 20px; overflow:hidden; background:var(--soft); border-radius:8px; }
        .summary-item { padding:6px 10px; text-align:center; border-left:1px solid rgba(107,101,189,.18); }
        .summary-item:last-child { border-left:0; }
        .summary-label { display:block; margin-bottom:5px; color:var(--muted); font-size:11px; }
        .summary-value { font-size:15px; font-weight:800; }
        .taken { color:var(--green); } .given { color:var(--red); }
        .transactions { display:grid; gap:9px; }
        .transaction { overflow:hidden; border:1px solid var(--line); border-radius:7px; background:#fff; break-inside:avoid; }
        .transaction-main { padding:11px 12px; }
        .transaction-top { display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .transaction-date { font-size:13px; font-weight:800; }
        .transaction-type { font-size:13px; font-weight:800; text-align:left; }
        .transaction-note { margin-top:7px; color:var(--muted); font-size:12px; line-height:1.65; }
        .transaction-balance { margin-top:5px; font-size:11px; }
        .source { padding:10px 12px; background:#f7f7f9; border-top:1px solid var(--line); }
        .source-title { color:var(--purple); font-size:13px; font-weight:800; }
        .source-meta { display:flex; flex-wrap:wrap; gap:5px 18px; margin-top:6px; color:var(--muted); font-size:11px; }
        .products { display:grid; gap:7px; margin-top:9px; }
        .product { display:grid; grid-template-columns:46px minmax(0,1fr) auto; align-items:center; gap:9px; padding:7px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; }
        .product.no-image { grid-template-columns:minmax(0,1fr) auto; }
        .product-img,.product-placeholder { width:46px; height:46px; border-radius:5px; }
        .product-img { object-fit:cover; }
        .product-placeholder { display:grid; place-items:center; color:#9ca3af; background:#f3f4f6; font-size:10px; }
        .product-name { font-size:12px; font-weight:800; }
        .product-calc { margin-top:4px; color:var(--muted); font-size:10px; direction:rtl; }
        .product-total { color:var(--ink); font-size:11px; font-weight:800; white-space:nowrap; }
        .empty { padding:38px; color:var(--muted); text-align:center; border:1px dashed var(--line); border-radius:8px; }
        @media print { body { padding:0; background:#fff; } .report { width:100%; padding:0; box-shadow:none; } }
        @media (max-width:600px) {
            body { padding:0; background:#fff; } .report { min-height:100vh; padding:18px 12px; border-radius:0; box-shadow:none; }
            .brand-name { font-size:19px; } .brand img { height:45px; } .meta { grid-template-columns:1fr; }
            .summary { grid-template-columns:1fr; gap:0; } .summary-item { display:flex; justify-content:space-between; align-items:center; border-left:0; border-bottom:1px solid rgba(107,101,189,.13); text-align:right; }
            .summary-item:last-child { border-bottom:0; } .summary-label { margin:0; } .transaction-top { align-items:flex-start; }
            .product { grid-template-columns:52px minmax(0,1fr); } .product-img,.product-placeholder { width:52px; height:52px; } .product-total { grid-column:2; }
        }
    </style>
</head>
<body>
@php
    $showSourceDetails = ($detail_level ?? 'summary') !== 'summary';
    $showProductImages = ($detail_level ?? 'summary') === 'detailed_with_images';
    $currencyLabel = $currency ?? 'شيكل';
@endphp
<main class="report">
    <header class="brand">
        <div><div class="brand-name">DOCTOR BIKE</div><div class="brand-subtitle">تقرير دفتر الديون</div></div>
        <img src="{{ asset('appImages/logo.jpg') }}" alt="Doctor Bike">
    </header>
    <h1>كشف حساب</h1>
    <section class="meta">
        <div><strong>صاحب الحساب:</strong> {{ $person['name'] ?? '—' }}</div>
        <div><strong>رقم الهاتف:</strong> {{ $person['phone'] ?? '—' }}</div>
        <div><strong>الفترة:</strong> {{ $period_label ?? 'جميع المعاملات' }}</div>
        <div><strong>تاريخ الإصدار:</strong> {{ now()->format('Y-m-d H:i') }}</div>
    </section>
    <section class="summary">
        <div class="summary-item"><span class="summary-label">مبالغ مستحقة لنا</span><span class="summary-value taken">{{ number_format($total_taken,2) }} {{ $currencyLabel }}</span></div>
        <div class="summary-item"><span class="summary-label">مبالغ مستحقة علينا</span><span class="summary-value given">{{ number_format($total_given,2) }} {{ $currencyLabel }}</span></div>
        <div class="summary-item"><span class="summary-label">صافي الرصيد</span><span class="summary-value {{ $balance >= 0 ? 'taken' : 'given' }}">{{ number_format($balance,2) }} {{ $currencyLabel }}</span></div>
    </section>

    @if($transactions->isEmpty())
        <div class="empty">لا توجد معاملات</div>
    @else
        <section class="transactions">
        @foreach($transactions as $index => $transaction)
            @php
                $isTaken = $transaction->type === 'taken';
                $sourceDetail = $source_details[$transaction->id] ?? null;
            @endphp
            <article class="transaction">
                <div class="transaction-main">
                    <div class="transaction-top">
                        <div class="transaction-date">{{ $index + 1 }}. {{ $transaction->transaction_date?->format('Y-m-d') ?? '—' }}</div>
                        <div class="transaction-type {{ $isTaken ? 'taken' : 'given' }}">{{ $isTaken ? 'مستحق لنا' : 'مستحق علينا' }} &nbsp; {{ number_format($transaction->amount,2) }} {{ $currencyLabel }}</div>
                    </div>
                    @if(!empty(trim((string) $transaction->note)))<div class="transaction-note">{{ $transaction->note }}</div>@endif
                    <div class="transaction-balance">الرصيد بعد الحركة: <strong>{{ number_format($transaction->balance_after,2) }} {{ $currencyLabel }}</strong></div>
                </div>
                @if($showSourceDetails && $sourceDetail)
                    <div class="source">
                        <div class="source-title">{{ $sourceDetail['title'] }}</div>
                        @if(!empty($sourceDetail['meta']))<div class="source-meta">@foreach($sourceDetail['meta'] as $label => $value)<span><strong>{{ $label }}:</strong> {{ is_numeric($value) ? number_format((float)$value,2) : $value }}</span>@endforeach</div>@endif
                        @if(!empty($sourceDetail['items']))
                            <div class="products">
                            @foreach($sourceDetail['items'] as $item)
                                <div class="product {{ $showProductImages ? '' : 'no-image' }}">
                                    @if($showProductImages && !empty($item['image_url']))<img class="product-img" src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}">@elseif($showProductImages)<div class="product-placeholder">لا صورة</div>@endif
                                    <div><div class="product-name">{{ $item['name'] }}</div><div class="product-calc">{{ number_format($item['quantity'],0) }} × {{ number_format($item['unit_price'],2) }} {{ $currencyLabel }}</div></div>
                                    <div class="product-total">{{ number_format($item['line_total'],2) }} {{ $currencyLabel }}</div>
                                </div>
                            @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </article>
        @endforeach
        </section>
    @endif
</main>
</body>
</html>

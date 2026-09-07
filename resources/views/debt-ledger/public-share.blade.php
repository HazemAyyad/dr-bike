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
        .report { width:min(680px,100%); margin:auto; padding:22px 24px; background:#fff; border-radius:12px; box-shadow:0 8px 30px rgba(31,41,55,.09); }
        .brand { direction:ltr; display:grid; grid-template-columns:130px 1fr 2fr; align-items:center; gap:18px; padding-bottom:10px; margin-bottom:16px; border-bottom:2px solid var(--purple); }
        .brand-title { direction:rtl; color:var(--purple); font-size:21px; font-weight:800; text-align:right; }
        .brand img { width:auto; height:78px; object-fit:contain; }
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
        .ledger-wrap { overflow-x:auto; border:1px solid var(--line); border-radius:7px; }
        .ledger { width:100%; min-width:700px; border-collapse:collapse; font-size:11px; }
        .ledger th,.ledger td { padding:6px 5px; border-left:1px solid var(--line); border-bottom:1px solid var(--line); }
        .ledger th { color:#fff; background:var(--purple); text-align:center; }
        .ledger .statement { width:42%; line-height:1.4; }
        .source-row td { padding:0; background:#f7f7f9; }
        .transaction-top { display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .transaction-date { font-size:13px; font-weight:800; }
        .transaction-type { font-size:13px; font-weight:800; text-align:left; }
        .transaction-note { overflow:hidden; color:var(--muted); font-size:11px; white-space:nowrap; text-overflow:ellipsis; }
        .transaction-balance { font-size:10px; white-space:nowrap; }
        .source { background:#f7f7f9; }
        .products { width:100%; border-collapse:collapse; table-layout:fixed; }
        .products th,.products td { padding:3px 5px; border-bottom:1px solid #e5e7eb; background:#fff; font-size:9px; }
        .products th { color:#344054; background:#e6f1f2; }
        .product-img,.product-placeholder { width:26px; height:26px; border-radius:3px; }
        .product-img { object-fit:cover; }
        .product-placeholder { display:grid; place-items:center; color:#9ca3af; background:#f3f4f6; font-size:10px; }
        .product-name { font-weight:700; }
        .empty { padding:38px; color:var(--muted); text-align:center; border:1px dashed var(--line); border-radius:8px; }
        @media print { body { padding:0; background:#fff; } .report { width:100%; padding:0; box-shadow:none; } }
        @media (max-width:600px) {
            body { padding:0; background:#fff; } .report { min-height:100vh; padding:18px 12px; border-radius:0; box-shadow:none; }
            .brand { grid-template-columns:85px 1fr; } .brand-space { display:none; } .brand-title { font-size:16px; } .brand img { height:54px; } .meta { grid-template-columns:1fr; }
            .summary { grid-template-columns:1fr; gap:0; } .summary-item { display:flex; justify-content:space-between; align-items:center; border-left:0; border-bottom:1px solid rgba(107,101,189,.13); text-align:right; }
            .summary-item:last-child { border-bottom:0; } .summary-label { margin:0; } .transaction-main { grid-template-columns:auto 1fr auto; gap:6px; } .transaction-balance { grid-column:2 / 4; }
            .products { min-width:0; } .product-img,.product-placeholder { width:24px; height:24px; }
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
    <header class="brand"><img src="{{ asset('appImages/logo.jpg') }}" alt="Doctor Bike"><span class="brand-space"></span><div class="brand-title">دكتور بايك - تقرير دفتر الديون</div></header>
    <h1>كشف حساب</h1>
    <section class="meta">
        <div><strong>صاحب الحساب:</strong> {{ $person['name'] ?? '—' }}</div>
        <div><strong>رقم الهاتف:</strong> {{ $person['phone'] ?? '—' }}</div>
        <div><strong>الفترة:</strong> {{ $period_label ?? 'جميع المعاملات' }}</div>
        <div><strong>تاريخ الإصدار:</strong> {{ now()->format('Y-m-d H:i') }}</div>
    </section>
    <section class="summary">
        <div class="summary-item"><span class="summary-label">إجمالي {{ $taken_label ?? 'أخذت' }}</span><span class="summary-value taken">{{ number_format($total_taken,2) }} {{ $currencyLabel }}</span></div>
        <div class="summary-item"><span class="summary-label">إجمالي {{ $given_label ?? 'أعطيت' }}</span><span class="summary-value given">{{ number_format($total_given,2) }} {{ $currencyLabel }}</span></div>
        <div class="summary-item"><span class="summary-label">صافي الرصيد</span><span class="summary-value {{ $balance >= 0 ? 'taken' : 'given' }}">{{ number_format($balance,2) }} {{ $currencyLabel }}</span></div>
    </section>

    @if($transactions->isEmpty())
        <div class="empty">لا توجد معاملات</div>
    @else
        <div class="ledger-wrap"><table class="ledger"><thead><tr><th>#</th><th>التاريخ</th><th class="statement">البيان</th><th>{{ $taken_label ?? 'أخذت' }}</th><th>{{ $given_label ?? 'أعطيت' }}</th><th>الرصيد</th></tr></thead><tbody>
        @foreach($transactions as $index => $transaction)
            @php
                $isTaken = $transaction->type === 'taken';
                $sourceDetail = $source_details[$transaction->id] ?? null;
                $saleNumber = $transaction->source === 'instant_sale'
                    ? (($instant_sale_numbers[$transaction->source_id] ?? null) ?: 'SAL-'.str_pad((string)$transaction->source_id, 7, '0', STR_PAD_LEFT))
                    : null;
                $displayNote = $saleNumber ? 'فاتورة بيع '.$saleNumber : ($transaction->note ?? '—');
            @endphp
            <tr>
                <td class="num">{{ $index + 1 }}</td>
                <td class="num">{{ $transaction->transaction_date?->format('Y-m-d') ?? '—' }}</td>
                <td>{{ !empty(trim((string) $transaction->note)) ? $displayNote : '—' }}</td>
                <td class="num taken">{{ $isTaken ? number_format($transaction->amount,2) : '—' }}</td>
                <td class="num given">{{ !$isTaken ? number_format($transaction->amount,2) : '—' }}</td>
                <td class="num {{ $transaction->balance_after >= 0 ? 'taken' : 'given' }}">{{ number_format($transaction->balance_after,2) }} {{ $currencyLabel }}</td>
            </tr>
                @if($showSourceDetails && $sourceDetail)
                    <tr class="source-row"><td colspan="6"><div class="source">
                        @if(!empty($sourceDetail['items']))
                            <table class="products"><tbody>
                            @foreach($sourceDetail['items'] as $item)
                                <tr>@if($showProductImages)<td class="num" style="width:34px">@if(!empty($item['image_url']))<img class="product-img" src="{{ $item['image_url'] }}" alt="">@else — @endif</td>@endif<td class="product-name">{{ $item['name'] }}</td><td class="num" style="width:42%">{{ number_format($item['quantity'],2) }} × {{ number_format($item['unit_price'],2) }} = <strong>{{ number_format($item['line_total'],2) }} {{ $currencyLabel }}</strong></td></tr>
                            @endforeach
                            </tbody></table>
                        @endif
                    </div></td></tr>
                @endif
        @endforeach
        </tbody></table></div>
    @endif
</main>
</body>
</html>

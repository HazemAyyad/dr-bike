<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AccountingAccount;
use App\Models\AccountingJournalEntry;
use App\Models\AssetLog;
use App\Models\Bill;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtTransaction;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\Expense;
use App\Models\IncomingCheck;
use App\Models\InstantSale;
use App\Models\InventoryCostAllocation;
use App\Models\Log;
use App\Models\OutgoingCheck;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProfitSale;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ReturnModel;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Seller;
use App\Services\AccountingReportService;
use App\Services\CashboxReportService;
use App\Services\DebtLedgerService;
use App\Services\ProductStockService;
use App\Support\ApiImageUrl;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Reports extends Controller
{
    public function __construct(private ?AccountingReportService $accountingReports = null) {}

    public function mainData()
    {
        try {

            $totalDebtsWeOwe = Debt::where('type', 'we owe')
                ->where('status', 'unpaid')
                ->sum('total'); // ديون علينا
            $totalDebtsOwedToUs = Debt::where('type', 'owed to us')
                ->where('status', 'unpaid')
                ->sum('total'); // ديون لنا
            $totalSales = InstantSale::whereNull('maintenance_id')->sum('total_cost'); // اجمالي المبيعات
            $totalBoxes = Box::totalAmount(); // مجموع الصناديق
            $numberOfPeople = Customer::count() + Seller::count(); // عدد الاشخاص
            $numberOfEmployees = EmployeeDetail::count(); // عدد الموظفين

            $todayCompletedEmployeeTasksCount = EmployeeTask::where('status', 'completed')
                ->where('parent_id', null)
                ->whereDate('created_at', Carbon::today())
                ->count();
            $monthCompletedEmployeeTasks = EmployeeTask::where('status', 'completed')
                ->where('parent_id', null)
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count();
            $todayIncompletedEmployeeTasksCount = EmployeeTask::where('status', '!=', 'completed')
                ->where('parent_id', null)
                ->whereDate('created_at', Carbon::today())
                ->count();
            $monthIncompletedEmployeeTasks = EmployeeTask::where('status', '!=', 'completed')
                ->where('parent_id', null)
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count();

            // checks
            $totalOutgoingChecks = OutgoingCheck::totalAmount(); // غير المصروفة
            $totalIncomingChecks = IncomingCheck::totalAmount(); // غير المصروفة
            $totalChecks = $totalIncomingChecks + $totalOutgoingChecks; // مجموع الشيكات
            $profits = $totalSales - ($totalDebtsWeOwe + $totalOutgoingChecks); // صافي الربح

            $totalBills = Bill::where('status', 'finished')->sum('total'); // قيمة المشتريات
            $totalOngoingProjects = Project::where('status', 'ongoing')->count(); // عدد المشاريع
            $totalExpenses = Expense::sum('price'); // اجمالي المصاريف
            $totalReturns = ReturnModel::whereIn('status', ['confirmed', 'pending', 'delivered', 'settled'])->sum('total'); // مردودات المشتريات

            $totalChecksOnUs = OutgoingCheck::sum('total'); // شيكات علينا

            $totalGoods = 0; // تكلفة البضاعة

            $costingService = app(\App\Services\InventoryCostingService::class);
            foreach (Product::query()->with('sizes.colorSizes')->get() as $product) {
                $summary = $costingService->productSummary($product, true, 0);
                $totalGoods += (float) ($summary['inventory_value'] ?? 0);
            }

            $shopCapital = $totalBoxes + $totalChecks + $totalDebtsOwedToUs + $totalGoods; // رأس مال المحل
            $netShopCapital = ($totalBoxes + $totalChecks + $totalDebtsOwedToUs + $totalGoods) - ($totalChecksOnUs + $totalDebtsWeOwe); // رأس مال المحل صافي

            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_debts_we_owe' => $totalDebtsWeOwe,
                    'total_sales' => $totalSales,
                    'profits' => $profits,
                    'total_boxes' => $totalBoxes,
                    'total_checks' => $totalChecks,
                    'total_bills' => $totalBills,
                    'number_of_people' => $numberOfPeople,
                    'number_of_projects' => $totalOngoingProjects,
                    'number_of_employees' => $numberOfEmployees,
                    'total_expenses' => $totalExpenses,
                    'total_returns' => $totalReturns,
                    'total_goods' => $totalGoods,
                    'shop_capital' => $shopCapital,
                    'net_shop_capital' => $netShopCapital,
                    'completed_employee_tasks_daily' => $todayCompletedEmployeeTasksCount,
                    'incompleted_employee_tasks_daily' => $todayIncompletedEmployeeTasksCount,
                    'completed_employee_tasks_monthly' => $monthCompletedEmployeeTasks,
                    'incompleted_employee_tasks_monthly' => $monthIncompletedEmployeeTasks,

                ],
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function getReport(Request $request)
    {
        try {
            $request->validate([
                'type' => [
                    'required',
                    'string',
                    Rule::in(['debts', 'instant_sales', 'employee_tasks', 'boxes', 'checks', 'bills', 'people', 'projects', 'employees', 'expenses', 'returns']),
                ],

                'from_date' => ['nullable', 'date'],
                'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            ]);

            if ($request->type === 'people') {
                $logs = Log::whereIn('type', ['customers', 'sellers'])
                    ->where('is_canceled', 0)
                    ->when($request->from_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '>=', $request->from_date);
                    })
                    ->when($request->to_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '<=', $request->to_date);
                    })
                    ->get();
            } elseif ($request->type === 'checks') {
                $logs = Log::whereIn('type', ['incoming_checks', 'outgoing_checks'])
                    ->where('is_canceled', 0)
                    ->when($request->from_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '>=', $request->from_date);
                    })
                    ->when($request->to_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '<=', $request->to_date);
                    })
                    ->get();
            }
            // elseif($request->type === 'employee_tasks_daily'){
            //     $logs = Log::where('type','employee_tasks')->where('is_canceled',0)
            //     ->whereDate('created_at', Carbon::today())->get();

            // }
            // elseif($request->type === 'employee_tasks_monthly'){
            //     $logs = Log::where('type','employee_tasks')->where('is_canceled',0)
            //     ->whereMonth('created_at', Carbon::now()->month)
            //     ->whereYear('created_at', Carbon::now()->year)
            //     ->get();

            // }

            else {
                $logs = Log::where('type', $request->type)
                    ->where('is_canceled', 0)
                    ->when($request->from_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '>=', $request->from_date);
                    })
                    ->when($request->to_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '<=', $request->to_date);
                    })
                    ->get();
            }

            // 🔹 First render HTML from the Blade
            $reportHtml = view('pdf.report', [
                'logs' => $logs,
            ])->render();

            // 🔹 Fix Arabic text
            $arabic = new Arabic;
            $positions = $arabic->arIdentify($reportHtml);

            for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
                $utf8ar = $arabic->utf8Glyphs(
                    substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
                );
                $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
            }

            // 🔹 Load fixed HTML into PDF
            $pdf = Pdf::loadHTML($reportHtml);

            return $pdf->download('report.pdf');

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),

            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function salesReport(Request $request)
    {
        try {
            $request->validate([
                'period' => [
                    'nullable',
                    'string',
                    Rule::in(['today', 'week', 'month', 'quarter', 'half_year', 'year', 'custom']),
                ],
                'from_date' => ['nullable', 'date'],
                'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
                'status' => ['nullable', 'string', Rule::in(['all', 'active', 'cancelled'])],
                'payment_type' => ['nullable', 'string', Rule::in(['all', 'cash', 'debt', 'mixed'])],
                'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            ]);

            [$from, $to, $period] = $this->resolveReportPeriod($request);
            $status = $request->input('status', 'all');
            $paymentType = $request->input('payment_type', 'all');

            $baseQuery = InstantSale::query()
                ->with(['product:id,nameAr,nameEng', 'buyerCustomer:id,name,phone', 'seller:id,name,phone', 'paymentBox:id,name'])
                ->whereNull('parent_id')
                ->whereNull('maintenance_id')
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->when($request->filled('box_id'), fn ($query) => $query->where('payment_box_id', $request->box_id))
                ->when($status === 'active', function ($query) {
                    $query->where(function ($nested) {
                        $nested->whereNull('status')->orWhere('status', '!=', 'cancelled');
                    })->whereNull('cancelled_at');
                })
                ->when($status === 'cancelled', function ($query) {
                    $query->where(function ($nested) {
                        $nested->where('status', 'cancelled')->orWhereNotNull('cancelled_at');
                    });
                });

            $sales = $baseQuery
                ->orderByDesc('created_at')
                ->get();

            $rows = $sales->map(function (InstantSale $sale) {
                $total = (float) ($sale->total_cost ?? 0);
                $discount = (float) ($sale->discount ?? 0);
                $paid = (float) ($sale->payment_box_value ?? 0);
                $remaining = max($total - $paid, 0);
                $isCancelled = $sale->isCancelled();

                return [
                    'id' => $sale->id,
                    'serial_number' => $sale->serial_number,
                    'date' => optional($sale->created_at)->toDateTimeString(),
                    'status' => $isCancelled ? 'cancelled' : ($sale->status ?: 'active'),
                    'sale_kind' => $sale->sale_kind ?: 'regular',
                    'source' => 'instant_sale',
                    'buyer_type' => $sale->buyer_type,
                    'buyer_id' => $sale->buyer_id,
                    'buyer_name' => $sale->buyer_name ?: optional($sale->buyerCustomer)->name ?: optional($sale->seller)->name ?: 'زبون نقدي',
                    'buyer_phone' => $sale->buyer_phone ?: optional($sale->buyerCustomer)->phone ?: optional($sale->seller)->phone,
                    'product_name' => optional($sale->product)->nameAr ?: optional($sale->product)->nameEng,
                    'quantity' => (float) ($sale->quantity ?? 0),
                    'unit_price' => (float) ($sale->cost ?? 0),
                    'total' => $total,
                    'discount' => $discount,
                    'paid' => $paid,
                    'remaining' => $remaining,
                    'payment_type' => $this->salesReportPaymentType($total, $paid),
                    'box_id' => $sale->payment_box_id,
                    'box_name' => $sale->payment_box_name ?: optional($sale->paymentBox)->name,
                    'notes' => $sale->notes,
                ];
            });

            if (Schema::hasTable('profit_sales')) {
                $profitQuery = ProfitSale::query()
                    ->with(['customer:id,name,phone', 'seller:id,name,phone', 'paymentBox:id,name'])
                    ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->when($request->filled('box_id'), fn ($query) => $query->where('payment_box_id', $request->box_id))
                    ->when($status === 'active', function ($query) {
                        $query->where(function ($nested) {
                            $nested->whereNull('status')->orWhere('status', '!=', 'cancelled');
                        })->whereNull('cancelled_at');
                    })
                    ->when($status === 'cancelled', function ($query) {
                        $query->where(function ($nested) {
                            $nested->where('status', 'cancelled')->orWhereNotNull('cancelled_at');
                        });
                    });

                $profitRows = $profitQuery->get()->map(function (ProfitSale $sale) {
                    $total = (float) ($sale->total_cost ?? 0);
                    $paid = (float) ($sale->payment_box_value ?? 0);
                    $isCancelled = $sale->isCancelled();

                    return [
                        'id' => $sale->id,
                        'serial_number' => 'PRF-'.$sale->id,
                        'date' => optional($sale->created_at)->toDateTimeString(),
                        'status' => $isCancelled ? 'cancelled' : ($sale->status ?: 'active'),
                        'sale_kind' => 'profit_sale',
                        'source' => 'profit_sale',
                        'buyer_type' => $sale->buyer_type,
                        'buyer_id' => $sale->customer_id ?: $sale->seller_id,
                        'buyer_name' => $sale->buyer_name ?: optional($sale->customer)->name ?: optional($sale->seller)->name ?: 'بدون زبون',
                        'buyer_phone' => optional($sale->customer)->phone ?: optional($sale->seller)->phone,
                        'product_name' => 'بيع ربحي',
                        'quantity' => 1.0,
                        'unit_price' => $total,
                        'total' => $total,
                        'discount' => 0.0,
                        'paid' => $paid,
                        'remaining' => max($total - $paid, 0),
                        'payment_type' => $this->salesReportPaymentType($total, $paid),
                        'box_id' => $sale->payment_box_id,
                        'box_name' => $sale->payment_box_name ?: optional($sale->paymentBox)->name,
                        'notes' => $sale->notes,
                    ];
                });
                $rows = $rows->concat($profitRows);
            }

            $rows = $rows->filter(function (array $row) use ($paymentType) {
                return $paymentType === 'all' || $row['payment_type'] === $paymentType;
            })->sortByDesc('date')->values();

            $activeRows = $rows->where('status', '!=', 'cancelled');
            $cancelledRows = $rows->where('status', 'cancelled');

            return response()->json([
                'status' => 'success',
                'data' => [
                    'period' => [
                        'key' => $period,
                        'from_date' => $from->toDateString(),
                        'to_date' => $to->toDateString(),
                    ],
                    'filters' => [
                        'status' => $status,
                        'payment_type' => $paymentType,
                        'box_id' => $request->box_id,
                    ],
                    'summary' => [
                        'invoice_count' => $rows->count(),
                        'active_invoice_count' => $activeRows->count(),
                        'cancelled_invoice_count' => $cancelledRows->count(),
                        'gross_sales' => round($activeRows->sum('total'), 3),
                        'cancelled_sales' => round($cancelledRows->sum('total'), 3),
                        'discounts' => round($activeRows->sum('discount'), 3),
                        'cash_paid' => round($activeRows->sum('paid'), 3),
                        'debt_remaining' => round($activeRows->sum('remaining'), 3),
                    ],
                    'rows' => $rows,
                ],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function analyticsDashboard(Request $request)
    {
        $request->validate([
            'period' => ['nullable', 'string', Rule::in(['today', 'week', 'month', 'quarter', 'half_year', 'year', 'custom'])],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        [$from, $to, $period] = $this->resolveReportPeriod($request);
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $duration = $from->diffInSeconds($to) + 1;
        $previousTo = $from->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subSeconds($duration - 1);

        $current = $this->analyticsPeriodMetrics($from, $to);
        $previous = $this->analyticsPeriodMetrics($previousFrom, $previousTo);
        $series = $this->analyticsSeries($from, $to);

        $debtBalances = collect($this->ledgerBalanceIndex())->values();
        $debts = collect(DebtLedgerService::CURRENCIES)->flatMap(function (string $currency) use ($debtBalances) {
            $forUs = $debtBalances->sum(fn (array $balances) => abs(min((float) ($balances[$currency]['balance'] ?? 0), 0)));
            $onUs = $debtBalances->sum(fn (array $balances) => max((float) ($balances[$currency]['balance'] ?? 0), 0));
            if (abs($forUs) < 0.0001 && abs($onUs) < 0.0001) {
                return [];
            }

            return [
                ['key' => 'for_us_'.$currency, 'label' => 'ديون لنا - '.$currency, 'value' => round($forUs, 3)],
                ['key' => 'on_us_'.$currency, 'label' => 'ديون علينا - '.$currency, 'value' => round($onUs, 3)],
            ];
        })->values();
        if ($debts->isEmpty()) {
            $debts = collect([
                ['key' => 'for_us_شيكل', 'label' => 'ديون لنا - شيكل', 'value' => 0.0],
                ['key' => 'on_us_شيكل', 'label' => 'ديون علينا - شيكل', 'value' => 0.0],
            ]);
        }
        $openIncomingChecks = IncomingCheck::query()->where('status', 'not_cashed')->get(['total', 'currency']);
        $openOutgoingChecks = OutgoingCheck::query()->where('status', 'not_cashed')->get(['total', 'currency']);
        $checks = collect(DebtLedgerService::CURRENCIES)->flatMap(function (string $currency) use ($openIncomingChecks, $openOutgoingChecks) {
            $incoming = $openIncomingChecks
                ->filter(fn (IncomingCheck $check) => $this->reportCurrency($check->currency) === $currency)
                ->sum('total');
            $outgoing = $openOutgoingChecks
                ->filter(fn (OutgoingCheck $check) => $this->reportCurrency($check->currency) === $currency)
                ->sum('total');
            if (abs((float) $incoming) < 0.0001 && abs((float) $outgoing) < 0.0001) {
                return [];
            }

            return [
                ['key' => 'incoming_'.$currency, 'label' => 'واردة - '.$currency, 'value' => round((float) $incoming, 3)],
                ['key' => 'outgoing_'.$currency, 'label' => 'صادرة - '.$currency, 'value' => round((float) $outgoing, 3)],
            ];
        })->values();
        if ($checks->isEmpty()) {
            $checks = collect([
                ['key' => 'incoming_شيكل', 'label' => 'واردة - شيكل', 'value' => 0.0],
                ['key' => 'outgoing_شيكل', 'label' => 'صادرة - شيكل', 'value' => 0.0],
            ]);
        }

        $stockService = app(ProductStockService::class);
        $costingService = app(\App\Services\InventoryCostingService::class);
        $inventory = Product::query()
            ->with([
                'sizes.colorSizes',
                'normalImages' => fn ($query) => $query->orderBy('id'),
                'viewImages' => fn ($query) => $query->orderBy('id'),
                'image3d' => fn ($query) => $query->orderBy('id'),
            ])
            ->get()
            ->map(function (Product $product) use ($stockService, $costingService) {
                $stock = (float) $stockService->resolveDisplayStock($product);
                $cost = $costingService->productSummary($product, true, 0);
                $inventoryValue = (float) ($cost['inventory_value'] ?? 0);

                return [
                    'id' => $product->id,
                    'label' => $product->nameAr ?: $product->nameEng ?: (string) $product->id,
                    'image' => ApiImageUrl::normalize($product->viewImages->first()?->imageUrl
                        ?? $product->normalImages->first()?->imageUrl
                        ?? $product->image3d->first()?->imageUrl),
                    'images' => collect([
                        $product->viewImages->first()?->imageUrl,
                        $product->normalImages->first()?->imageUrl,
                        $product->image3d->first()?->imageUrl,
                    ])->filter()->map(fn ($image) => ApiImageUrl::normalize($image))->values(),
                    'quantity' => round($stock, 3),
                    'value' => round($inventoryValue, 3),
                    'cost_coverage_complete' => (bool) ($cost['cost_coverage_complete'] ?? false),
                ];
            });
        $soldByProduct = InstantSale::query()
            ->whereNotNull('product_id')
            ->whereBetween('created_at', [$from, $to])
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->whereNull('cancelled_at')
            ->select('product_id', DB::raw('SUM(quantity) as sold_quantity'))
            ->groupBy('product_id')
            ->pluck('sold_quantity', 'product_id');
        $inventory = $inventory->map(function (array $row) use ($soldByProduct) {
            $row['sold_quantity'] = (float) ($soldByProduct[$row['id']] ?? 0);

            return $row;
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'key' => $period,
                    'from_date' => $from->toDateString(),
                    'to_date' => $to->toDateString(),
                    'previous_from_date' => $previousFrom->toDateString(),
                    'previous_to_date' => $previousTo->toDateString(),
                ],
                'generated_at' => Carbon::now()->toIso8601String(),
                'summary' => [
                    $this->analyticsSummaryItem('sales', $current['net_sales'], $previous['net_sales']),
                    array_merge(
                        $this->analyticsSummaryItem('net_profit', $current['net_profit'], $previous['net_profit']),
                        [
                            'net_sales' => round($current['net_sales'], 3),
                            'cost_of_sales' => round($current['cost_of_sales'], 3),
                            'expenses' => round($current['expenses'], 3),
                        ]
                    ),
                    $this->analyticsSummaryItem('expenses', $current['expenses'], $previous['expenses']),
                    $this->analyticsSummaryItem('cash_collected', $current['cash_collected'], $previous['cash_collected']),
                ],
                'sales_profit_series' => $series->map(fn ($row) => [
                    'label' => $row['label'],
                    'sales' => round($row['sales'], 3),
                    'profit' => round($row['profit'], 3),
                ])->values(),
                'operations_series' => $series->map(fn ($row) => [
                    'label' => $row['label'],
                    'sales' => round($row['sales'], 3),
                    'expenses' => round($row['expenses'], 3),
                    'purchases' => round($row['purchases'], 3),
                ])->values(),
                'payment_mix' => [
                    ['key' => 'cash', 'label' => 'نقدي', 'value' => round($current['payment_cash'], 3)],
                    ['key' => 'debt', 'label' => 'دين', 'value' => round($current['payment_debt'], 3)],
                    ['key' => 'mixed', 'label' => 'مختلط', 'value' => round($current['payment_mixed'], 3)],
                ],
                'debts' => $debts,
                'checks' => $checks,
                'inventory' => [
                    'products_count' => $inventory->count(),
                    'quantity' => round($inventory->sum('quantity'), 3),
                    'value' => round($inventory->sum('value'), 3),
                    'low_stock_count' => $inventory->where('quantity', '>', 0)->where('quantity', '<=', 3)->count(),
                    'top_value' => $inventory->sortByDesc('value')->take(7)->values(),
                    'low_stock' => $inventory->where('quantity', '>', 0)->where('quantity', '<=', 3)->sortBy('quantity')->values(),
                    'out_of_stock' => $inventory->where('quantity', 0)->values(),
                    'negative_stock' => $inventory->where('quantity', '<', 0)->sortBy('quantity')->values(),
                    'best_sellers' => $inventory->where('sold_quantity', '>', 0)->sortByDesc('sold_quantity')->take(50)->values(),
                    'least_sellers' => $inventory->sortBy('sold_quantity')->take(50)->values(),
                ],
                'tasks' => [
                    ['key' => 'completed', 'label' => 'منجزة', 'value' => $current['tasks_completed']],
                    ['key' => 'pending', 'label' => 'غير منجزة', 'value' => $current['tasks_pending']],
                ],
                'quality' => [
                    'cost_coverage_percent' => $current['cost_coverage_percent'],
                    'costed_sales_count' => $current['costed_sales_count'],
                    'uncosted_sales_count' => $current['uncosted_sales_count'],
                    'profit_complete' => $current['uncosted_sales_count'] === 0,
                    'message' => $current['uncosted_sales_count'] === 0
                        ? 'تكلفة المبيعات مكتملة للفترة.'
                        : 'صافي الربح غير نهائي لوجود فواتير بلا تكلفة مخزون مكتملة.',
                ],
            ],
        ]);
    }

    private function analyticsPeriodMetrics(Carbon $from, Carbon $to): array
    {
        $sales = $this->analyticsSales($from, $to);

        // instant_sales.total_cost is persisted after discount. Keep discounts
        // informational and never subtract them from the stored net total again.
        $grossSales = (float) $sales->sum('total');
        $discounts = (float) $sales->sum('discount');
        $directReturns = SalesReturn::query()->whereIn('return_type', ['direct', 'partial'])->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);
        $directReturnTotal = (float) (clone $directReturns)->sum('total_amount');
        $directReturnCost = (float) SalesReturnItem::query()->whereHas('salesReturn', fn ($query) => $query
            ->whereIn('return_type', ['direct', 'partial'])->where('status', 'completed')->whereBetween('completed_at', [$from, $to]))->sum('inventory_total_cost');
        $netSales = $this->financialNetSales($grossSales, $directReturnTotal);
        $cost = (float) $sales->sum('cost') - $directReturnCost;
        $expenses = $this->periodExpenseTotal($from, $to);
        $purchases = (float) Bill::where('status', 'finished')->whereBetween('created_at', [$from, $to])->sum('total');
        $purchaseReturns = (float) ReturnModel::whereIn('status', ['confirmed', 'delivered', 'settled'])
            ->whereBetween('created_at', [$from, $to])->sum('total');

        $paymentMix = ['cash' => 0.0, 'debt' => 0.0, 'mixed' => 0.0];
        foreach ($sales as $sale) {
            $total = max((float) $sale['total'], 0);
            $paid = min(max((float) $sale['paid'], 0), $total);
            $paymentMix[$this->salesReportPaymentType($total, $paid)] += $total;
        }

        $cashRefunds = Schema::hasColumn('sales_returns', 'cash_refund_amount')
            ? (float) (clone $directReturns)->sum('cash_refund_amount')
            : 0.0;

        return [
            'net_sales' => $netSales,
            'cash_collected' => $this->periodCashCollected($from, $to, (float) $sales->sum('paid') - $cashRefunds),
            'expenses' => $expenses,
            'purchases' => $purchases,
            'purchase_returns' => $purchaseReturns,
            'cost_of_sales' => $cost,
            'net_profit' => $this->analyticsNetProfit($netSales, $cost, $expenses),
            'costed_sales_count' => $sales->where('cost_complete', true)->count(),
            'uncosted_sales_count' => $sales->where('cost_complete', false)->count(),
            'cost_coverage_percent' => $sales->isEmpty()
                ? 100.0
                : round(($sales->where('cost_complete', true)->count() / $sales->count()) * 100, 1),
            'payment_cash' => $paymentMix['cash'],
            'payment_debt' => $paymentMix['debt'],
            'payment_mixed' => $paymentMix['mixed'],
            'tasks_completed' => EmployeeTask::where('status', 'completed')->whereNull('parent_id')->whereBetween('created_at', [$from, $to])->count(),
            'tasks_pending' => EmployeeTask::where('status', '!=', 'completed')->whereNull('parent_id')->whereBetween('created_at', [$from, $to])->count(),
        ];
    }

    private function analyticsSeries(Carbon $from, Carbon $to)
    {
        $days = max($from->diffInDays($to), 1);
        $format = $days > 120 ? 'Y-m' : 'Y-m-d';
        $labelFormat = $days > 120 ? 'm/Y' : 'd/m';
        $cursor = $from->copy()->startOfDay();
        $points = collect();

        while ($cursor->lte($to)) {
            $key = $cursor->format($format);
            $points->put($key, ['label' => $cursor->format($labelFormat), 'sales' => 0.0, 'profit' => 0.0, 'expenses' => 0.0, 'purchases' => 0.0]);
            $cursor = $days > 120 ? $cursor->addMonth()->startOfMonth() : $cursor->addDay();
        }

        $sales = $this->analyticsSales($from, $to);
        foreach ($sales as $sale) {
            $key = $sale['created_at']->format($format);
            if (! $points->has($key)) {
                continue;
            }
            $net = (float) $sale['total'];
            $row = $points[$key];
            $row['sales'] += $net;
            $row['profit'] += $net - (float) $sale['cost'];
            $points->put($key, $row);
        }

        $returns = SalesReturn::query()
            ->with('items:id,sales_return_id,inventory_total_cost')
            ->whereIn('return_type', ['direct', 'partial'])
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->get(['id', 'completed_at', 'total_amount']);
        foreach ($returns as $return) {
            $key = $return->completed_at->format($format);
            if (! $points->has($key)) {
                continue;
            }
            $returnTotal = (float) $return->total_amount;
            $returnCost = (float) $return->items->sum('inventory_total_cost');
            $row = $points[$key];
            $row['sales'] -= $returnTotal;
            $row['profit'] -= $returnTotal - $returnCost;
            $points->put($key, $row);
        }

        foreach ($this->periodExpenses($from, $to) as $expense) {
            $key = $expense['date']->format($format);
            if ($points->has($key)) {
                $row = $points[$key];
                $row['expenses'] += (float) $expense['amount'];
                $row['profit'] -= (float) $expense['amount'];
                $points->put($key, $row);
            }
        }
        foreach (Bill::where('status', 'finished')->whereBetween('created_at', [$from, $to])->get(['created_at', 'total']) as $bill) {
            $key = $bill->created_at->format($format);
            if ($points->has($key)) {
                $row = $points[$key];
                $row['purchases'] += (float) $bill->total;
                $points->put($key, $row);
            }
        }

        return $points->values();
    }

    private function analyticsSales(Carbon $from, Carbon $to)
    {
        $instantHasPaid = Schema::hasColumn('instant_sales', 'payment_box_value');
        $instantHasCost = Schema::hasColumn('instant_sales', 'inventory_total_cost');
        $instantColumns = ['id', 'created_at', 'total_cost', 'discount'];
        if ($instantHasPaid) {
            $instantColumns[] = 'payment_box_value';
        }
        if ($instantHasCost) {
            $instantColumns[] = 'inventory_total_cost';
        }

        $instantQuery = InstantSale::query()
            ->whereNull('parent_id')
            ->whereBetween('created_at', [$from, $to]);
        if (Schema::hasColumn('instant_sales', 'maintenance_id')) {
            $instantQuery->whereNull('maintenance_id');
        }
        if (Schema::hasColumn('instant_sales', 'status')) {
            $instantQuery->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'));
        }
        if (Schema::hasColumn('instant_sales', 'cancelled_at')) {
            $instantQuery->whereNull('cancelled_at');
        }

        $instantRows = $instantQuery->get($instantColumns);
        $parentIds = $instantRows->pluck('id')->map(fn ($id) => (int) $id)->values();
        $costByInvoice = collect();

        if ($parentIds->isNotEmpty()) {
            $lineColumns = ['id', 'parent_id', 'product_id', 'quantity'];
            if ($instantHasCost) {
                $lineColumns[] = 'inventory_total_cost';
            }
            $costByInvoice = InstantSale::query()
                ->where(function ($query) use ($parentIds) {
                    $query->whereIn('id', $parentIds)->orWhereIn('parent_id', $parentIds);
                })
                ->get($lineColumns)
                ->groupBy(fn (InstantSale $line) => (int) ($line->parent_id ?: $line->id))
                ->map(function ($lines) use ($instantHasCost) {
                    $costed = $lines->filter(fn (InstantSale $line) => ! $instantHasCost || $line->inventory_total_cost !== null)->count();

                    return [
                        'cost' => (float) $lines->sum(fn (InstantSale $line) => $this->analyticsLineCost(
                            $instantHasCost ? $line->inventory_total_cost : null
                        )),
                        'complete' => $instantHasCost && $costed === $lines->count(),
                    ];
                });
        }

        $instant = $instantRows->map(fn (InstantSale $sale) => [
            'source' => 'instant_sale',
            'created_at' => $sale->created_at,
            'total' => (float) ($sale->total_cost ?? 0),
            'discount' => (float) ($sale->discount ?? 0),
            'paid' => $instantHasPaid
                ? (float) ($sale->payment_box_value ?? 0)
                : (float) ($sale->total_cost ?? 0),
            'cost' => (float) ($costByInvoice[(int) $sale->id]['cost'] ?? 0),
            'cost_complete' => (bool) ($costByInvoice[(int) $sale->id]['complete'] ?? false),
        ]);

        if (! Schema::hasTable('profit_sales')) {
            return $instant;
        }

        $profitHasPaid = Schema::hasColumn('profit_sales', 'payment_box_value');
        $profitColumns = ['created_at', 'total_cost'];
        if ($profitHasPaid) {
            $profitColumns[] = 'payment_box_value';
        }
        $profitQuery = ProfitSale::query()->whereBetween('created_at', [$from, $to]);
        if (Schema::hasColumn('profit_sales', 'status')) {
            $profitQuery->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'));
        }
        if (Schema::hasColumn('profit_sales', 'cancelled_at')) {
            $profitQuery->whereNull('cancelled_at');
        }

        $profit = $profitQuery->get($profitColumns)->map(fn (ProfitSale $sale) => [
            'source' => 'profit_sale',
            'created_at' => $sale->created_at,
            'total' => (float) ($sale->total_cost ?? 0),
            'discount' => 0.0,
            'paid' => $profitHasPaid
                ? (float) ($sale->payment_box_value ?? 0)
                : (float) ($sale->total_cost ?? 0),
            'cost' => 0.0,
            'cost_complete' => true,
        ]);

        return $instant->concat($profit)->values();
    }

    private function analyticsSummaryItem(string $key, float $current, float $previous): array
    {
        $change = $previous == 0.0 ? ($current == 0.0 ? 0.0 : 100.0) : (($current - $previous) / abs($previous)) * 100;

        return ['key' => $key, 'value' => round($current, 3), 'previous_value' => round($previous, 3), 'change_percent' => round($change, 1)];
    }

    private function analyticsLineCost($snapshotCost): float
    {
        return $snapshotCost !== null ? (float) $snapshotCost : 0.0;
    }

    private function financialNetSales(float $storedSalesTotal, float $completedReturns): float
    {
        return $storedSalesTotal - $completedReturns;
    }

    private function allocatedProductRevenue(
        float $storedInvoiceTotal,
        float $invoiceDiscount,
        float $lineGross
    ): float {
        $subtotalBeforeDiscount = $storedInvoiceTotal + max($invoiceDiscount, 0);
        if ($subtotalBeforeDiscount <= 0 || $lineGross <= 0) {
            return 0.0;
        }

        return $lineGross * (max($storedInvoiceTotal, 0) / $subtotalBeforeDiscount);
    }

    private function analyticsNetProfit(float $netSales, float $costOfSales, float $expenses): float
    {
        return $netSales - $costOfSales - $expenses;
    }

    public function reportData(Request $request)
    {
        try {
            $request->validate([
                'type' => [
                    'required',
                    'string',
                    Rule::in([
                        'balances',
                        'statement',
                        'checks',
                        'boxes',
                        'daily_boxes',
                        'inventory',
                        'income',
                        'sales_returns',
                        'product_profit',
                        'trial_balance',
                        'general_ledger',
                        'balance_sheet',
                        'cash_flow',
                        'aging_receivable',
                        'aging_payable',
                        'journal',
                    ]),
                ],
                'period' => [
                    'nullable',
                    'string',
                    Rule::in(['today', 'week', 'month', 'quarter', 'half_year', 'year', 'custom']),
                ],
                'from_date' => ['nullable', 'date'],
                'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
                'person_type' => ['nullable', 'string', Rule::in(['customer', 'seller'])],
                'person_id' => ['nullable', 'integer'],
                'box_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('boxes', 'id')->where(function ($query) {
                        $query->where('is_shown', 1)
                            ->where(function ($types) {
                                $types->whereNull('type')->orWhereNotIn('type', [
                                    config('sales_daily.box_type', 'daily_sales'),
                                    config('sales_orders.daily_box.type', 'daily_sales_orders'),
                                    config('maintenance_daily.box_type', 'daily_maintenance'),
                                ]);
                            });
                    }),
                ],
                'check_direction' => ['nullable', 'string', Rule::in(['all', 'incoming', 'outgoing'])],
                'currency' => ['nullable', 'string', Rule::in(['شيكل', 'دولار', 'دينار', 'NIS', 'ILS', 'USD', 'JOD'])],
                'account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            ]);

            [$from, $to, $period] = $this->resolveReportPeriod($request);
            $payload = match ($request->type) {
                'balances' => $this->balancesReportPayload(),
                'statement' => $this->statementReportPayload($request, $from, $to),
                'checks' => $this->checksReportPayload($request, $from, $to),
                'boxes' => app(CashboxReportService::class)->statement(
                    $request->filled('box_id') ? (int) $request->box_id : null,
                    $from,
                    $to,
                ),
                'daily_boxes' => app(CashboxReportService::class)->dailySessions($from, $to),
                'inventory' => $this->inventoryReportPayload($from, $to),
                'income' => $this->incomeReportPayload($from, $to, $request),
                'sales_returns' => $this->salesReturnsReportPayload($from, $to),
                'product_profit' => $this->productProfitReportPayload($from, $to),
                'trial_balance', 'general_ledger', 'balance_sheet', 'cash_flow',
                'aging_receivable', 'aging_payable', 'journal' => $this->accountingReportPayload($request, $from, $to),
            };

            return response()->json([
                'status' => 'success',
                'data' => array_merge([
                    'period' => [
                        'key' => $period,
                        'from_date' => $from->toDateString(),
                        'to_date' => $to->toDateString(),
                    ],
                ], $payload),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            $accountingTypes = [
                'trial_balance', 'general_ledger', 'balance_sheet', 'cash_flow',
                'aging_receivable', 'aging_payable', 'journal',
            ];
            $isAccountingReport = in_array((string) $request->input('type'), $accountingTypes, true);
            if ($isAccountingReport) {
                \Illuminate\Support\Facades\Log::error('Accounting report request failed.', [
                    'type' => $request->input('type'),
                    'user_id' => $request->user()?->id,
                    'exception' => $e,
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => $isAccountingReport
                    ? 'تعذر تحميل التقرير المحاسبي. حاول مجددًا، وإن استمرت المشكلة راجع سجل الخادم.'
                    : __('messages.something_wrong'),
            ], 200);
        }
    }

    public function reportPeople(Request $request)
    {
        try {
            $scope = (string) $request->query('scope', 'all');
            if (! in_array($scope, ['all', 'people', 'boxes', 'accounts'], true)) {
                $scope = 'all';
            }

            $data = [];
            if (in_array($scope, ['all', 'people'], true)) {
                $balanceIndex = $this->ledgerBalanceIndex();
                $customers = Customer::query()
                    ->select(['id', 'name', 'phone'])
                    ->orderBy('name')
                    ->get()
                    ->map(function (Customer $person) use ($balanceIndex) {
                        $balances = $balanceIndex['customer:'.$person->id] ?? $this->emptyCurrencyBalances();

                        return [
                            'id' => $person->id,
                            'type' => 'customer',
                            'type_label' => 'زبون',
                            'name' => $person->name,
                            'phone' => $person->phone,
                            // Keep the old field for compatibility, but make its
                            // currency explicit instead of mixing currencies.
                            'balance' => round((float) $balances['شيكل']['balance'], 3),
                            'balance_currency' => 'شيكل',
                            'balances' => $balances,
                        ];
                    });

                $sellers = Seller::query()
                    ->select(['id', 'name', 'phone'])
                    ->orderBy('name')
                    ->get()
                    ->map(function (Seller $person) use ($balanceIndex) {
                        $balances = $balanceIndex['seller:'.$person->id] ?? $this->emptyCurrencyBalances();

                        return [
                            'id' => $person->id,
                            'type' => 'seller',
                            'type_label' => 'مورد',
                            'name' => $person->name,
                            'phone' => $person->phone,
                            'balance' => round((float) $balances['شيكل']['balance'], 3),
                            'balance_currency' => 'شيكل',
                            'balances' => $balances,
                        ];
                    });
                $data['people'] = $customers->merge($sellers)->values();
            }
            if (in_array($scope, ['all', 'boxes'], true)) {
                $data['boxes'] = app(CashboxReportService::class)
                    ->permanentBoxes()
                    ->map(fn (Box $box) => [
                        'id' => $box->id,
                        'name' => $box->name,
                        'currency' => $box->currency,
                    ])
                    ->values();
            }
            if (in_array($scope, ['all', 'accounts'], true)) {
                $data['accounts'] = Schema::hasTable('accounting_accounts')
                    ? AccountingAccount::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'type'])
                    : collect();
            }

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function accountingReportPayload(Request $request, Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('accounting_accounts')
            || ! Schema::hasTable('accounting_journal_entries')
            || ! Schema::hasTable('accounting_journal_lines')) {
            return $this->accountingUnavailablePayload();
        }

        $service = $this->accountingReports ?? app(AccountingReportService::class);
        $currency = $this->reportCurrency($request->input('currency', 'شيكل'));
        $type = (string) $request->type;

        if ($type === 'general_ledger' && ! $request->filled('account_id')) {
            return [
                'title' => 'دفتر الأستاذ العام',
                'summary' => [
                    ['title' => 'الحساب', 'value' => 'اختر حسابًا من الفلاتر'],
                    ['title' => 'العملة', 'value' => $currency],
                ],
                'columns' => ['التاريخ', 'رقم القيد', 'البيان', 'مدين', 'دائن', 'الرصيد'],
                'rows' => collect(),
                'quality' => $service->quality(),
            ];
        }

        $report = match ($type) {
            'trial_balance' => $service->trialBalance($from, $to, $currency),
            'general_ledger' => $service->generalLedger((int) $request->account_id, $from, $to, $currency),
            'balance_sheet' => $service->balanceSheet($to, $currency),
            'cash_flow' => $service->cashFlow($from, $to, $currency),
            'aging_receivable' => $service->aging($to, 'receivable', $currency),
            'aging_payable' => $service->aging($to, 'payable', $currency),
            'journal' => [
                'title' => 'دفتر اليومية',
                'currency' => $currency,
                'entries' => $service->journalExport($from, $to, $currency),
                'quality' => $service->quality(),
            ],
        };

        return match ($type) {
            'trial_balance' => $this->trialBalanceUiPayload($report),
            'general_ledger' => $this->generalLedgerUiPayload($report),
            'balance_sheet' => $this->balanceSheetUiPayload($report),
            'cash_flow' => $this->cashFlowUiPayload($report),
            'aging_receivable', 'aging_payable' => $this->agingUiPayload($report),
            'journal' => $this->journalUiPayload($report),
        };
    }

    private function trialBalanceUiPayload(array $report): array
    {
        $summary = $report['summary'];

        return [
            'title' => $report['title'],
            'summary' => [
                ['title' => 'العملة', 'value' => $report['currency']],
                ['title' => 'افتتاحي مدين', 'value' => $summary['opening_debit']],
                ['title' => 'افتتاحي دائن', 'value' => $summary['opening_credit']],
                ['title' => 'حركة مدين', 'value' => $summary['movement_debit']],
                ['title' => 'حركة دائن', 'value' => $summary['movement_credit']],
                ['title' => 'ختامي مدين', 'value' => $summary['debit']],
                ['title' => 'ختامي دائن', 'value' => $summary['credit']],
                ['title' => 'الفرق', 'value' => $summary['difference']],
            ],
            'columns' => ['الكود', 'الحساب', 'افتتاحي مدين', 'افتتاحي دائن', 'حركة مدين', 'حركة دائن', 'ختامي مدين', 'ختامي دائن'],
            'rows' => $report['rows'],
            'quality' => $report['quality'],
        ];
    }

    private function incomeStatementUiPayload(array $report): array
    {
        $summary = $report['summary'];

        return [
            'title' => $report['title'],
            'summary' => [
                ['title' => 'العملة', 'value' => $report['currency']],
                ['title' => 'إجمالي الإيرادات', 'value' => $summary['gross_revenue']],
                ['title' => 'مردودات المبيعات', 'value' => $summary['sales_returns']],
                ['title' => 'صافي الإيرادات', 'value' => $summary['net_revenue']],
                ['title' => 'المصاريف', 'value' => $summary['expenses']],
                ['title' => 'صافي الربح', 'value' => $summary['net_profit']],
            ],
            'columns' => ['الكود', 'الحساب', 'مدين', 'دائن', 'الرصيد'],
            'rows' => $report['rows'],
            'quality' => $report['quality'],
        ];
    }

    private function generalLedgerUiPayload(array $report): array
    {
        $summary = $report['summary'];

        return [
            'title' => $report['title'],
            'summary' => [
                ['title' => 'العملة', 'value' => $report['currency']],
                ['title' => 'الرصيد الافتتاحي', 'value' => $summary['opening_balance']],
                ['title' => 'مدين', 'value' => $summary['debit']],
                ['title' => 'دائن', 'value' => $summary['credit']],
                ['title' => 'الرصيد الختامي', 'value' => $summary['closing_balance']],
            ],
            'columns' => ['التاريخ', 'رقم القيد', 'البيان', 'مدين', 'دائن', 'الرصيد'],
            'rows' => $report['rows'],
            'quality' => $report['quality'],
        ];
    }

    private function balanceSheetUiPayload(array $report): array
    {
        $rows = collect();
        foreach (['assets' => 'الأصول', 'liabilities' => 'الالتزامات', 'equity' => 'حقوق الملكية'] as $key => $label) {
            $rows = $rows->concat(collect($report['sections'][$key] ?? [])->map(fn ($row) => array_merge($row, ['section' => $label])));
        }
        $rows->push([
            'section' => 'حقوق الملكية', 'code' => '-', 'account' => 'الأرباح المحتجزة',
            'balance' => $report['sections']['retained_earnings'] ?? 0,
        ]);
        $summary = $report['summary'];

        return [
            'title' => $report['title'],
            'summary' => [
                ['title' => 'العملة', 'value' => $report['currency']],
                ['title' => 'الأصول', 'value' => $summary['assets']],
                ['title' => 'الالتزامات', 'value' => $summary['liabilities']],
                ['title' => 'حقوق الملكية', 'value' => $summary['equity']],
                ['title' => 'فرق الميزانية', 'value' => $summary['difference']],
            ],
            'columns' => ['القسم', 'الكود', 'الحساب', 'الرصيد'],
            'rows' => $rows,
            'quality' => $report['quality'],
        ];
    }

    private function cashFlowUiPayload(array $report): array
    {
        $labels = ['operating' => 'تشغيلي', 'investing' => 'استثماري', 'financing' => 'تمويلي'];
        $rows = collect($report['sections'])->flatMap(function ($section, $key) use ($labels) {
            return collect($section['rows'] ?? [])->map(fn ($row) => array_merge($row, ['section_label' => $labels[$key] ?? $key]));
        })->values();
        $summary = $report['summary'];

        return [
            'title' => $report['title'],
            'summary' => [
                ['title' => 'العملة', 'value' => $report['currency']],
                ['title' => 'رصيد أول المدة', 'value' => $summary['opening_cash']],
                ['title' => 'التدفقات الداخلة', 'value' => $summary['cash_in']],
                ['title' => 'التدفقات الخارجة', 'value' => $summary['cash_out']],
                ['title' => 'صافي التدفق', 'value' => $summary['net_cash_flow']],
                ['title' => 'رصيد آخر المدة', 'value' => $summary['closing_cash']],
            ],
            'columns' => ['القسم', 'المصدر', 'داخل', 'خارج', 'الصافي'],
            'rows' => $rows,
            'quality' => $report['quality'],
        ];
    }

    private function agingUiPayload(array $report): array
    {
        $summary = $report['summary'];

        return [
            'title' => $report['title'],
            'summary' => [
                ['title' => 'العملة', 'value' => $report['currency']],
                ['title' => 'غير مستحق', 'value' => $summary['current']],
                ['title' => '1 - 30 يوم', 'value' => $summary['days_1_30']],
                ['title' => '31 - 60 يوم', 'value' => $summary['days_31_60']],
                ['title' => '61 - 90 يوم', 'value' => $summary['days_61_90']],
                ['title' => 'أكثر من 90 يوم', 'value' => $summary['over_90']],
                ['title' => 'الإجمالي', 'value' => $summary['balance']],
            ],
            'columns' => ['الشخص', 'غير مستحق', '1-30', '31-60', '61-90', '+90', 'الإجمالي'],
            'rows' => $report['rows'],
            'quality' => $report['quality'],
        ];
    }

    private function journalUiPayload(array $report): array
    {
        $entries = collect($report['entries']);
        $rows = $entries->flatMap(function ($entry) {
            return $entry->lines->map(fn ($line) => [
                'date' => $entry->entry_date?->toDateString(),
                'entry_number' => $entry->entry_number,
                'account' => $line->account?->code.' - '.$line->account?->name_ar,
                'description' => $line->description ?: $entry->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'source' => $entry->source_type.($entry->source_id ? ' #'.$entry->source_id : ''),
            ]);
        })->values();

        return [
            'title' => $report['title'],
            'summary' => [
                ['title' => 'العملة', 'value' => $report['currency']],
                ['title' => 'عدد القيود', 'value' => $entries->count()],
                ['title' => 'مدين', 'value' => round((float) $rows->sum('debit'), 4)],
                ['title' => 'دائن', 'value' => round((float) $rows->sum('credit'), 4)],
            ],
            'columns' => ['التاريخ', 'رقم القيد', 'الحساب', 'البيان', 'مدين', 'دائن', 'المصدر'],
            'rows' => $rows,
            'quality' => $report['quality'],
        ];
    }

    private function accountingUnavailablePayload(): array
    {
        return [
            'title' => 'التقرير المحاسبي',
            'summary' => [
                ['title' => 'الحالة', 'value' => 'يلزم تشغيل ترحيلات دفتر الأستاذ ثم التهيئة المحاسبية'],
            ],
            'columns' => [],
            'rows' => collect(),
            'quality' => ['complete' => false, 'ledger_not_initialized' => true],
        ];
    }

    /**
     * Build one currency-safe balance index using a grouped query. This avoids
     * both mixed-currency totals and a query per person/currency.
     *
     * @return array<string, array<string, array{total_taken: float, total_given: float, balance: float}>>
     */
    private function ledgerBalanceIndex(): array
    {
        $index = [];
        $totals = DebtTransaction::query()
            ->active()
            ->select(['customer_id', 'seller_id', 'currency', 'type'])
            ->selectRaw('SUM(amount) as total_amount')
            ->groupBy('customer_id', 'seller_id', 'currency', 'type')
            ->get();

        foreach ($totals as $total) {
            $personType = $total->customer_id ? 'customer' : 'seller';
            $personId = (int) ($total->customer_id ?: $total->seller_id);
            if ($personId <= 0) {
                continue;
            }

            $key = $personType.':'.$personId;
            $currency = $this->reportCurrency($total->currency);
            $index[$key] ??= $this->emptyCurrencyBalances();
            $amount = (float) $total->total_amount;
            if ($total->type === 'taken') {
                $index[$key][$currency]['total_taken'] += $amount;
            } elseif ($total->type === 'given') {
                $index[$key][$currency]['total_given'] += $amount;
            }
            $index[$key][$currency]['balance'] =
                $index[$key][$currency]['total_taken'] - $index[$key][$currency]['total_given'];
        }

        return $index;
    }

    /**
     * @return array<string, array{total_taken: float, total_given: float, balance: float}>
     */
    private function emptyCurrencyBalances(): array
    {
        return collect(DebtLedgerService::CURRENCIES)
            ->mapWithKeys(fn (string $currency) => [$currency => [
                'total_taken' => 0.0,
                'total_given' => 0.0,
                'balance' => 0.0,
            ]])
            ->all();
    }

    private function balancesReportPayload(): array
    {
        $balanceIndex = $this->ledgerBalanceIndex();
        $customerIds = collect(array_keys($balanceIndex))
            ->filter(fn (string $key) => str_starts_with($key, 'customer:'))
            ->map(fn (string $key) => (int) str_replace('customer:', '', $key));
        $sellerIds = collect(array_keys($balanceIndex))
            ->filter(fn (string $key) => str_starts_with($key, 'seller:'))
            ->map(fn (string $key) => (int) str_replace('seller:', '', $key));
        $people = Customer::query()
            ->where('is_canceled', false)
            ->whereIn('id', $customerIds)
            ->get(['id', 'name', 'phone'])
            ->map(fn (Customer $person) => [
                'id' => $person->id,
                'type' => 'customer',
                'type_label' => 'زبون',
                'name' => $person->name,
                'phone' => $person->phone,
                'balances' => $balanceIndex['customer:'.$person->id],
            ])
            ->concat(Seller::query()
                ->where('is_canceled', false)
                ->whereIn('id', $sellerIds)
                ->get(['id', 'name', 'phone'])
                ->map(fn (Seller $person) => [
                    'id' => $person->id,
                    'type' => 'seller',
                    'type_label' => 'مورد',
                    'name' => $person->name,
                    'phone' => $person->phone,
                    'balances' => $balanceIndex['seller:'.$person->id],
                ]));

        $rows = $people->flatMap(function (array $person) {
            return collect(DebtLedgerService::CURRENCIES)->map(function (string $currency) use ($person) {
                $balance = (float) ($person['balances'][$currency]['balance'] ?? 0);

                return [
                    'person_type' => $person['type'],
                    'person_id' => $person['id'],
                    'type' => $person['type_label'],
                    'name' => $person['name'],
                    'phone' => $person['phone'],
                    'currency' => $currency,
                    'balance' => round($balance, 3),
                    'balance_abs' => round(abs($balance), 3),
                    // Debt ledger semantics are: "given" decreases the signed
                    // balance (the person owes us), while "taken" increases it
                    // (we owe the person). Therefore negative is receivable.
                    'direction' => $balance <= 0 ? 'receivable' : 'payable',
                    'status' => $balance <= 0 ? 'إلنا' : 'علينا',
                ];
            });
        })->filter(fn (array $row) => abs((float) $row['balance']) > 0.0001)
            ->sortByDesc(fn (array $row) => abs((float) $row['balance']))
            ->values();

        $summary = collect([
            ['title' => 'عدد الحسابات', 'value' => $people->count()],
        ]);
        foreach (DebtLedgerService::CURRENCIES as $currency) {
            $currencyRows = $rows->where('currency', $currency);
            if ($currencyRows->isEmpty()) {
                continue;
            }
            $summary->push(
                ['title' => 'إلنا - '.$currency, 'value' => round($currencyRows->where('direction', 'receivable')->sum('balance_abs'), 3)],
                ['title' => 'علينا - '.$currency, 'value' => round($currencyRows->where('direction', 'payable')->sum('balance_abs'), 3)],
            );
        }

        return [
            'title' => 'أرصدة الزبائن والموردين',
            'summary' => $summary,
            'columns' => ['النوع', 'الاسم', 'الهاتف', 'العملة', 'الرصيد', 'الحالة'],
            'rows' => $rows,
        ];
    }

    private function statementReportPayload(Request $request, Carbon $from, Carbon $to): array
    {
        if (! $request->filled('person_id') || ! $request->filled('person_type')) {
            return [
                'title' => 'كشف حركات الحساب',
                'summary' => [
                    ['title' => 'الحساب', 'value' => 'اختر شخص من الفلاتر'],
                    ['title' => 'عدد الحركات', 'value' => 0],
                    ['title' => 'مدين', 'value' => 0],
                    ['title' => 'دائن', 'value' => 0],
                ],
                'columns' => ['التاريخ', 'الشخص', 'النوع', 'القيمة', 'العملة', 'الرصيد بعد', 'الصندوق', 'المصدر', 'الملاحظة'],
                'rows' => collect(),
            ];
        }

        $query = DebtTransaction::query()
            ->with(['customer:id,name,phone', 'seller:id,name,phone', 'box:id,name'])
            ->active()
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->person_type === 'customer' && $request->filled('person_id'), fn ($q) => $q->where('customer_id', $request->person_id)->whereNull('seller_id'))
            ->when($request->person_type === 'seller' && $request->filled('person_id'), fn ($q) => $q->where('seller_id', $request->person_id)->whereNull('customer_id'));

        $rows = $query->orderBy('transaction_date')->orderBy('id')->get()->map(function (DebtTransaction $transaction) {
            $person = $transaction->customer ?: $transaction->seller;

            return [
                'date' => optional($transaction->transaction_date)->toDateString(),
                'person' => optional($person)->name ?: '-',
                'person_type' => $transaction->customer_id ? 'زبون' : 'مورد',
                'transaction_type' => $transaction->type,
                'transaction_type_label' => $transaction->type === 'taken' ? 'أخذت' : 'أعطيت',
                'amount' => (float) $transaction->amount,
                'currency' => $this->reportCurrency($transaction->currency),
                'balance_after' => (float) $transaction->balance_after,
                'box' => optional($transaction->box)->name,
                'source' => $this->statementSourceLabel($transaction->source, $transaction->source_id),
                'note' => $transaction->note,
            ];
        });

        $summary = collect([
            ['title' => 'عدد الحركات', 'value' => $rows->count()],
        ]);
        foreach (DebtLedgerService::CURRENCIES as $currency) {
            $currencyRows = $rows->where('currency', $currency);
            if ($currencyRows->isEmpty()) {
                continue;
            }
            $summary->push(
                ['title' => 'أخذت - '.$currency, 'value' => round($currencyRows->where('transaction_type', 'taken')->sum('amount'), 3)],
                ['title' => 'أعطيت - '.$currency, 'value' => round($currencyRows->where('transaction_type', 'given')->sum('amount'), 3)],
            );
        }

        return [
            'title' => 'كشف حركات الحساب',
            'summary' => $summary,
            'columns' => ['التاريخ', 'الشخص', 'الحركة', 'القيمة', 'العملة', 'الرصيد بعد', 'الصندوق', 'المصدر', 'الملاحظة'],
            'rows' => $rows,
        ];
    }

    private function checksReportPayload(Request $request, Carbon $from, Carbon $to): array
    {
        $direction = $request->input('check_direction', 'all');
        $rows = collect();

        if ($direction !== 'outgoing') {
            $incoming = IncomingCheck::query()
                ->with(['fromCustomer:id,name,phone', 'fromSeller:id,name,phone', 'toCustomer:id,name,phone', 'toSeller:id,name,phone'])
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orderByDesc('created_at')
                ->get()
                ->map(function (IncomingCheck $check) {
                    $fromPerson = $check->fromCustomer ?: $check->fromSeller;
                    $toPerson = $check->toCustomer ?: $check->toSeller;

                    return [
                        'direction' => 'وارد',
                        'check_id' => $check->check_id,
                        'bank_name' => $check->bank_name,
                        'person' => optional($fromPerson)->name ?: optional($toPerson)->name ?: '-',
                        'total' => (float) $check->total,
                        'currency' => $this->reportCurrency($check->currency),
                        'due_date' => $this->reportDateString($check->due_date),
                        'status' => $this->checkStatusLabel($check->status),
                    ];
                });
            $rows = $rows->merge($incoming);
        }

        if ($direction !== 'incoming') {
            $outgoing = OutgoingCheck::query()
                ->with(['customer:id,name,phone', 'seller:id,name,phone'])
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orderByDesc('created_at')
                ->get()
                ->map(function (OutgoingCheck $check) {
                    return [
                        'direction' => 'صادر',
                        'check_id' => $check->check_id,
                        'bank_name' => $check->bank_name,
                        'person' => optional($check->customer ?: $check->seller)->name ?: '-',
                        'total' => (float) $check->total,
                        'currency' => $this->reportCurrency($check->currency),
                        'due_date' => $this->reportDateString($check->due_date),
                        'status' => $this->checkStatusLabel($check->status),
                    ];
                });
            $rows = $rows->merge($outgoing);
        }

        $summary = collect([
            ['title' => 'عدد الشيكات', 'value' => $rows->count()],
        ]);
        foreach (DebtLedgerService::CURRENCIES as $currency) {
            $currencyRows = $rows->where('currency', $currency);
            if ($currencyRows->isEmpty()) {
                continue;
            }
            $summary->push(
                ['title' => 'الواردة - '.$currency, 'value' => round($currencyRows->where('direction', 'وارد')->sum('total'), 3)],
                ['title' => 'الصادرة - '.$currency, 'value' => round($currencyRows->where('direction', 'صادر')->sum('total'), 3)],
            );
        }

        return [
            'title' => 'الشيكات الصادرة والواردة',
            'summary' => $summary,
            'columns' => ['الاتجاه', 'رقم الشيك', 'البنك', 'الشخص', 'القيمة', 'العملة', 'الاستحقاق', 'الحالة'],
            'rows' => $rows->values(),
        ];
    }

    private function inventoryReportPayload(Carbon $from, Carbon $to): array
    {
        $products = Product::query()
            ->with([
                'sizes.colorSizes',
            ])
            ->orderBy('nameAr')
            ->get();

        $fromDate = $from->copy()->startOfDay();
        $toDate = $to->copy()->endOfDay();
        $movements = Schema::hasTable('product_stock_movements')
            ? ProductStockMovement::query()
                ->where('created_at', '>=', $fromDate)
                ->get(['product_id', 'quantity', 'unit_cost', 'total_cost', 'created_at'])
                ->groupBy('product_id')
            : collect();
        $stockService = app(ProductStockService::class);
        $costingService = app(\App\Services\InventoryCostingService::class);
        $movementRowsCount = 0;
        $costedMovementRowsCount = 0;

        $rows = $products->map(function (Product $product) use (
            $movements,
            $stockService,
            $costingService,
            $toDate,
            &$movementRowsCount,
            &$costedMovementRowsCount
        ) {
            $stock = (float) $stockService->resolveDisplayStock($product);
            $inventory = $costingService->productSummary($product, true, 0);
            $currentValue = (float) ($inventory['inventory_value'] ?? 0);
            $unitCost = (float) ($inventory['average_inventory_unit_cost'] ?? 0);

            $periodQuantity = 0.0;
            $periodValue = 0.0;
            $afterQuantity = 0.0;
            $afterValue = 0.0;
            foreach (collect($movements[$product->id] ?? []) as $movement) {
                $quantity = (float) $movement->quantity;
                $hasSnapshot = $movement->total_cost !== null || $movement->unit_cost !== null;
                $absoluteCost = $movement->total_cost !== null
                    ? abs((float) $movement->total_cost)
                    : abs($quantity) * ($movement->unit_cost !== null ? (float) $movement->unit_cost : 0);
                $signedValue = $quantity < 0 ? -$absoluteCost : $absoluteCost;
                $movementRowsCount++;
                if ($hasSnapshot) {
                    $costedMovementRowsCount++;
                }

                if ($movement->created_at->gt($toDate)) {
                    $afterQuantity += $quantity;
                    $afterValue += $signedValue;
                } else {
                    $periodQuantity += $quantity;
                    $periodValue += $signedValue;
                }
            }

            $endingStock = $stock - $afterQuantity;
            $openingStock = $endingStock - $periodQuantity;
            $endingValue = $currentValue - $afterValue;
            $openingValue = $endingValue - $periodValue;

            return [
                'code' => $product->product_code ?: $product->id,
                'product' => $product->nameAr ?: $product->nameEng,
                'opening_quantity' => round($openingStock, 3),
                'quantity' => round($endingStock, 3),
                'unit_cost' => round($unitCost, 3),
                'total_cost' => round($endingValue, 3),
                'opening_value' => round($openingValue, 3),
                'ending_value' => round($endingValue, 3),
                'cost_coverage_complete' => (bool) ($inventory['cost_coverage_complete'] ?? false),
            ];
        });

        $coverage = $movementRowsCount > 0
            ? round(($costedMovementRowsCount / $movementRowsCount) * 100, 1)
            : 100.0;

        return [
            'title' => 'كميات وقيمة المخزون',
            'summary' => [
                ['title' => 'عدد المنتجات', 'value' => $rows->count()],
                ['title' => 'كمية أول المدة', 'value' => round($rows->sum('opening_quantity'), 3)],
                ['title' => 'كمية آخر المدة', 'value' => round($rows->sum('quantity'), 3)],
                ['title' => 'بضاعة أول المدة', 'value' => round($rows->sum('opening_value'), 3)],
                ['title' => 'بضاعة آخر المدة', 'value' => round($rows->sum('ending_value'), 3)],
                ['title' => 'تغطية تكلفة الحركات', 'value' => $coverage.'%'],
            ],
            'columns' => ['الكود', 'الصنف', 'كمية أول المدة', 'كمية آخر المدة', 'متوسط التكلفة', 'قيمة أول المدة', 'قيمة آخر المدة'],
            'rows' => $rows,
        ];
    }

    private function incomeReportPayload(Carbon $from, Carbon $to, Request $request): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $hasLedger = Schema::hasTable('accounting_journal_entries')
            && Schema::hasTable('accounting_journal_lines')
            && Schema::hasTable('accounting_accounts');
        $ledgerCoversPeriod = $hasLedger && $this->ledgerCoversPeriod($from);
        if ($ledgerCoversPeriod
            && AccountingJournalEntry::query()->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->exists()) {
            $service = $this->accountingReports ?? app(AccountingReportService::class);
            $currency = $this->reportCurrency($request->input('currency', 'شيكل'));

            return $this->incomeStatementUiPayload($service->incomeStatement($from, $to, $currency));
        }

        $sales = $this->analyticsSales($from, $to);
        $salesAfterDiscount = (float) $sales->sum('total');
        $salesDiscount = (float) $sales->sum('discount');
        $salesReturns = (float) SalesReturn::query()
            ->whereIn('return_type', ['direct', 'partial'])
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->sum('total_amount');
        $netSales = $this->financialNetSales($salesAfterDiscount, $salesReturns);
        $returnCost = (float) SalesReturnItem::query()
            ->whereHas('salesReturn', fn ($query) => $query
                ->whereIn('return_type', ['direct', 'partial'])
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$from, $to]))
            ->sum('inventory_total_cost');
        $costOfSales = (float) $sales->sum('cost') - $returnCost;
        $expenses = $this->periodExpenseTotal($from, $to);
        $grossProfit = $netSales - $costOfSales;
        $netProfit = $grossProfit - $expenses;

        $rows = collect([
            ['account' => 'المبيعات بعد الخصم', 'debit' => 0, 'credit' => round($salesAfterDiscount, 3)],
            ['account' => 'مردودات المبيعات', 'debit' => round($salesReturns, 3), 'credit' => 0],
            ['account' => 'الخصم الممنوح - للبيان فقط', 'debit' => round($salesDiscount, 3), 'credit' => 0],
            ['account' => 'صافي المبيعات', 'debit' => 0, 'credit' => round($netSales, 3)],
            ['account' => 'تكلفة المبيعات', 'debit' => round($costOfSales, 3), 'credit' => 0],
            ['account' => 'إجمالي الربح', 'debit' => 0, 'credit' => round($grossProfit, 3)],
            ['account' => 'إجمالي المصاريف', 'debit' => round($expenses, 3), 'credit' => 0],
            ['account' => 'صافي الأرباح', 'debit' => $netProfit < 0 ? round(abs($netProfit), 3) : 0, 'credit' => $netProfit >= 0 ? round($netProfit, 3) : 0],
        ]);

        return [
            'title' => 'قائمة الدخل',
            'summary' => [
                ['title' => 'صافي المبيعات', 'value' => round($netSales, 3)],
                ['title' => 'تكلفة المبيعات', 'value' => round($costOfSales, 3)],
                ['title' => 'إجمالي الربح', 'value' => round($grossProfit, 3)],
                ['title' => 'صافي الأرباح', 'value' => round($netProfit, 3)],
                ['title' => 'تغطية تكلفة المبيعات', 'value' => $sales->isEmpty()
                    ? '100%'
                    : round(($sales->where('cost_complete', true)->count() / $sales->count()) * 100, 1).'%'],
            ],
            'columns' => ['الحساب', 'مدين', 'دائن'],
            'rows' => $rows,
            'quality' => [
                'complete' => false,
                'ledger_not_initialized' => ! $hasLedger,
                'coverage_incomplete' => $hasLedger && ! $ledgerCoversPeriod,
                'cost_coverage_incomplete' => $sales->where('cost_complete', false)->isNotEmpty(),
            ],
        ];
    }

    private function salesReturnsReportPayload(Carbon $from, Carbon $to): array
    {
        $cancelledRows = InstantSale::query()
            ->with(['product:id,nameAr,nameEng', 'buyerCustomer:id,name,phone', 'seller:id,name,phone'])
            ->whereNull('parent_id')
            ->whereNull('maintenance_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(function ($query) {
                $query->where('status', 'cancelled')->orWhereNotNull('cancelled_at');
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (InstantSale $sale) => [
                'serial' => $sale->serial_number ?: $sale->id,
                'date' => optional($sale->created_at)->toDateTimeString(),
                'buyer' => $sale->buyer_name ?: optional($sale->buyerCustomer)->name ?: optional($sale->seller)->name ?: 'زبون نقدي',
                'product' => optional($sale->product)->nameAr ?: optional($sale->product)->nameEng,
                'quantity' => (float) ($sale->quantity ?? 0),
                'total' => (float) ($sale->total_cost ?? 0),
                'cancelled_at' => optional($sale->cancelled_at)->toDateTimeString(),
            ]);
        $directRows = SalesReturn::query()
            ->with(['customer:id,name', 'seller:id,name', 'items'])
            ->whereIn('return_type', ['direct', 'partial'])
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (SalesReturn $return) => [
                'serial' => $return->serial_number ?: $return->id,
                'date' => optional($return->completed_at)->toDateTimeString(),
                'buyer' => $return->customer?->name ?: $return->seller?->name ?: '-',
                'product' => $return->items->pluck('product_name')->filter()->implode('، '),
                'quantity' => (float) $return->items->sum('quantity'),
                'total' => (float) $return->total_amount,
                'cancelled_at' => null,
            ]);
        $rows = $cancelledRows->concat($directRows)->sortByDesc('date')->values();

        return [
            'title' => 'مردودات المبيعات',
            'summary' => [
                ['title' => 'عدد فواتير المرتجع والإلغاء', 'value' => $rows->count()],
                ['title' => 'قيمة المرتجعات والإلغاءات', 'value' => round($rows->sum('total'), 3)],
            ],
            'columns' => ['الرقم', 'التاريخ', 'الزبون', 'الصنف', 'الكمية', 'الإجمالي', 'تاريخ الإلغاء'],
            'rows' => $rows,
        ];
    }

    private function productProfitReportPayload(Carbon $from, Carbon $to): array
    {
        $invoices = InstantSale::query()
            ->with(['product', 'subProducts.product'])
            ->whereNull('parent_id')
            ->whereNull('maintenance_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->whereNull('cancelled_at')
            ->get();
        $productRows = collect();
        $costedLines = 0;
        $totalLines = 0;

        foreach ($invoices as $invoice) {
            $lines = collect([$invoice])->concat($invoice->subProducts);
            foreach ($lines as $line) {
                if (! $line->product_id || ! $line->product) {
                    continue;
                }
                $quantity = (float) ($line->quantity ?? 0);
                $lineGross = (float) ($line->cost ?? 0) * $quantity;
                $salesTotal = $this->allocatedProductRevenue(
                    (float) ($invoice->total_cost ?? 0),
                    (float) ($invoice->discount ?? 0),
                    $lineGross,
                );
                $hasSnapshot = $line->inventory_total_cost !== null;
                $costTotal = $this->analyticsLineCost(
                    $line->inventory_total_cost
                );
                $totalLines++;
                if ($hasSnapshot) {
                    $costedLines++;
                }

                $key = (int) $line->product_id;
                $current = $productRows->get($key, [
                    'code' => $line->product->product_code ?: $line->product_id,
                    'product' => $line->product->nameAr ?: $line->product->nameEng,
                    'quantity' => 0.0,
                    'sales_total' => 0.0,
                    'cost_total' => 0.0,
                ]);
                $current['quantity'] += $quantity;
                $current['sales_total'] += $salesTotal;
                $current['cost_total'] += $costTotal;
                $productRows->put($key, $current);
            }
        }

        $orders = SalesOrder::query()
            ->with(['items.product'])
            ->whereNotNull('financial_posted_at')
            ->whereBetween('financial_posted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('is_debt_collection', false)
            ->where('status', '!=', 'canceled')
            ->when(
                Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'sales_order_id'),
                fn ($query) => $query->whereNotExists(fn ($linkedSale) => $linkedSale
                    ->selectRaw('1')
                    ->from('instant_sales')
                    ->whereColumn('instant_sales.sales_order_id', 'sales_orders.id')
                    ->whereNull('instant_sales.parent_id')),
            )
            ->get();
        $orderIds = $orders->pluck('id');
        $orderCosts = $orderIds->isEmpty() || ! Schema::hasTable('inventory_cost_allocations')
            ? collect()
            : InventoryCostAllocation::query()
                ->where('reference_type', 'sales_order')
                ->whereIn('reference_id', $orderIds)
                ->get(['reference_id', 'product_id', 'total_cost'])
                ->groupBy(fn (InventoryCostAllocation $row) => $row->reference_id.':'.$row->product_id)
                ->map(fn ($rows) => (float) $rows->sum('total_cost'));
        $uncostedOrderProducts = $orderIds->isEmpty() || ! Schema::hasTable('product_stock_movements')
            ? collect()
            : ProductStockMovement::query()
                ->where('reference_type', 'sales_order')
                ->whereIn('reference_id', $orderIds)
                ->where('quantity', '<', 0)
                ->whereNull('total_cost')
                ->get(['reference_id', 'product_id'])
                ->mapWithKeys(fn (ProductStockMovement $row) => [$row->reference_id.':'.$row->product_id => true]);

        foreach ($orders as $order) {
            $items = $order->items->where('is_hidden', false);
            $gross = (float) $items->sum(fn ($item) => (float) ($item->line_total ?: ((float) $item->unit_price * (float) $item->quantity)));
            foreach ($items->groupBy('product_id') as $productId => $productItems) {
                $product = $productItems->first()?->product;
                if (! $productId || ! $product) {
                    continue;
                }
                $productGross = (float) $productItems->sum(fn ($item) => (float) ($item->line_total ?: ((float) $item->unit_price * (float) $item->quantity)));
                $salesTotal = $gross > 0 ? (float) $order->total * ($productGross / $gross) : 0.0;
                $costKey = $order->id.':'.$productId;
                $costTotal = (float) ($orderCosts[$costKey] ?? 0);
                $totalLines++;
                if ($orderCosts->has($costKey) && ! $uncostedOrderProducts->has($costKey)) {
                    $costedLines++;
                }

                $key = (int) $productId;
                $current = $productRows->get($key, [
                    'code' => $product->product_code ?: $productId,
                    'product' => $product->nameAr ?: $product->nameEng,
                    'quantity' => 0.0,
                    'sales_total' => 0.0,
                    'cost_total' => 0.0,
                ]);
                $current['quantity'] += (float) $productItems->sum('quantity');
                $current['sales_total'] += $salesTotal;
                $current['cost_total'] += $costTotal;
                $productRows->put($key, $current);
            }
        }

        $returnedItems = SalesReturnItem::query()
            ->with('product:id,product_code,nameAr,nameEng')
            ->whereHas('salesReturn', fn ($query) => $query
                ->whereIn('return_type', ['direct', 'partial'])
                ->where('status', 'completed')
                ->whereBetween('completed_at', [
                    $from->copy()->startOfDay(),
                    $to->copy()->endOfDay(),
                ]))
            ->get();
        foreach ($returnedItems as $item) {
            if (! $item->product_id) {
                continue;
            }

            $key = (int) $item->product_id;
            $current = $productRows->get($key, [
                'code' => $item->product?->product_code ?: $item->product_id,
                'product' => $item->product?->nameAr ?: $item->product?->nameEng ?: $item->product_name,
                'quantity' => 0.0,
                'sales_total' => 0.0,
                'cost_total' => 0.0,
            ]);
            $current['quantity'] -= (float) $item->quantity;
            $current['sales_total'] -= (float) $item->line_total;
            $current['cost_total'] -= (float) ($item->inventory_total_cost ?? 0);
            $productRows->put($key, $current);
            $totalLines++;
            if ($item->inventory_total_cost !== null) {
                $costedLines++;
            }
        }

        $rows = $productRows->map(function (array $row) {
            $profit = (float) $row['sales_total'] - (float) $row['cost_total'];
            $profitPercent = (float) $row['sales_total'] > 0
                ? ($profit / (float) $row['sales_total']) * 100
                : 0;

            return array_merge($row, [
                'quantity' => round((float) $row['quantity'], 3),
                'sales_total' => round((float) $row['sales_total'], 3),
                'cost_total' => round((float) $row['cost_total'], 3),
                'profit' => round($profit, 3),
                'profit_percent' => round($profitPercent, 2).'%',
            ]);
        })->sortByDesc('profit')->values();

        $coverage = $totalLines > 0 ? round(($costedLines / $totalLines) * 100, 1) : 100.0;

        return [
            'title' => 'نسبة ربح المنتجات',
            'summary' => [
                ['title' => 'عدد المنتجات', 'value' => $rows->count()],
                ['title' => 'إجمالي المبيعات', 'value' => round($rows->sum('sales_total'), 3)],
                ['title' => 'إجمالي التكلفة', 'value' => round($rows->sum('cost_total'), 3)],
                ['title' => 'إجمالي الربح', 'value' => round($rows->sum('profit'), 3)],
                ['title' => 'تغطية تكلفة FIFO', 'value' => $coverage.'%'],
            ],
            'columns' => ['الكود', 'الصنف', 'الكمية', 'المبيعات', 'التكلفة', 'الربح', 'نسبة الربح'],
            'rows' => $rows,
        ];
    }

    private function checkStatusLabel(?string $status): string
    {
        return match ($status) {
            'not_cashed' => 'غير مصروف',
            'cashed_to_person' => 'مصروف للشخص',
            'cashed_to_box' => 'مصروف للصندوق',
            default => $status ?: '-',
        };
    }

    private function statementSourceLabel(?string $source, $sourceId = null): string
    {
        $label = match ((string) $source) {
            'instant_sale' => 'باقي فاتورة بيع فوري',
            'profit_sale' => 'باقي فاتورة بيع ربحي',
            'sales_order' => 'باقي طلبية',
            'sales_return' => 'رصيد مرتجع مبيعات',
            'incoming_check' => 'شيك وارد',
            'incoming_check_disposal' => 'تصرف في شيك وارد',
            'outgoing_check' => 'شيك صادر',
            'manual', '' => 'قسم الديون',
            default => 'مصدر آخر',
        };

        return $sourceId ? $label.' #'.$sourceId : $label;
    }

    private function reportDateString($value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return $value ? (string) $value : '-';
    }

    private function reportCurrency(?string $currency): string
    {
        return match (mb_strtolower(trim((string) $currency))) {
            'دولار', 'dollar', 'usd', '$' => 'دولار',
            'دينار', 'dinar', 'jod' => 'دينار',
            'شيكل', 'shekel', 'ils', 'nis', '₪', '' => 'شيكل',
            default => 'شيكل',
        };
    }

    private function periodExpenseTotal(Carbon $from, Carbon $to): float
    {
        return (float) $this->periodExpenses($from, $to)->sum('amount');
    }

    private function periodExpenses(Carbon $from, Carbon $to)
    {
        $expenses = Expense::query()
            ->whereDate(DB::raw('COALESCE(expense_date, created_at)'), '>=', $from->toDateString())
            ->whereDate(DB::raw('COALESCE(expense_date, created_at)'), '<=', $to->toDateString())
            ->get(['price', 'expense_date', 'created_at'])
            ->map(fn (Expense $expense) => [
                'date' => Carbon::parse($expense->expense_date ?: $expense->created_at),
                'amount' => (float) $expense->price,
                'source' => 'expense',
            ]);

        $depreciation = Schema::hasTable('asset_logs') && Schema::hasColumn('asset_logs', 'depreciation_period')
            ? AssetLog::query()
                ->where('type', 'depreciate')
                ->whereBetween('depreciation_period', [$from->format('Y-m'), $to->format('Y-m')])
                ->get(['depreciation_period', 'depreciation_amount'])
                ->map(fn (AssetLog $log) => [
                    'date' => Carbon::parse($log->depreciation_period.'-01'),
                    'amount' => (float) $log->depreciation_amount,
                    'source' => 'depreciation',
                ])
            : collect();

        $projectExpenses = Schema::hasTable('project_expenses')
            ? ProjectExpense::query()
                ->whereDate(DB::raw('COALESCE(expense_date, created_at)'), '>=', $from->toDateString())
                ->whereDate(DB::raw('COALESCE(expense_date, created_at)'), '<=', $to->toDateString())
                ->get(['expenses', 'expense_date', 'created_at'])
                ->map(fn (ProjectExpense $expense) => [
                    'date' => Carbon::parse($expense->expense_date ?: $expense->created_at),
                    'amount' => (float) $expense->expenses,
                    'source' => 'project_expense',
                ])
            : collect();

        return $expenses->concat($depreciation)->concat($projectExpenses)->values();
    }

    private function periodCashCollected(Carbon $from, Carbon $to, float $fallback): float
    {
        if (! Schema::hasTable('accounting_journal_entries')
            || ! Schema::hasTable('accounting_journal_lines')
            || ! Schema::hasTable('accounting_accounts')
            || ! $this->ledgerCoversPeriod($from)) {
            return $fallback;
        }

        $cashAccountId = DB::table('accounting_accounts')->where('system_key', 'cash')->value('id');
        if (! $cashAccountId) {
            return $fallback;
        }

        $hasEntries = DB::table('accounting_journal_entries')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->exists();
        if (! $hasEntries) {
            return $fallback;
        }

        $row = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('lines.account_id', $cashAccountId)
            ->whereBetween('entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('entries.source_type', [
                'instant_sale', 'profit_sale', 'sales_order_settlement', 'incoming_check',
                'debt_transaction', 'sales_return',
            ])
            ->selectRaw("SUM(CASE WHEN entries.source_type = 'debt_transaction' THEN lines.debit ELSE lines.debit - lines.credit END) as collected")
            ->first();

        return round((float) ($row->collected ?? 0), 4);
    }

    private function ledgerCoversPeriod(Carbon $from): bool
    {
        if (! Schema::hasTable('accounting_cutovers')) {
            return false;
        }

        return DB::table('accounting_cutovers')
            ->where('status', 'applied')
            ->whereDate('cutover_date', '<=', $from->toDateString())
            ->exists();
    }

    private function resolveReportPeriod(Request $request): array
    {
        $period = $request->input('period', 'month');

        if ($period === 'custom' || $request->filled('from_date') || $request->filled('to_date')) {
            $from = $request->filled('from_date') ? Carbon::parse($request->from_date) : Carbon::now()->startOfMonth();
            $to = $request->filled('to_date') ? Carbon::parse($request->to_date) : Carbon::now();

            return [$from, $to, 'custom'];
        }

        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->copy(), $now->copy(), 'today'],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'week'],
            'quarter' => [$now->copy()->subMonths(3)->startOfDay(), $now->copy(), 'quarter'],
            'half_year' => [$now->copy()->subMonths(6)->startOfDay(), $now->copy(), 'half_year'],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), 'year'],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'month'],
        };
    }

    private function salesReportPaymentType(float $total, float $paid): string
    {
        if ($paid <= 0 && $total > 0) {
            return 'debt';
        }

        if ($paid >= $total) {
            return 'cash';
        }

        return 'mixed';
    }

    public static function fixArabic($reportHtml)
    {
        // 🔹 Fix Arabic text
        $arabic = new Arabic;
        $positions = $arabic->arIdentify($reportHtml);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(
                substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
            );
            $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
        }

        return $reportHtml;
    }
}

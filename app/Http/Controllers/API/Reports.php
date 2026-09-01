<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use ArPHP\I18N\Arabic;
use App\Models\Debt;
use App\Models\DebtTransaction;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\Expense;
use App\Models\IncomingCheck;
use App\Models\InstantSale;
use App\Models\Log;
use App\Models\OutgoingCheck;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProfitSale;
use App\Models\ReturnModel;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\DebtLedgerService;

class Reports extends Controller
{
    public function mainData(){
        try{

        $totalDebtsWeOwe = Debt::where('type','we owe')
        ->where('status','unpaid')
        ->sum('total'); // ديون علينا
        $totalDebtsOwedToUs = Debt::where('type','owed to us')
        ->where('status','unpaid')
        ->sum('total'); // ديون لنا
        $totalSales = InstantSale::whereNull('maintenance_id')->sum('total_cost'); // اجمالي المبيعات
        $totalBoxes = Box::totalAmount(); // مجموع الصناديق
        $numberOfPeople = Customer::count() + Seller::count(); // عدد الاشخاص
        $numberOfEmployees = EmployeeDetail::count(); // عدد الموظفين

        $todayCompletedEmployeeTasksCount = EmployeeTask::where('status', 'completed')
            ->where('parent_id', NULL)
            ->whereDate('created_at', Carbon::today())
            ->count();
        $monthCompletedEmployeeTasks = EmployeeTask::where('status', 'completed')
            ->where('parent_id', NULL)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();
        $todayIncompletedEmployeeTasksCount = EmployeeTask::where('status','!=', 'completed')
            ->where('parent_id', NULL)
            ->whereDate('created_at', Carbon::today())
            ->count();
        $monthIncompletedEmployeeTasks = EmployeeTask::where('status','!=', 'completed')
            ->where('parent_id', NULL)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();

        

        //checks
        $totalOutgoingChecks = OutgoingCheck::totalAmount(); // غير المصروفة
        $totalIncomingChecks = IncomingCheck::totalAmount(); // غير المصروفة
        $totalChecks = $totalIncomingChecks + $totalOutgoingChecks; // مجموع الشيكات
        $profits = $totalSales - ($totalDebtsWeOwe + $totalOutgoingChecks); // صافي الربح

        $totalBills = Bill::where('status','finished')->sum('total'); // قيمة المشتريات
        $totalOngoingProjects = Project::where('status','ongoing')->count(); // عدد المشاريع
        $totalExpenses = Expense::sum('price'); // اجمالي المصاريف
        $totalReturns = ReturnModel::whereIn('status', ['confirmed', 'pending', 'delivered', 'settled'])->sum('total'); // مردودات المشتريات

        $totalChecksOnUs = OutgoingCheck::sum('total'); // شيكات علينا

        $totalGoods = 0; // تكلفة البضاعة
        
        foreach(Product::all() as $product){
            $salePrice = $product->purchasePrices->last();
            if($salePrice){
                $singleGood = $salePrice->price * $product->stock??0;
                $totalGoods += $singleGood;
            }

        }

        $shopCapital = $totalBoxes + $totalChecks + $totalDebtsOwedToUs + $totalGoods; // رأس مال المحل
        $netShopCapital = ($totalBoxes + $totalChecks + $totalDebtsOwedToUs + $totalGoods) - ($totalChecksOnUs + $totalDebtsWeOwe); // رأس مال المحل صافي

        return response()->json([
            'status'=>'success',
            'data' => [
                'total_debts_we_owe' => $totalDebtsWeOwe,
                'total_sales' => $totalSales,
                'profits' => $profits,
                'total_boxes' => $totalBoxes,
                'total_checks' => $totalChecks,
                'total_bills' => $totalBills,
                'number_of_people' => $numberOfPeople,
                'number_of_projects' => $totalOngoingProjects,
                'number_of_employees' => $numberOfEmployees ,
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
        ],200);
        }
        catch(QueryException $e){
                return response([
                    'status'=>'error',
                    'message' => __('messages.something_wrong'),
                ],200);
            }
            

            catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.something_wrong'),
                ], 200);
            }
    }

    public function getReport(Request $request){
        try {
                $request->validate([
                    'type' => [
                        'required',
                        'string',
                        Rule::in(['debts', 'instant_sales', 'employee_tasks', 'boxes'
                        ,'checks','bills','people','projects','employees'
                        ,'expenses','returns']),
                    ],

                    'from_date' => ['nullable', 'date'],
                    'to_date'   => ['nullable', 'date', 'after_or_equal:from_date'],
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
            }

            elseif ($request->type === 'checks') {
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
        $arabic = new Arabic();
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
            'errors' => $e->errors()

        ], 200);
    }  catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.retrieve_data_error')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong')
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
                ->limit(1000)
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
            })->filter(function (array $row) use ($paymentType) {
                return $paymentType === 'all' || $row['payment_type'] === $paymentType;
            })->values();

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

        $debtPeople = collect(app(DebtLedgerService::class)->getPeopleList('customers'))
            ->concat(app(DebtLedgerService::class)->getPeopleList('sellers'));
        $debtsForUs = $debtPeople->sum(fn (array $person) => max((float) ($person['balances']['شيكل']['balance'] ?? 0), 0));
        $debtsOnUs = $debtPeople->sum(fn (array $person) => abs(min((float) ($person['balances']['شيكل']['balance'] ?? 0), 0)));
        $incomingChecks = (float) IncomingCheck::totalAmount();
        $outgoingChecks = (float) OutgoingCheck::totalAmount();

        $inventory = Product::query()
            ->with(['purchasePrices' => fn ($query) => $query->orderByDesc('id')->limit(1)])
            ->get()
            ->map(function (Product $product) {
                $stock = (float) ($product->stock ?? 0);
                $unitCost = (float) ($product->purchasePrices->first()?->price ?? 0);

                return [
                    'id' => $product->id,
                    'label' => $product->nameAr ?: $product->nameEng ?: (string) $product->id,
                    'quantity' => round($stock, 3),
                    'value' => round($stock * $unitCost, 3),
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
                    $this->analyticsSummaryItem('net_profit', $current['net_profit'], $previous['net_profit']),
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
                'debts' => [
                    ['key' => 'for_us', 'label' => 'ديون لنا', 'value' => round($debtsForUs, 3)],
                    ['key' => 'on_us', 'label' => 'ديون علينا', 'value' => round($debtsOnUs, 3)],
                ],
                'checks' => [
                    ['key' => 'incoming', 'label' => 'واردة', 'value' => round($incomingChecks, 3)],
                    ['key' => 'outgoing', 'label' => 'صادرة', 'value' => round($outgoingChecks, 3)],
                ],
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
            ],
        ]);
    }

    private function analyticsPeriodMetrics(Carbon $from, Carbon $to): array
    {
        $sales = $this->analyticsSales($from, $to);

        $grossSales = (float) $sales->sum('total');
        $discounts = (float) $sales->sum('discount');
        $netSales = $grossSales - $discounts;
        $cost = (float) $sales->sum('cost');
        $expenses = (float) Expense::whereBetween('created_at', [$from, $to])->sum('price');
        $purchases = (float) Bill::where('status', 'finished')->whereBetween('created_at', [$from, $to])->sum('total');
        $purchaseReturns = (float) ReturnModel::whereIn('status', ['confirmed', 'delivered', 'settled'])
            ->whereBetween('created_at', [$from, $to])->sum('total');

        $paymentMix = ['cash' => 0.0, 'debt' => 0.0, 'mixed' => 0.0];
        foreach ($sales as $sale) {
            $total = max((float) $sale['total'] - (float) $sale['discount'], 0);
            $paid = min(max((float) $sale['paid'], 0), $total);
            $paymentMix[$this->salesReportPaymentType($total, $paid)] += $total;
        }

        return [
            'net_sales' => $netSales,
            'cash_collected' => (float) $sales->sum('paid'),
            'expenses' => $expenses,
            'purchases' => $purchases,
            'purchase_returns' => $purchaseReturns,
            'cost_of_sales' => $cost,
            'net_profit' => $this->analyticsNetProfit($netSales, $cost, $expenses),
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
            if (!$points->has($key)) continue;
            $net = (float) $sale['total'] - (float) $sale['discount'];
            $row = $points[$key];
            $row['sales'] += $net;
            $row['profit'] += $net - (float) $sale['cost'];
            $points->put($key, $row);
        }

        foreach (Expense::whereBetween('created_at', [$from, $to])->get(['created_at', 'price']) as $expense) {
            $key = $expense->created_at->format($format);
            if ($points->has($key)) { $row = $points[$key]; $row['expenses'] += (float) $expense->price; $row['profit'] -= (float) $expense->price; $points->put($key, $row); }
        }
        foreach (Bill::where('status', 'finished')->whereBetween('created_at', [$from, $to])->get(['created_at', 'total']) as $bill) {
            $key = $bill->created_at->format($format);
            if ($points->has($key)) { $row = $points[$key]; $row['purchases'] += (float) $bill->total; $points->put($key, $row); }
        }

        return $points->values();
    }

    private function analyticsSales(Carbon $from, Carbon $to)
    {
        $instantHasPaid = Schema::hasColumn('instant_sales', 'payment_box_value');
        $instantHasCost = Schema::hasColumn('instant_sales', 'inventory_total_cost');
        $instantColumns = ['id', 'created_at', 'total_cost', 'discount'];
        if ($instantHasPaid) $instantColumns[] = 'payment_box_value';
        if ($instantHasCost) $instantColumns[] = 'inventory_total_cost';

        $instantQuery = InstantSale::query()
            ->whereNull('parent_id')
            ->whereBetween('created_at', [$from, $to]);
        if (Schema::hasColumn('instant_sales', 'maintenance_id')) $instantQuery->whereNull('maintenance_id');
        if (Schema::hasColumn('instant_sales', 'status')) {
            $instantQuery->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'));
        }
        if (Schema::hasColumn('instant_sales', 'cancelled_at')) $instantQuery->whereNull('cancelled_at');

        $instantRows = $instantQuery->get($instantColumns);
        $parentIds = $instantRows->pluck('id')->map(fn ($id) => (int) $id)->values();
        $costByInvoice = collect();

        if ($parentIds->isNotEmpty()) {
            $lineColumns = ['id', 'parent_id', 'product_id', 'quantity'];
            if ($instantHasCost) $lineColumns[] = 'inventory_total_cost';
            $costByInvoice = InstantSale::query()
                ->with('product:id,wholesalePrice')
                ->where(function ($query) use ($parentIds) {
                    $query->whereIn('id', $parentIds)->orWhereIn('parent_id', $parentIds);
                })
                ->get($lineColumns)
                ->groupBy(fn (InstantSale $line) => (int) ($line->parent_id ?: $line->id))
                ->map(fn ($lines) => (float) $lines->sum(fn (InstantSale $line) =>
                    $this->analyticsLineCost(
                        $instantHasCost ? $line->inventory_total_cost : null,
                        (float) ($line->quantity ?? 0),
                        (float) ($line->product?->wholesalePrice ?? 0)
                    )
                ));
        }

        $instant = $instantRows->map(fn (InstantSale $sale) => [
            'source' => 'instant_sale',
            'created_at' => $sale->created_at,
            'total' => (float) ($sale->total_cost ?? 0),
            'discount' => (float) ($sale->discount ?? 0),
            'paid' => $instantHasPaid
                ? (float) ($sale->payment_box_value ?? 0)
                : (float) ($sale->total_cost ?? 0),
            'cost' => (float) ($costByInvoice[(int) $sale->id] ?? 0),
        ]);

        if (!Schema::hasTable('profit_sales')) return $instant;

        $profitHasPaid = Schema::hasColumn('profit_sales', 'payment_box_value');
        $profitColumns = ['created_at', 'total_cost'];
        if ($profitHasPaid) $profitColumns[] = 'payment_box_value';
        $profitQuery = ProfitSale::query()->whereBetween('created_at', [$from, $to]);
        if (Schema::hasColumn('profit_sales', 'status')) {
            $profitQuery->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'));
        }
        if (Schema::hasColumn('profit_sales', 'cancelled_at')) $profitQuery->whereNull('cancelled_at');

        $profit = $profitQuery->get($profitColumns)->map(fn (ProfitSale $sale) => [
            'source' => 'profit_sale',
            'created_at' => $sale->created_at,
            'total' => (float) ($sale->total_cost ?? 0),
            'discount' => 0.0,
            'paid' => $profitHasPaid
                ? (float) ($sale->payment_box_value ?? 0)
                : (float) ($sale->total_cost ?? 0),
            'cost' => 0.0,
        ]);

        return $instant->concat($profit)->values();
    }

    private function analyticsSummaryItem(string $key, float $current, float $previous): array
    {
        $change = $previous == 0.0 ? ($current == 0.0 ? 0.0 : 100.0) : (($current - $previous) / abs($previous)) * 100;
        return ['key' => $key, 'value' => round($current, 3), 'previous_value' => round($previous, 3), 'change_percent' => round($change, 1)];
    }

    private function analyticsLineCost($snapshotCost, float $quantity, float $wholesalePrice): float
    {
        return $snapshotCost !== null
            ? (float) $snapshotCost
            : $quantity * $wholesalePrice;
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
                        'inventory',
                        'income',
                        'sales_returns',
                        'product_profit',
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
                'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
                'check_direction' => ['nullable', 'string', Rule::in(['all', 'incoming', 'outgoing'])],
            ]);

            [$from, $to, $period] = $this->resolveReportPeriod($request);
            $payload = match ($request->type) {
                'balances' => $this->balancesReportPayload(),
                'statement' => $this->statementReportPayload($request, $from, $to),
                'checks' => $this->checksReportPayload($request, $from, $to),
                'boxes' => $this->boxesReportPayload($request, $from, $to),
                'inventory' => $this->inventoryReportPayload($from, $to),
                'income' => $this->incomeReportPayload($from, $to),
                'sales_returns' => $this->salesReturnsReportPayload($from, $to),
                'product_profit' => $this->productProfitReportPayload($from, $to),
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
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function reportPeople()
    {
        try {
            $customers = Customer::query()
                ->select(['id', 'name', 'phone'])
                ->withSum(['debtTransactions as balance' => fn ($query) => $query->active()], 'amount')
                ->orderBy('name')
                ->get()
                ->map(fn (Customer $person) => [
                    'id' => $person->id,
                    'type' => 'customer',
                    'type_label' => 'زبون',
                    'name' => $person->name,
                    'phone' => $person->phone,
                    'balance' => round((float) ($person->balance ?? 0), 3),
                ]);

            $sellers = Seller::query()
                ->select(['id', 'name', 'phone'])
                ->withSum(['debtTransactions as balance' => fn ($query) => $query->active()], 'amount')
                ->orderBy('name')
                ->get()
                ->map(fn (Seller $person) => [
                    'id' => $person->id,
                    'type' => 'seller',
                    'type_label' => 'مورد',
                    'name' => $person->name,
                    'phone' => $person->phone,
                    'balance' => round((float) ($person->balance ?? 0), 3),
                ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'people' => $customers->merge($sellers)->values(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function balancesReportPayload(): array
    {
        $customerRows = Customer::query()
            ->select(['id', 'name', 'phone'])
            ->withSum(['debtTransactions as balance' => fn ($query) => $query->active()], 'amount')
            ->get()
            ->map(fn (Customer $person) => $this->personBalanceRow('customer', 'زبون', $person));

        $sellerRows = Seller::query()
            ->select(['id', 'name', 'phone'])
            ->withSum(['debtTransactions as balance' => fn ($query) => $query->active()], 'amount')
            ->get()
            ->map(fn (Seller $person) => $this->personBalanceRow('seller', 'مورد', $person));

        $rows = $customerRows->merge($sellerRows)
            ->filter(fn (array $row) => abs((float) $row['balance']) > 0.0001)
            ->sortByDesc(fn (array $row) => abs((float) $row['balance']))
            ->values();

        return [
            'title' => 'أرصدة الزبائن والموردين',
            'summary' => [
                ['title' => 'عدد الحسابات', 'value' => $rows->count()],
                ['title' => 'إلنا', 'value' => round($rows->where('direction', 'receivable')->sum('balance_abs'), 3)],
                ['title' => 'علينا', 'value' => round($rows->where('direction', 'payable')->sum('balance_abs'), 3)],
            ],
            'columns' => ['النوع', 'الاسم', 'الهاتف', 'الرصيد', 'الحالة'],
            'rows' => $rows,
        ];
    }

    private function personBalanceRow(string $type, string $typeLabel, Customer|Seller $person): array
    {
        $balance = (float) ($person->balance ?? 0);

        return [
            'person_type' => $type,
            'person_id' => $person->id,
            'type' => $typeLabel,
            'name' => $person->name,
            'phone' => $person->phone,
            'balance' => round($balance, 3),
            'balance_abs' => round(abs($balance), 3),
            'direction' => $balance >= 0 ? 'receivable' : 'payable',
            'status' => $balance >= 0 ? 'إلنا' : 'علينا',
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

        $rows = $query->orderBy('transaction_date')->orderBy('id')->limit(1500)->get()->map(function (DebtTransaction $transaction) {
            $person = $transaction->customer ?: $transaction->seller;
            return [
                'date' => optional($transaction->transaction_date)->toDateString(),
                'person' => optional($person)->name ?: '-',
                'person_type' => $transaction->customer_id ? 'زبون' : 'مورد',
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'balance_after' => (float) $transaction->balance_after,
                'box' => optional($transaction->box)->name,
                'source' => $this->statementSourceLabel($transaction->source, $transaction->source_id),
                'note' => $transaction->note,
            ];
        });

        return [
            'title' => 'كشف حركات الحساب',
            'summary' => [
                ['title' => 'عدد الحركات', 'value' => $rows->count()],
                ['title' => 'مدين', 'value' => round($rows->where('amount', '>', 0)->sum('amount'), 3)],
                ['title' => 'دائن', 'value' => round(abs($rows->where('amount', '<', 0)->sum('amount')), 3)],
            ],
            'columns' => ['التاريخ', 'الشخص', 'النوع', 'القيمة', 'العملة', 'الرصيد بعد', 'الصندوق', 'المصدر', 'الملاحظة'],
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
                ->limit(1000)
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
                        'currency' => $check->currency,
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
                ->limit(1000)
                ->get()
                ->map(function (OutgoingCheck $check) {
                    return [
                        'direction' => 'صادر',
                        'check_id' => $check->check_id,
                        'bank_name' => $check->bank_name,
                        'person' => optional($check->customer ?: $check->seller)->name ?: '-',
                        'total' => (float) $check->total,
                        'currency' => $check->currency,
                        'due_date' => $this->reportDateString($check->due_date),
                        'status' => $this->checkStatusLabel($check->status),
                    ];
                });
            $rows = $rows->merge($outgoing);
        }

        return [
            'title' => 'الشيكات الصادرة والواردة',
            'summary' => [
                ['title' => 'عدد الشيكات', 'value' => $rows->count()],
                ['title' => 'الواردة', 'value' => round($rows->where('direction', 'وارد')->sum('total'), 3)],
                ['title' => 'الصادرة', 'value' => round($rows->where('direction', 'صادر')->sum('total'), 3)],
            ],
            'columns' => ['الاتجاه', 'رقم الشيك', 'البنك', 'الشخص', 'القيمة', 'العملة', 'الاستحقاق', 'الحالة'],
            'rows' => $rows->values(),
        ];
    }

    private function boxesReportPayload(Request $request, Carbon $from, Carbon $to): array
    {
        $rows = BoxLog::query()
            ->with(['box:id,name', 'fromBox:id,name', 'toBox:id,name'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($request->filled('box_id'), function ($query) use ($request) {
                $query->where(function ($nested) use ($request) {
                    $nested->where('box_id', $request->box_id)
                        ->orWhere('from_box_id', $request->box_id)
                        ->orWhere('to_box_id', $request->box_id);
                });
            })
            ->orderByDesc('created_at')
            ->limit(1500)
            ->get()
            ->map(function (BoxLog $log) {
                $amount = (float) ($log->value ?? $log->transfered_balance ?? 0);
                return [
                    'date' => optional($log->created_at)->toDateTimeString(),
                    'box' => optional($log->box ?: $log->toBox ?: $log->fromBox)->name ?: '-',
                    'from_box' => optional($log->fromBox)->name,
                    'to_box' => optional($log->toBox)->name,
                    'type' => $log->type ?: '-',
                    'amount' => $amount,
                    'description' => $log->description ?: $log->note,
                ];
            });

        return [
            'title' => 'كشف حساب الصناديق',
            'summary' => [
                ['title' => 'عدد الحركات', 'value' => $rows->count()],
                ['title' => 'إجمالي الحركة', 'value' => round($rows->sum('amount'), 3)],
                ['title' => 'رصيد الصناديق الحالي', 'value' => round(Box::where('is_shown', 1)->sum('total'), 3)],
            ],
            'columns' => ['التاريخ', 'الصندوق', 'من صندوق', 'إلى صندوق', 'النوع', 'القيمة', 'الوصف'],
            'rows' => $rows,
        ];
    }

    private function inventoryReportPayload(Carbon $from, Carbon $to): array
    {
        $products = Product::query()
            ->with(['purchasePrices' => fn ($query) => $query->orderByDesc('id')])
            ->orderBy('nameAr')
            ->limit(1500)
            ->get();

        $movementByProduct = Schema::hasTable('product_stock_movements')
            ? DB::table('product_stock_movements')
                ->select('product_id', DB::raw('SUM(quantity) as quantity'))
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->groupBy('product_id')
                ->pluck('quantity', 'product_id')
            : collect();

        $rows = $products->map(function (Product $product) use ($movementByProduct) {
            $stock = (float) ($product->stock ?? 0);
            $unitCost = (float) ($product->purchasePrices->first()?->price ?? 0);
            $periodMovement = (float) ($movementByProduct[$product->id] ?? 0);
            $openingStock = $stock - $periodMovement;

            return [
                'code' => $product->product_code ?: $product->id,
                'product' => $product->nameAr ?: $product->nameEng,
                'quantity' => $stock,
                'unit_cost' => round($unitCost, 3),
                'total_cost' => round($stock * $unitCost, 3),
                'opening_value' => round($openingStock * $unitCost, 3),
                'ending_value' => round($stock * $unitCost, 3),
            ];
        });

        return [
            'title' => 'كميات وقيمة المخزون',
            'summary' => [
                ['title' => 'عدد المنتجات', 'value' => $rows->count()],
                ['title' => 'إجمالي الكمية', 'value' => round($rows->sum('quantity'), 3)],
                ['title' => 'بضاعة أول المدة', 'value' => round($rows->sum('opening_value'), 3)],
                ['title' => 'بضاعة آخر المدة', 'value' => round($rows->sum('ending_value'), 3)],
            ],
            'columns' => ['الكود', 'الصنف', 'الكمية', 'تكلفة الوحدة', 'إجمالي التكلفة', 'أول المدة', 'آخر المدة'],
            'rows' => $rows,
        ];
    }

    private function incomeReportPayload(Carbon $from, Carbon $to): array
    {
        $activeSales = InstantSale::query()
            ->whereNull('parent_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->whereNull('cancelled_at');

        $sales = (float) (clone $activeSales)->sum('total_cost');
        $salesDiscount = (float) (clone $activeSales)->sum('discount');
        $salesReturns = (float) InstantSale::query()
            ->whereNull('parent_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(function ($query) {
                $query->where('status', 'cancelled')->orWhereNotNull('cancelled_at');
            })
            ->sum('total_cost');
        $purchases = (float) Bill::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('total');
        $purchaseReturns = (float) ReturnModel::whereIn('status', ['confirmed', 'pending', 'delivered', 'settled'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('total');
        $earnedDiscount = (float) Bill::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('discount');
        $expenses = (float) Expense::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('price');
        $inventory = $this->inventoryReportPayload($from, $to);
        $openingInventory = (float) collect($inventory['summary'])->firstWhere('title', 'بضاعة أول المدة')['value'];
        $endingInventory = (float) collect($inventory['summary'])->firstWhere('title', 'بضاعة آخر المدة')['value'];
        $netSales = $sales - $salesReturns - $salesDiscount;
        $costOfSales = $openingInventory + $purchases - $purchaseReturns - $earnedDiscount - $endingInventory;
        $grossProfit = $netSales - $costOfSales;
        $netProfit = $grossProfit - $expenses;

        $rows = collect([
            ['account' => 'المبيعات', 'debit' => 0, 'credit' => round($sales, 3)],
            ['account' => 'مردودات المبيعات', 'debit' => round($salesReturns, 3), 'credit' => 0],
            ['account' => 'خصم مسموح به', 'debit' => round($salesDiscount, 3), 'credit' => 0],
            ['account' => 'صافي المبيعات', 'debit' => 0, 'credit' => round($netSales, 3)],
            ['account' => 'بضاعة أول المدة', 'debit' => round($openingInventory, 3), 'credit' => 0],
            ['account' => 'المشتريات', 'debit' => round($purchases, 3), 'credit' => 0],
            ['account' => 'مردودات المشتريات', 'debit' => 0, 'credit' => round($purchaseReturns, 3)],
            ['account' => 'خصم مكتسب', 'debit' => 0, 'credit' => round($earnedDiscount, 3)],
            ['account' => 'بضاعة آخر المدة', 'debit' => 0, 'credit' => round($endingInventory, 3)],
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
            ],
            'columns' => ['الحساب', 'مدين', 'دائن'],
            'rows' => $rows,
        ];
    }

    private function salesReturnsReportPayload(Carbon $from, Carbon $to): array
    {
        $rows = InstantSale::query()
            ->with(['product:id,nameAr,nameEng', 'buyerCustomer:id,name,phone', 'seller:id,name,phone'])
            ->whereNull('parent_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(function ($query) {
                $query->where('status', 'cancelled')->orWhereNotNull('cancelled_at');
            })
            ->orderByDesc('created_at')
            ->limit(1000)
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

        return [
            'title' => 'مردودات المبيعات',
            'summary' => [
                ['title' => 'عدد الفواتير الملغية', 'value' => $rows->count()],
                ['title' => 'قيمة المرتجعات', 'value' => round($rows->sum('total'), 3)],
            ],
            'columns' => ['الرقم', 'التاريخ', 'الزبون', 'الصنف', 'الكمية', 'الإجمالي', 'تاريخ الإلغاء'],
            'rows' => $rows,
        ];
    }

    private function productProfitReportPayload(Carbon $from, Carbon $to): array
    {
        $sales = InstantSale::query()
            ->with(['product.purchasePrices' => fn ($query) => $query->orderByDesc('id')])
            ->whereNull('parent_id')
            ->whereNotNull('product_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->whereNull('cancelled_at')
            ->get();

        $rows = $sales->groupBy('product_id')->map(function ($items) {
            $first = $items->first();
            $unitCost = (float) ($first->product?->purchasePrices->first()?->price ?? 0);
            $quantity = (float) $items->sum('quantity');
            $salesTotal = (float) $items->sum('total_cost');
            $costTotal = $quantity * $unitCost;
            $profit = $salesTotal - $costTotal;
            $profitPercent = $salesTotal > 0 ? ($profit / $salesTotal) * 100 : 0;

            return [
                'code' => $first->product?->product_code ?: $first->product_id,
                'product' => $first->product?->nameAr ?: $first->product?->nameEng,
                'quantity' => round($quantity, 3),
                'sales_total' => round($salesTotal, 3),
                'cost_total' => round($costTotal, 3),
                'profit' => round($profit, 3),
                'profit_percent' => round($profitPercent, 2).'%',
            ];
        })->sortByDesc('profit')->values();

        return [
            'title' => 'نسبة ربح المنتجات',
            'summary' => [
                ['title' => 'عدد المنتجات', 'value' => $rows->count()],
                ['title' => 'إجمالي المبيعات', 'value' => round($rows->sum('sales_total'), 3)],
                ['title' => 'إجمالي التكلفة', 'value' => round($rows->sum('cost_total'), 3)],
                ['title' => 'إجمالي الربح', 'value' => round($rows->sum('profit'), 3)],
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

    public static function fixArabic($reportHtml){
                // 🔹 Fix Arabic text
        $arabic = new Arabic();
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

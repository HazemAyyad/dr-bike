<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Box;
use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use ArPHP\I18N\Arabic;
use App\Models\Debt;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\Expense;
use App\Models\IncomingCheck;
use App\Models\InstantSale;
use App\Models\Log;
use App\Models\OutgoingCheck;
use App\Models\Product;
use App\Models\Project;
use App\Models\ReturnModel;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $totalReturns = ReturnModel::sum('total'); // مردودات المشتريات

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

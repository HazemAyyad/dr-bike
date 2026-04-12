<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Box;
use App\Models\Customer;
use App\Models\Seller;
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
        $totalSales = InstantSale::sum('total_cost'); // اجمالي المبيعات
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

<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DebtTransaction;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\Expense;
use App\Models\InstantSale;
use App\Models\Log;
use App\Models\Product;
use App\Models\Seller;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Logs extends Controller
{
    public static function createLog($name,$description,$type){
        Log::create([
            'name'=>$name,
            'description' =>$description,
            'type'=>$type
        ]);

    }



    private function respondWithLogs(callable $queryCallback)
{
    try {
        $logs = $queryCallback();

        return response()->json([
            'status' => 'success',
            'logs' => $logs,
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


 public function getAllLogs()
{
    return $this->respondWithLogs(function () {
        return Log::where('is_canceled',0)->get();
    });
}

public function activitySummary(Request $request)
{
    try {
        [$from, $to] = $this->activityDateRange($request);

        $logsQuery = Log::query()->where('is_canceled', 0);
        $this->applyCreatedAtRange($logsQuery, $from, $to);

        $logTypeCounts = (clone $logsQuery)
            ->select('type', DB::raw('COUNT(*) as total'))
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type ?: 'غير محدد',
                'count' => (int) $row->total,
            ])
            ->values();

        $salesQuery = InstantSale::query()
            ->whereNull('parent_id')
            ->whereNull('cancelled_at')
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->where(function ($query) {
                $query->whereNull('sale_kind')->orWhere('sale_kind', '!=', 'adjustment');
            });
        $this->applyCreatedAtRange($salesQuery, $from, $to);

        $sales = $salesQuery
            ->select([
                'id',
                'total_cost',
                'payment_box_value',
                'buyer_type',
                'buyer_id',
                'seller_id',
                'buyer_name',
                'created_at',
            ])
            ->get();

        $salesParentIds = $sales->pluck('id')->values();
        $saleLines = collect();
        if ($salesParentIds->isNotEmpty()) {
            $saleLines = InstantSale::query()
                ->with(['product' => function ($query) {
                    $query->withTrashed()->select('id', 'nameAr', 'nameEng');
                }])
                ->where(function ($query) use ($salesParentIds) {
                    $query->whereIn('id', $salesParentIds)
                        ->orWhereIn('parent_id', $salesParentIds);
                })
                ->get(['id', 'parent_id', 'product_id', 'quantity', 'total_cost']);
        }

        $debtQuery = DebtTransaction::query()->active();
        $this->applyCreatedAtRange($debtQuery, $from, $to);
        $debts = $debtQuery
            ->with(['customer:id,name', 'seller:id,name'])
            ->get(['id', 'customer_id', 'seller_id', 'type', 'amount', 'note', 'source', 'source_id', 'created_at']);

        $customerIds = $sales->pluck('buyer_id')->filter()->merge(
            $debts->pluck('customer_id')->filter()
        )->unique()->values();
        $sellerIds = $sales->pluck('seller_id')->filter()->merge(
            $debts->pluck('seller_id')->filter()
        )->unique()->values();

        $customerNames = Customer::query()
            ->whereIn('id', $customerIds)
            ->pluck('name', 'id');
        $sellerNames = Seller::query()
            ->whereIn('id', $sellerIds)
            ->pluck('name', 'id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'range' => [
                    'from' => $from?->toDateString(),
                    'to' => $to?->toDateString(),
                ],
                'totals' => [
                    'logs_count' => (int) (clone $logsQuery)->count(),
                    'log_types_count' => $logTypeCounts->count(),
                    'customers_count' => $customerIds->count(),
                    'people_count' => $customerIds->count() + $sellerIds->count() + $sales->whereNull('buyer_id')->whereNull('seller_id')->whereNotNull('buyer_name')->count(),
                    'invoices_count' => $sales->count(),
                    'sales_count' => $sales->count(),
                    'sales_amount' => round((float) $sales->sum('total_cost'), 2),
                    'paid_amount' => round((float) $sales->sum('payment_box_value'), 2),
                    'remaining_amount' => round(max(0, (float) $sales->sum('total_cost') - (float) $sales->sum('payment_box_value')), 2),
                    'debt_transactions_count' => $debts->count(),
                    'debt_amount' => round((float) $debts->sum('amount'), 2),
                    'debt_given_amount' => round((float) $debts->where('type', 'given')->sum('amount'), 2),
                    'debt_taken_amount' => round((float) $debts->where('type', 'taken')->sum('amount'), 2),
                    'sold_items_quantity' => round((float) $saleLines->sum('quantity'), 2),
                ],
                'log_type_counts' => $logTypeCounts,
                'sales_people' => $this->summarizeSalesByPerson($sales, $customerNames, $sellerNames),
                'debt_people' => $this->summarizeDebtsByPerson($debts),
                'sold_products' => $this->summarizeSoldProducts($saleLines),
            ],
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

public function getEmployeesLogs()
{
    return $this->respondWithLogs(function () {
        return Log::where('type', 'employees')
        ->where('is_canceled',0)->get();
    });

}
  public function cancelLog(Request $request){
    try {
      $request->validate(['log_id'=>'required|exists:logs,id']);
      $log = Log::findOrFail($request->log_id);
      $log->update(['is_canceled'=>1]);
        return response()->json([
            'status' => 'success',
            'message' => __('messages.log_cancelled'),
        ], 200);
    } 
    
     catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    
    catch (QueryException $e) {
        return response()->json([
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


  public function showLog(Request $request){
       try {
      $request->validate(['log_id'=>'required|exists:logs,id']);
      $log = Log::findOrFail($request->log_id);
        return response()->json([
            'status' => 'success',
            'log_details' => $log,
        ], 200);
    } 
    
     catch (ValidationException $e) {
            return response(['status' => 'error', 'message' => __('messages.validation_failed')], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.something_wrong')], 200);
        }
    
    catch (QueryException $e) {
        return response()->json([
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
    
    // admin home page data 
    public function homeData(){
        try{

        $totalDebtsWeOwe = Debt::where('type','we owe')
        ->where('status','unpaid')
        ->sum('total'); // ديون علينا
        $totalDebtsOwedToUs = Debt::where('type','owed to us')
        ->where('status','unpaid')
        ->sum('total'); // ديون لنا
   
        $totalProducts = Product::count();
        $numberOfEmployees = EmployeeDetail::count(); // عدد الموظفين
        $totalCompletedTasks = EmployeeTask::where('status', 'completed')
            ->where('parent_id', NULL)
            ->count();
        $totalIncompletedTasks = EmployeeTask::where('status','!=', 'completed')
            ->where('parent_id', NULL)
            ->count();
        $totalExpenses = Expense::sum('price'); // اجمالي المصاريف

     return response()->json([
            'status'=>'success',
            'data' => [
                'total_debts_we_owe' => $totalDebtsWeOwe,
                'total_debts_owed_to_us' => $totalDebtsOwedToUs,
                'total_products' => $totalProducts,
                'number_of_employees' => $numberOfEmployees,
                'total_expenses' => $totalExpenses,
                'total_completed_tasks' => $totalCompletedTasks,
                'total_incompleted_tasks' => $totalIncompletedTasks,
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

private function activityDateRange(Request $request): array
{
    $from = $request->filled('date_from')
        ? Carbon::parse($request->date_from)->startOfDay()
        : null;
    $to = $request->filled('date_to')
        ? Carbon::parse($request->date_to)->endOfDay()
        : null;

    return [$from, $to];
}

private function applyCreatedAtRange($query, ?Carbon $from, ?Carbon $to): void
{
    if ($from) {
        $query->where('created_at', '>=', $from);
    }

    if ($to) {
        $query->where('created_at', '<=', $to);
    }
}

private function summarizeSalesByPerson($sales, $customerNames, $sellerNames)
{
    return $sales
        ->groupBy(function ($sale) {
            if ($sale->buyer_id) {
                return 'customer:' . $sale->buyer_id;
            }
            if ($sale->seller_id) {
                return 'seller:' . $sale->seller_id;
            }
            return 'walkin:' . ($sale->buyer_name ?: 'غير محدد');
        })
        ->map(function ($group, $key) use ($customerNames, $sellerNames) {
            [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
            $first = $group->first();
            $name = $first->buyer_name ?: 'غير محدد';
            if ($type === 'customer') {
                $name = $customerNames[$id] ?? $name;
            } elseif ($type === 'seller') {
                $name = $sellerNames[$id] ?? $name;
            }

            return [
                'person_type' => $type,
                'person_id' => is_numeric($id) ? (int) $id : null,
                'name' => $name,
                'invoices_count' => $group->count(),
                'sales_amount' => round((float) $group->sum('total_cost'), 2),
                'paid_amount' => round((float) $group->sum('payment_box_value'), 2),
                'remaining_amount' => round(max(0, (float) $group->sum('total_cost') - (float) $group->sum('payment_box_value')), 2),
            ];
        })
        ->sortByDesc('sales_amount')
        ->take(10)
        ->values();
}

private function summarizeDebtsByPerson($debts)
{
    return $debts
        ->groupBy(function ($debt) {
            if ($debt->customer_id) {
                return 'customer:' . $debt->customer_id;
            }
            if ($debt->seller_id) {
                return 'seller:' . $debt->seller_id;
            }
            return 'unknown:0';
        })
        ->map(function ($group, $key) {
            [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
            $first = $group->first();
            $name = $first->customer?->name ?? $first->seller?->name ?? 'غير محدد';

            return [
                'person_type' => $type,
                'person_id' => is_numeric($id) ? (int) $id : null,
                'name' => $name,
                'transactions_count' => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
                'given_amount' => round((float) $group->where('type', 'given')->sum('amount'), 2),
                'taken_amount' => round((float) $group->where('type', 'taken')->sum('amount'), 2),
                'last_note' => optional($group->sortByDesc('created_at')->first())->note,
            ];
        })
        ->sortByDesc('amount')
        ->take(10)
        ->values();
}

private function summarizeSoldProducts($saleLines)
{
    return $saleLines
        ->whereNotNull('product_id')
        ->groupBy('product_id')
        ->map(function ($group, $productId) {
            $product = $group->first()->product;

            return [
                'product_id' => $productId,
                'name' => $product?->nameAr ?? $product?->nameEng ?? ('منتج #' . $productId),
                'quantity' => round((float) $group->sum('quantity'), 2),
                'sales_amount' => round((float) $group->sum('total_cost'), 2),
                'lines_count' => $group->count(),
            ];
        })
        ->sortByDesc('quantity')
        ->take(10)
        ->values();
}
}

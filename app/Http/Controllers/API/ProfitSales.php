<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Box;
use App\Models\Customer;
use App\Models\ProfitSale;
use App\Services\DebtLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class ProfitSales extends Controller
{
    private string $profitSaleMediaPath = 'profit-sale-media';

    private function storeProfitSaleFile(Request $request, string $field): ?string
    {
        if (! $request->hasFile($field)) {
            return null;
        }

        $file = $request->file($field);
        $name = uniqid($field.'_').'.'.$file->getClientOriginalExtension();
        $file->move(public_path($this->profitSaleMediaPath), $name);

        return 'public/'.$this->profitSaleMediaPath.'/'.$name;
    }

    public function store(Request $request)
 {
    try{
    $data = $request->validate([
        'total_cost' => 'required|numeric|min:0',
        'notes' => 'nullable|string',
        'image' => 'nullable|image|max:10240',
        'video' => 'nullable|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska|max:51200',
        'buyer_type' => 'nullable|string|in:customer,seller,unknown',
        'buyer_id' => 'nullable|integer|exists:customers,id',
        'seller_id' => 'nullable|integer|exists:sellers,id',
        'buyer_name' => 'nullable|string|max:255',
        'buyer_phone' => 'nullable|string|max:30',
        'payment_box_id' => 'nullable|integer|exists:boxes,id',
        'payment_box_name' => 'nullable|string|max:255',
        'payment_box_value' => 'nullable|numeric|min:0',
    ]);

    unset($data['image'], $data['video']);
    $data['image_path'] = $this->storeProfitSaleFile($request, 'image');
    $data['video_path'] = $this->storeProfitSaleFile($request, 'video');
    $buyerType = $request->input('buyer_type', 'unknown');
    $buyerName = trim((string) $request->input('buyer_name', ''));
    $buyerPhone = trim((string) $request->input('buyer_phone', ''));

    $profitSale = DB::transaction(function () use ($data, $request, $buyerType, $buyerName, $buyerPhone) {
        unset($data['buyer_id'], $data['buyer_phone']);

        if ($buyerType === 'customer') {
            if ($request->filled('buyer_id')) {
                $data['customer_id'] = (int) $request->input('buyer_id');
            } elseif ($buyerName !== '') {
                $customer = Customer::firstOrCreate(
                    $buyerPhone !== '' ? ['phone' => $buyerPhone] : ['name' => $buyerName],
                    [
                        'name' => $buyerName,
                        'phone' => $buyerPhone !== '' ? $buyerPhone : null,
                        'ID_image' => [],
                        'license_image' => [],
                    ]
                );
                $data['customer_id'] = $customer->id;
                $data['buyer_name'] = $customer->name;
            }
        } elseif ($buyerType === 'seller' && $request->filled('seller_id')) {
            $data['seller_id'] = (int) $request->input('seller_id');
        } else {
            unset($data['customer_id'], $data['seller_id']);
            $data['buyer_type'] = 'unknown';
        }

        $data['payment_box_value'] = (float) ($data['payment_box_value'] ?? 0);
        if (! $request->filled('payment_box_id')) {
            unset($data['payment_box_id'], $data['payment_box_name']);
        }

        $profitSale = ProfitSale::create($data);

        if ($profitSale->payment_box_id && $profitSale->payment_box_value > 0) {
            $box = Box::lockForUpdate()->find($profitSale->payment_box_id);
            if ($box) {
                $box->total = (float) ($box->total ?? 0) + (float) $profitSale->payment_box_value;
                $box->save();

                BoxLogs::createBoxLog(
                    $box,
                    'قبض — بيع ربحي',
                    'add',
                    $profitSale->payment_box_value,
                    'قبض بيع ربحي #'.$profitSale->id.' بقيمة '.number_format((float) $profitSale->payment_box_value, 2, '.', '')
                );
            }
        }

        app(DebtLedgerService::class)->syncProfitSaleToLedger($profitSale->fresh(['paymentBox']));

        return $profitSale;
    });


        Logs::createLog('اضافة ربح نقدي جديد','اضافة ربح نقدي جديد','profit_sales');
        return response()->json([
                    'status' => 'success',
                    'message' => __('messages.profit_sale_created_successfully')
                ], 200);

            }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 200);
        }

}

public function getProfitSales()
{
    try {
        $profitSales = ProfitSale::all();
        return response()->json([
            'status' => 'success',
            'profit_sales' => $profitSales,
        ], 200);
    } catch (QueryException $e) {
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

    public function showProfitSale(Request $request){
        try{
            $request->validate(['profit_sale_id'=>'required|exists:profit_sales,id']);
            $profitSale = ProfitSale::findOrFail($request->profit_sale_id)
            ;

            return response()->json([
                'status'=>'success',
                'profit_sale_details' => $profitSale,
            ],200);
    }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }

    
        catch (QueryException $e) {
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

    public function edit(Request $request){
        try{
        $data =  $request->validate([
            'profit_sale_id'=>'required|exists:instant_sales,id',
            'total_cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string',

        ]);

        $profitSale = ProfitSale::findOrFail($request->profit_sale_id);
        $profitSale->update($data);
        Logs::createLog('تعديل ربح نقدي ','تم تعديل ربح نقدي ','profit_sales');


    }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()

            ], 200);
        }
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        }

    
        catch (QueryException $e) {
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

}

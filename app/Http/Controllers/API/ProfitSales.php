<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ProfitSale;
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
    ]);

    unset($data['image'], $data['video']);
    $data['image_path'] = $this->storeProfitSaleFile($request, 'image');
    $data['video_path'] = $this->storeProfitSaleFile($request, 'video');

    ProfitSale::create($data);


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
                'message' => __('messages.create_data_error')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
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

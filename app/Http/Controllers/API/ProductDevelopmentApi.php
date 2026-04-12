<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductDevelopment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductDevelopmentApi extends Controller
{
    public function create(Request $request){

        try{
            $data = $request->validate([
                'product_id'=>'required|integer|exists:products,id',
                'description'=>'nullable|string',
            ]);

            $prodev =ProductDevelopment::create($data);
            Logs::createLog(
                'تطوير منتج',
                'تم اضافة المنتج ' . ($prodev->product->nameAr ?? 'لا اسم') . ' الى قائمة تطوير المنتجات',
                'product_developments'
            );


            return response()->json([
                'status'=>'success',
                'message'=>__('messages.prodev_created'),
            ],200);
        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

    }

    public function showProDev(Request $request){
        try{
            $request->validate(['product_development_id'=>'required|integer|exists:product_development,id',
        ]);

        $prodev = ProductDevelopment::with('product:id,nameAr')
        ->findOrFail($request->product_development_id);

        $image = $prodev->product->viewImages->first();
        $formatted = [
            'id' => $prodev->id,

            'product_name' => $prodev->product->nameAr??'no name',
            'product_image' => $image ? env('STORE_DOMAIN').$image->imageUrl : 'no image',
            'description' => $prodev->description,
            'current_step' => $prodev->step,

        ];

        return response()->json([
            'status'=>'success',
            'product_development' => $formatted
        ],200);

        }

                   catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } 
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        
         } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }
    public function updateDev(Request $request){
        try{

            $data = $request->validate([
                'product_development_id'=>'required|integer|exists:product_development,id',
                'step'=>'required|integer|in:2,3,4,5,6,7',
            ]);

            $prodev = ProductDevelopment::findOrFail($data['product_development_id']);
            $prodev->update(['step'=>$data['step']]);
            Logs::createLog(
                'تحديث خطوة تطوير منتج',
                'تم تحديث خطوة تطوير المنتج ' 
                    . ($prodev->product->nameAr ?? 'لا اسم') 
                    . ' الى الخطوة رقم ' 
                    . $data['step'],
                'product_developments'
            );


            return response()->json([
                'status'=>'success',
                'message'=>__('messages.prodev_step_updated'),
            ],200);

        }

              catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } 
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        
         } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

    }

    public function allProDevs(){
        try{

            $proDevs = ProductDevelopment::with('product:id,nameAr')
            ->get();

            $formatted = $proDevs->map(function($dev){
                $image = $dev->product->viewImages->first();

                return [
                    'id'=> $dev->id,
                    'product_name' => $dev->product->nameAr??'no name',
                    'product_image' => $image ? env('STORE_DOMAIN').$image->imageUrl : 'no image',
                    'current_step' => $dev->step,  
                    'description' => $dev->description,
                              ];
            });

            return response()->json([
                'status'=>'success',
                'product_developments' => $formatted,
            ],200);
        }

        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } 
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
    }
}
}

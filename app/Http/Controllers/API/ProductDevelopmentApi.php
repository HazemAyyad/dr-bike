<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductDevelopment;
use App\Models\ProductDevelopmentActivityLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductDevelopmentApi extends Controller
{
    private function logActivity(Request $request, ProductDevelopment $prodev, string $action, string $description, ?array $changes = null): void
    {
        ProductDevelopmentActivityLog::create([
            'product_development_id' => $prodev->id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'description' => $description,
            'changes' => $changes,
        ]);
    }

    private function formatActivityLogs(ProductDevelopment $dev)
    {
        return $dev->activityLogs->map(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'changes' => $log->changes,
            'user_id' => $log->user_id,
            'user_name' => $log->user?->name ?? 'غير معروف',
            'user_type' => $log->user?->type,
            'created_at' => optional($log->created_at)->format('Y-m-d H:i'),
        ])->values();
    }

    public function create(Request $request){

        try{
            $data = $request->validate([
                'product_id'=>'required|integer|exists:products,id',
                'description'=>'nullable|string',
            ]);

            $prodev = ProductDevelopment::where('product_id', $data['product_id'])
                ->where('step', '<', 7)
                ->first();

            if ($prodev) {
                $oldDescription = $prodev->description;
                $prodev->update([
                    'description' => $data['description'] ?? $prodev->description,
                ]);
                $this->logActivity(
                    $request,
                    $prodev,
                    'updated',
                    'تم تعديل تفاصيل تطوير المنتج',
                    ['description' => ['old' => $oldDescription, 'new' => $prodev->description]]
                );
            } else {
                $prodev = ProductDevelopment::create($data);
                $this->logActivity(
                    $request,
                    $prodev,
                    'created',
                    'تم إنشاء تطوير المنتج'
                );
            }

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

        $prodev = ProductDevelopment::with([
            'product:id,nameAr,rate',
            'activityLogs.user:id,name,type',
        ])
        ->findOrFail($request->product_development_id);

        $image = $prodev->product->viewImages->first();
        $formatted = [
            'id' => $prodev->id,
            'product_id' => $prodev->product_id,

            'product_name' => $prodev->product->nameAr??'no name',
            'rate' => (float) ($prodev->product->rate ?? 0),
            'product_image' => $image ? \App\Support\ApiImageUrl::normalize($image->imageUrl) : 'no image',
            'description' => $prodev->description,
            'current_step' => $prodev->step,
            'activity_logs' => $this->formatActivityLogs($prodev),

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
            $oldStep = $prodev->step;
            $prodev->update(['step'=>$data['step']]);
            $this->logActivity(
                $request,
                $prodev,
                'updated',
                'تم تحديث مرحلة تطوير المنتج',
                ['step' => ['old' => $oldStep, 'new' => $data['step']]]
            );
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

    public function deleteDev(Request $request){
        try{
            $data = $request->validate([
                'product_development_id'=>'required|integer|exists:product_development,id',
            ]);

            $prodev = ProductDevelopment::with('product:id,nameAr')->findOrFail($data['product_development_id']);
            $productName = $prodev->product->nameAr ?? 'لا اسم';
            $this->logActivity(
                $request,
                $prodev,
                'deleted',
                'تم حذف المنتج من قائمة التطوير'
            );
            $prodev->delete();

            Logs::createLog(
                'حذف منتج من التطوير',
                'تم حذف المنتج ' . $productName . ' من قائمة تطوير المنتجات',
                'product_developments'
            );

            return response()->json([
                'status'=>'success',
                'message'=>'تم حذف المنتج من التطوير بنجاح',
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

            $proDevs = ProductDevelopment::with([
                'product:id,nameAr,rate',
                'activityLogs.user:id,name,type',
            ])
            ->get();

            $formatted = $proDevs->map(function($dev){
                $image = $dev->product?->viewImages->first();

                return [
                    'id'=> $dev->id,
                    'product_id' => $dev->product_id,
                    'product_name' => $dev->product->nameAr??'no name',
                    'rate' => (float) ($dev->product->rate ?? 0),
                    'product_image' => $image ? \App\Support\ApiImageUrl::normalize($image->imageUrl) : 'no image',
                    'current_step' => $dev->step,  
                    'description' => $dev->description,
                    'activity_logs' => $this->formatActivityLogs($dev),
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

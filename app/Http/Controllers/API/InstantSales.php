<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\InstantSale;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\isEmpty;

class InstantSales extends Controller
{




public function store(Request $request)
 {
    try{
    $data = $request->validate([
        'product_id' => 'required|exists:products,id',
        'quantity' => 'required|numeric|min:1',
        'cost' => 'required|numeric|min:0',
        'discount' => 'required|numeric|min:0',
        'total_cost' => 'required|numeric|min:0',

        'notes' => 'nullable|string',

        'type' => 'required|string|in:normal,project',
        'project_id' => 'nullable|exists:projects,id',

        'other_products' => 'nullable|array',
        'other_products.*.product_id' => 'required|exists:products,id',
        'other_products.*.cost' => 'required|numeric|min:0',
        'other_products.*.quantity' => 'required|numeric|min:1',
        'other_products.*.type' => 'required|string|in:normal,project',
        'other_products.*.project_id' => 'nullable|exists:projects,id',

    ]);


        $otherNames = [];


        // Save main instant sale
        $mainData = collect($data)->except('other_products')->toArray();

        $mainProduct = Product::findOrFail($mainData['product_id']);

        $mainSaleQuantity = $request->quantity;
        if( ($mainSaleQuantity > $mainProduct->stock) || ($mainProduct->stock <= 0) ){
            return response()->json([
                'status'=>'error',
                'message'=>__('messages.cant_sale'),
            ],200);
        }



        if ($request->has('other_products')) {

        foreach ($data['other_products'] as $item) {
            $product = Product::find($item['product_id']);
            $otherNames[] = $product->nameAr?? 'بدون اسم';
            if (($product->stock <= 0) || ($item['quantity'] > $product->stock)) {
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.cant_sale'),
                    ],200);
            } 
        }
    }
        $productProjects = $mainProduct->projects;


        if($mainData['type']==='project' && $productProjects->isEmpty()){
            return response()->json([
                'status'=>'error',
                'message'=>__('messages.cant_be_project_type'),
            ],200);
        }

        $mainInstantSale = InstantSale::create($mainData);

        $mainProduct->stock -= $mainInstantSale->quantity;
        $mainProduct->save();
        if ($mainProduct->stock === 0) {
                $closeout = $mainProduct->closeout;

                if ($closeout) { // check if it exists
                    $closeout->status = 'archived'; 
                    $closeout->save();
                }
            }

        // Save other  if provided
        if ($request->has('other_products')) {
            foreach ($request->other_products as  $product) {
                $subProduct = Product::findOrFail($product['product_id']);
                $subProductProjects = $subProduct->projects;


                if($product['type']==='project' && $subProductProjects->isEmpty()){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.cant_be_project_type'),
                    ],200);
                }        
                InstantSale::create([
                    'product_id' => $product['product_id'],
                    'cost' => $product['cost'],
                    'quantity' => $product['quantity'],
                    'parent_id' => $mainInstantSale->id,
                    'type' => $product['type'],
                    'project_id' => $product['project_id']?? null,
                ]);

                $subProduct->stock -= $product['quantity'];
                $subProduct->save();
                if ($subProduct->stock === 0) {
                        $closeout = $subProduct->closeout;

                         if ($closeout) { // check if it exists
                                    $closeout->status = 'archived'; 
                                    $closeout->save();
                                }
                            }
            }
        }
     $logDescription = "اضافة بيع فوري جديد للمنتج: " . ($mainInstantSale->product->nameAr ?? 'بدون اسم');
     if(count($otherNames)>0){
             $logDescription .= " مع منتجات إضافية: " . implode(", ", $otherNames);

     }
     $logDescription .= " بإجمالي تكلفة: " . $mainInstantSale->total_cost??0;

        Logs::createLog('اضافة بيع فوري جديد',
        $logDescription,
        'instant_sales');
        return response()->json([
                    'status' => 'success',
                    'message' => __('messages.instant_sale_created_successfully')
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


    // get the projects of a product for chosing that product in the instant sale
    public function getProjectsOfProduct(Request $request){
        try{
            $request->validate(['product_id'=>'required|exists:products,id']);

            $product = Product::findOrFail($request->product_id);
            $productProjects = $product->projects ;
            $projects = $productProjects->map(function($productProject){
                return [
                    'project_id' => $productProject->project->id,
                    'project_name' => $productProject->project->name,
                ];
            });
            return response()->json([
                'status'=>'success',
                'projects' => $projects,
            ]);

        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
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

    // get sub sales of parent sale
    public function getSubSales(Request $request){
      try{
            $request->validate(['instant_sale_id'=>'required|exists:instant_sales,id']);

            $sale = InstantSale::findOrFail($request->instant_sale_id);
            $subSales =  $sale->subProducts->map(function ($sub) {
                    return [
                        'id' => $sub->id,
                        'product_id' => $sub->product->nameAr,
                        'cost' => $sub->cost,
                        'quantity'=> $sub->quantity,

                    ];
                });
         return response()->json([
            'status'=>'success',
            'sub_sales'=> $subSales,
         ],200);
    }
            catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
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
    public function attachProjectToProductInSale(Request $request){
       try{
            $request->validate([
                'instant_sale_id'=>'required|exists:instant_sales,id',
                'project_id' => 'required|exists:projects,id',
            ]);

            $sale = InstantSale::findOrFail($request->instant_sale_id);
            $sale->update(['project_id'=> $request->project_id]);
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.sale_attached'),
            ]);

    }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
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
   public function getInstantSales()
{
    try {
        $instantSales = InstantSale::whereNull('parent_id')
            ->with([
                'product:id,nameAr',
                'subProducts.product:id,nameAr',
            ])
            ->latest()
            ->get();

        $formatted = $instantSales->map(function ($sale) {
            return [
                'id' => $sale->id,
                'product' => optional($sale->product)->nameAr ?? 'منتج محذوف',
                'cost' => $sale->cost,
                'total_cost' => $sale->total_cost,
                'quantity' => $sale->quantity,
                'notes' => $sale->notes,
                'date' => optional($sale->created_at)->format('Y-m-d'),
                'sub_products' => $sale->subProducts->map(function ($sub) {
                    return [
                        'id' => $sub->id,
                        'product_name' => optional($sub->product)->nameAr ?? 'منتج محذوف',
                        'cost' => $sub->cost,
                        'quantity' => $sub->quantity,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'instant_sales' => $formatted,
        ], 200);

    } catch (\Throwable $e) {
        \Log::error('getInstantSales error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
            'debug' => config('app.debug') ? $e->getMessage() : null,
        ], 200);
    }
}

    public function showInstantSale(Request $request){
        try{
            $request->validate(['instant_sale_id'=>'required|exists:instant_sales,id']);
            $instantSale = InstantSale::findOrFail($request->instant_sale_id)
            ->with('product:id,nameAr');

            return response()->json([
                'status'=>'success',
                'instant_sale_details' => $instantSale,
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
            'instant_sale_id'=>'required|exists:instant_sales,id',
            'cost' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0',
            'total_cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string',

        ]);

        $instantSale = InstantSale::findOrFail($request->instant_sale_id);
        $instantSale->update($data);
        Logs::createLog('تعديل بيع فوري ','تم تعديل بيع فوري ','instant_sales');


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

    public function invoiceDetails(Request $request){
        try{

            $request->validate(['instant_sale_id'=>'required|integer|exists:instant_sales,id']);

            $sale = InstantSale::
            with('product:id,nameAr')->
            findOrFail($request->instant_sale_id);

            $mainProImage = $sale->product->viewImages->first();
            $formatted = [
                'id' => $sale->id,
                'product' => $sale->product->nameAr,
                'product_image' => $mainProImage ? env('STORE_DOMAIN').$mainProImage->imageUrl : 'no image',

                'cost' => $sale->cost,
                'quantity'=> $sale->quantity,

                'total_cost' => $sale->total_cost,
                'discount'=> $sale->discount??0,

                'sub_products' => $sale->subProducts->map(function ($sub) {
                    $img = $sub->product->viewImages->first();

                    return [
                        'id' => $sub->id,
                        'product_name' => $sub->product->nameAr,
                        'product_image' => $img ? env('STORE_DOMAIN').$img->imageUrl : 'no image',

                        'cost' => $sub->cost,
                        'quantity'=> $sub->quantity, ];
            ;
        }),
             ];

             return response()->json([
                'status'=>'success',
                'instant_sale_invoice'=>$formatted,
             ],200);
        }
         catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),

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
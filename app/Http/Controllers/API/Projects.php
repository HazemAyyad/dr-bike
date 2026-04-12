<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Partnership;
use App\Models\Project;
use App\Models\ProjectProduct;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Projects extends Controller
{

    public $projectImagesPath = 'Projects/Images';
    public $partnershipPapersPath = 'Projects/PartnershipPapers';

    public function createProject(Request $request)
{
    try{
        $data = $request->validate( [
            'name'                => 'required|string|max:255',
            'project_cost'        => 'required|numeric|min:0',
            'images'            => 'nullable|array',
            'images.*'            => 'required|file',
            'notes'               => 'nullable|string',
            'partnership_papers' => 'nullable|array',
            'partnership_papers.*' => 'required|file',
            'customer_id'  => 'nullable|exists:customers,id',
            'seller_id'  => 'nullable|exists:sellers,id',
            'share'        => 'nullable|numeric|min:0',
             'partnership_percentage' => 'nullable|numeric|min:0',

            'products' =>'nullable|array',
            'products.*.product_id'  => 'required|exists:products,id',


            'payment_method'      => 'nullable|string|max:100',
            'payment_notes'       => 'nullable|string',

        ]);

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }

        if (is_null($request->customer_id) && is_null($request->seller_id)) {
            if ($request->filled('share') || $request->filled('partnership_percentage')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.cannot_add_share_or_percentage')
                ], 200);        
            
            }
    }

        $imageNames = $this->mediaHelper($request,'images',$this->projectImagesPath);
        $partnershipPapers = $this->mediaHelper($request,'partnership_papers',$this->partnershipPapersPath);



        // Create the project
        $project = Project::create([
            'name'                => $request->name,
            'project_cost'        => $request->project_cost,
            'images'              => $imageNames,
            'partnership_papers' => $partnershipPapers,
            'payment_method'      => $request->payment_method,
            'payment_notes'       => $request->payment_notes,
            'notes'               => $request->notes,
        ]);

        // If partners were included, attach them
        if ($request->filled('customer_id') || $request->filled('seller_id') ) {
                Partnership::create([
                    'project_id'            => $project->id,
                    'customer_id'            => $request->customer_id?? null,
                    'seller_id'              => $request->seller_id?? null,

                    'share'                 => $request->share?? 0,
                    'partnership_percentage'=> $request->partnership_percentage? $request->partnership_percentage/100 : 0,
                    'type' => 'project',
                ]);
            
        }

        if($request->filled('products')){
            foreach($request->products as $product){
                $projectProduct = ProjectProduct::where('project_id',$project->id)
                ->where('product_id',$product['product_id'])->first();
                
                if($projectProduct){
                    return response()->json([
                        'status'=>'error',
                        'message'=>__('messages.project_already_has_product'),
                    ],200);
                }
                ProjectProduct::create([
                    'project_id'=>$project->id,
                    'product_id' => $product['product_id'],
                ]);
            }
        }

        Logs::createLog('اضافة مشروع جديد','اضافة مشروع جديد باسم'.' '.$project->name
        .' '.'وتكلفة'.' '.$project->project_cost
        ,'projects');
                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.project_created_successfully'),
                ], 200);

    } catch (ValidationException $e) {
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
                'message' => __('messages.failed_to_create_project')
            ], 200);
        }


}

    // store images array files
    private function mediaHelper(Request $request, String $fileName , String $path){
        $imageNames = [];

        if ($request->hasFile($fileName)) {
            foreach ($request->file($fileName) as $image) {
                $filename = $image->getClientOriginalName();
                $image->move(public_path($path), $filename);
                $imageNames[] = $filename;
            }
        }
        return $imageNames;
    }

  

public function showProjectDetails(Request $request){
    try{
    $request->validate(['project_id'=>'required|exists:projects,id']);
    $project = Project::with([
        'partnership:id,customer_id,seller_id,project_id,share,partnership_percentage'
        ,
        'partnership.customer:id,name',
        'partnership.seller:id,name',

        'products.product:id,nameAr',

    ])->findOrFail($request->project_id);
    

        $images=[];
        if($project->images && count($project->images)>0){
            foreach($project->images as $image){
                $images[] = 'public/'.$this->projectImagesPath.'/'.$image;
            }
        }
        $project['images'] = $images;

        $partnershipPapers=[];
        if($project->partnership_papers && count($project->partnership_papers)>0){
            foreach($project->partnership_papers as $image){
                $partnershipPapers[] = 'public/'.$this->partnershipPapersPath.'/'.$image;
            }
        }
        $project['partnership_papers'] = $partnershipPapers;

        $partnership = [];
        $products = [];

        if($project->partnership){
            $partnership = [
                'customer_id' => $project->partnership->customer_id?? null,
                'customer_name' => $project->partnership->customer? $project->partnership->customer->name:null,
                'seller_id' => $project->partnership->seller_id?? null,
                'seller_name' => $project->partnership->seller? $project->partnership->seller->name:null,
                'share' => $project->partnership->share?? null,
                'partnership_percentage' => $project->partnership->partnership_percentage?? null,

            ];
        }

        $products = $project->products->map(function($projectProduct){
            return [
                'product_id'=> $projectProduct->product->id,
                'product_name' => $projectProduct->product->nameAr,
            ];
        });

        $project['partnership'] = $partnership;
        $project['products'] = $products;


        $project->unsetRelation('partnership');
        $project->unsetRelation('products');

           return response()->json([
                'status' => 'success',
                'project' => $project,
                
            ], 200);

            } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.project_not_found')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_project_details')
            ], 200);
        }
    
            
}

    public function editProject(Request $request){
        try{
            $data = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'name'                => 'required|string|max:255',
            'project_cost'        => 'required|numeric|min:0',
            'images'            => 'nullable|array',          
            'images.*'            => 'nullable',
          
            'notes'               => 'nullable|string',
            'partnership_papers' => 'nullable|array',           
            'partnership_papers.*' => 'nullable',
            'customer_id'  => 'nullable|exists:customers,id',
            'seller_id'  => 'nullable|exists:sellers,id',
            'share'     => 'nullable|numeric|min:0',
            'partnership_percentage' => 'nullable|numeric|min:0',

            'products' =>'required|array',
            'products.*.product_id'  => 'required|exists:products,id',


            'payment_method'      => 'nullable|string|max:100',
            'payment_notes'       => 'nullable|string',
            ]);

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }

        if (is_null($request->customer_id) && is_null($request->seller_id)) {
            if ($request->filled('share') || $request->filled('partnership_percentage')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.cannot_add_share_or_percentage')
                ], 200);        
            
            }
    }

    $project = Project::findOrFail($request->project_id);
    $partnership = $project->partnership;
    if($request->filled('customer_id')|| $request->filled('seller_id')){
        if($partnership){
            $partnership->update([
                    'customer_id'            => $request->customer_id?? null,
                    'seller_id'              => $request->seller_id?? null,

                    'share'                 => $request->share?? 0,
                    'partnership_percentage'=> $request->partnership_percentage?$request->partnership_percentage/100 :0,
            ]);
        }else{
                Partnership::create([
                    'project_id'            => $project->id,
                    'customer_id'            => $request->customer_id?? null,
                    'seller_id'              => $request->seller_id?? null,

                    'share'                 => $request->share?? 0,
                    'partnership_percentage'=> $request->partnership_percentage?$request->partnership_percentage/100 :0,
                    'type' => 'project',
                ]);
        }
    }
    else{
        if($partnership){
            $partnership->delete();
        }
    }



        $existingProductsIds = ProjectProduct::where('project_id', $project->id)
            ->pluck('product_id')
            ->toArray();

        $newProductIds = collect($request->input('products', []))
            ->pluck('product_id')
            ->toArray();
        $newProductIds = [];
        foreach($request->products as $product){
            $newProductIds[] = $product['product_id'];
        }
        $toAdd = array_diff($newProductIds, $existingProductsIds);
        $toDelete = array_diff($existingProductsIds, $newProductIds);

        // Delete unchecked permissions
        if (!empty($toDelete)) {
            ProjectProduct::where('project_id', $project->id)
                ->whereIn('product_id', $toDelete)
                ->delete();
        }

        // Add newly checked permissions
    if (!empty($toAdd)) {
        foreach ($toAdd as $productIds) {
             $projectProduct = ProjectProduct::where('project_id',$project->id)
            ->where('product_id',$productIds)->first();
            
            if($projectProduct){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.project_already_has_product'),
                ],200);
            }
            ProjectProduct::create([
                'project_id' => $project->id,
                'product_id' => $productIds,
            ]);
        }
    }

        $imageNames = CommonUse::handleImageUpdate($request,'images',$this->projectImagesPath,$project->images);
        $partnershipPapers =CommonUse::handleImageUpdate($request,'partnership_papers',$this->partnershipPapersPath,$project->partnership_papers);

  
        $project->update([
            'name'                => $request->name,
            'project_cost'        => $request->project_cost,
            'images'              => $imageNames,
            'partnership_papers' => $partnershipPapers,
            'payment_method'      => $request->payment_method,
            'payment_notes'       => $request->payment_notes,
            'notes'               => $request->notes,
        ]);
    
        return response()->json([
            'status'=>'success',
            'message'=>__('messages.project_updated'),
        ],200);

 } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()

            ], 200);
        } 
        
        
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.project_not_found')
            ], 200);
        }
        
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
          }  catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function addProductToProject(Request $request){
        try{

            $request->validate([
                'project_id'=>'required|integer|exists:projects,id',
                'product_id'=>'required|integer|exists:products,id',

            ]);

            $projectProduct = ProjectProduct::where('project_id',$request->project_id)
            ->where('product_id',$request->product_id)->first();
            
            if($projectProduct){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.project_already_has_product'),
                ],200);
            }

            ProjectProduct::create([
                'project_id'=> $request->project_id,
                'product_id' => $request->product_id,
            ]);
            return response()->json([
                'status'=>'success',
                'message' => __('messages.product_added_to_project'),
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
                'message' => __('messages.project_not_found')
            ], 200);
        }
        
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
          }  catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function completeProject(Request $request){
        try{
            $request->validate(['project_id'=>'required|exists:projects,id']);

            $project = Project::findOrFail($request->project_id);
            $project->update(['status'=>'completed',
            'achievement_percentage'=>1]);


            Logs::createLog('الانتهاء من مشروع ','تم الانتهاء مشروع باسم'.' '.$project->name,'projects');

            return response()->json([
                'status'=>'success',
                'message' => __('messages.project_completed'),
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
                'message' => __('messages.project_not_found')
            ], 200);
        }
        
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
          }  catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }


    private function getProjectSales(Project $project){

            $sales = $project->instantSales;
            $total = 0;
            $sales->map( function($sale) use(&$total){
                $total+= $sale->total_cost; 
            });

            return $total;

    }
    public function ongoingProjects(){
        try{
        $ongoingProjects = Project::where('status','ongoing')
        ->get(['id','name','achievement_percentage','project_cost','status']);
        
        //new
        $ongoingProjects->map(function($project){
            $totalSales = $this->getProjectSales($project);
            if($project->project_cost && $project->project_cost>0){
                $project['achievement_percentage'] =
                ($totalSales/$project->project_cost)*100;
            }
            else{
             $project['achievement_percentage'] =0;   
            }
            $project->unsetRelation('instantSales');
        });
        //end new

        return response()->json([
                'status' => 'success',
                'ongoing projects' => $ongoingProjects
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_ongoing_projects')
            ], 200);
        }

    }


    public function completedProjects(){

        try{
        $completedProjects = Project::where('status','completed')
        ->get(['id','name','achievement_percentage','project_cost','status']);

        $completedProjects->map(function($project){
            $totalSales = $this->getProjectSales($project);
            if($project->project_cost && $project->project_cost>0){
                $project['achievement_percentage'] =
                ($totalSales/$project->project_cost)*100;
            }
            else{
             $project['achievement_percentage'] =0;   
            }
            $project->unsetRelation('instantSales');
        });
            return response()->json([
                'status' => 'success',
                'completed projects' => $completedProjects
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_completed_projects')
            ], 200);
        }


    }


    // get sales of the project
    public function projectSales(Request $request){
        try{
            $request->validate(['project_id'=>'required|exists:projects,id']);

            $project = Project::findOrFail($request->project_id);
            $sales = $project->instantSales;
            $total = 0;
            $formatted = $sales->map( function($sale) use(&$total){
                $total+= $sale->total_cost;

                return [
                    'product_name' => $sale->product->nameAr,
                    'product_cost' => $sale->cost,
                    'product_quantity' => $sale->quantity,
                ];
            });
            

            return response()->json([
                'status'=>'success',
                'project_sales'=> $formatted,
                'total_costs'=> $total,
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
                'message' => __('messages.project_not_found')
            ], 200);
        }
        
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
          }  catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

}
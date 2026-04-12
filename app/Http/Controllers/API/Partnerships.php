<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Partnership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Partnerships extends Controller
{


    public function createPartnership(Request $request){
        try{
        $data = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'product_id' => 'nullable|exists:products,id',
            'department_id' => 'nullable|exists:departments,id',
            'sub_department_id' => 'nullable|exists:sub_departments,id',
            'project_id' => 'nullable|exists:projects,id',
            'share' => 'required|numeric|min:0',
            'partnership_percentage' => 'required|numeric|min:0|max:1',
        ]);
    
        $type = null;
        if ($request->filled('product_id')) {
            $type = 'product';
        } elseif ($request->filled('department_id')) {
            $type = 'department';
        } elseif ($request->filled('sub_department_id')) {
            $type = 'subdepartment';
        } elseif ($request->filled('project_id')) {
            $type = 'project';
        }
        else{
            $type = 'all store';
        }
    
        // Ensure exactly one type is selected
        if (!$type) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.validation_failed'),
                    'errors' => ['type' => __('messages.one_type_required')]
                ], 200);
        }

        $data['type'] = $type;
        $partnership = Partnership::create($data);
        Logs::createLog('اضافة شراكة جديدة','اضافة شراكة جديدة','partnerships');
        return response()->json([
                'status' => 'success',
                'message' => __('messages.partnership_created_successfully')
            ], 200);

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

    private function commonData($status){
        try{
        $partnerships = Partnership::where('status',$status)
        ->with('partner:id,name')
        ->get();

        $formatted = $partnerships->map(function($partnership){
           $data =  [
                'partnership_id' => $partnership->id,
                'partner_name' => $partnership->partner->name,
                'partnership_type' => $partnership->type,
                'partnership_share' => $partnership->share,
                'partnership_percentage' => $partnership->partnership_percentage,

            ];
            if($partnership->product_id !== null){
                $data ['product_name'] = 
                     $partnership->product->name
                ;
            }
            elseif($partnership->department_id !== null){
                $data ['department_name'] = 
                     $partnership->department->name
                ;  
            }
            elseif($partnership->sub_department_id !== null){
                $data ['sub_department_name'] = 
                      $partnership->subDepartment->name
                ;  
            }
            elseif($partnership->project_id !== null){
                $data ['project_name'] = 
                      $partnership->project->name
                ;  
            }
            return $data;
        });

        return response()->json([
                'status' => 'success',
                'partnerships data' => $formatted,
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

    public function getOngoingPartnerships(){
       return  $this->commonData('ongoing');

      
    }

    public function getCompletedPartnerships(){
        return  $this->commonData('completed');
 
     }

    public function showPartnership(Request $request){
        try{
            $request->validate(['partnership_id'=>'required|exists:partnerships,id']);

            $partnership = Partnership::findOrFail($request->partnership_id);

           
            
                $data = [
                    'partner_name' => $partnership->partner->name,
                    'partnership_share' => $partnership->share,
                    'partnership_percentage' => $partnership->partnership_percentage,
                    'partnership_status' => $partnership->status,
                    'type' => $partnership->type,

                ];
                if($partnership->product_id !== null){
                    $data['product_name'] = $partnership->product->name;


                }
                elseif($partnership->project_id !== null){
                    $data['project_name'] = $partnership->project->name;
                }

                elseif($partnership->department_id !== null){
                    $data['department_name'] = $partnership->department->name;
                }

                elseif($partnership->sub_department_id !== null){
                    $data['sub_department_name'] = $partnership->subDepartment->name;
                }

            return response()->json([
                'status' => 'success',
                'partnership_details' => $data
            ], 200);


    }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.partnership_not_found')
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } 
         catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

 }

    public function editPartnership(Request $request){
        try{
            $data = $request->validate([
                'partnership_id' => 'required|exists:partnerships,id',
                'status' => 'required|string',
                'partner_id' => 'required|exists:partners,id',
                'share' => 'required|numeric|min:0',
                'partnership_percentage' => 'required|numeric|min:0|max:1',

            ]);

            $partnership = Partnership::findOrFail($request->partnership_id);
            $partnership->update($data);

            Logs::createLog('تعديل بيانات شراكة','تعديل بيانات شراكة','partnerships');
            return response()->json([
                'status' => 'success',
                'message' => __('messages.partnership_updated_successfully')
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.partnership_not_found')
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.update_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
}
}
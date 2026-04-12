<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Maintenance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MaintenanceAPI extends Controller
{
    // get all maintenance details
    private function maintenances($status){

      try{
        $maintenances = Maintenance::where('status',$status)
        ->with('customer:id,name')
        ->with('seller:id,name')
        ->get();
        $formatted = $maintenances->map(function($maintenance){

           $imagePath = null;

            if (is_array($maintenance->files) && count($maintenance->files) > 0) {
                foreach ($maintenance->files as $file) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                    if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                        // found the first image → stop searching
                        $imagePath = 'public/MaintenanceFiles/' . $file;
                        break;
                    }
                }
            }
        //   $receiptDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $maintenance->receipt_date . ' ' . $maintenance->receipt_time);

        //     // Get current time
        //     $now = Carbon::now();

        //     // Get the difference in hours (can be negative)
        //     $diffInHours = $now->diffInHours($receiptDateTime, false); // false keeps negative if it's in the future

            return [
                'id'=> $maintenance->id,
                "customer_name"=> $maintenance->customer_id?  $maintenance->customer->name:null,
                "seller_name"=> $maintenance->seller_id? $maintenance->seller->name :null,

                "receipt_date"=> $maintenance->receipt_date??null,
                "receipt_time"=> $maintenance->receipt_time??null,
                "created_at" => $maintenance->created_at->format('Y-m-d'),
                'status' => $maintenance->status?? 'unknown',
                //"remaining_time_in_hours" => $diffInHours,
                "media_files" => $imagePath??'no image files',
            ];

        });
            return response()->json([
                'status' => 'success',
                'maintenance_details' => $formatted
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
                'message' => __('messages.failed_to_load_maintenances')
            ], 200);
        }

}

    public function getNewMaintenances(){
        return $this->maintenances('new');
    }

    public function getPendingMaintenances(){
        return $this->maintenances('ongoing');
    }

    public function getReadyMaintenances(){
        return $this->maintenances('ready');
    }

    public function getDoneMaintenances(){
        return $this->maintenances('delivered');
    }

    private function fileStorage(Request $request){
        $files = [];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $fullName = $file->getClientOriginalName();
                $file->move(public_path('MaintenanceFiles'), $fullName);
                $files[] = $fullName;
            }
        }

        return $files;
    }

    private function validateFields(Request $request){
        $data = $request->validate([
            'description'  => 'nullable|string',
            'receipt_date' => 'required|date',
            'receipt_time' => 'required|date_format:H:i',
            'files' => 'nullable|array',
            'files.*' => 'file|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',

        ]);

        return $data;
    }

// for new status 
    public function store(Request $request)
{
    try{

        $request->validate([
            'customer_id'  => 'nullable|exists:customers,id',
            'seller_id'  => 'nullable|exists:sellers,id'

        ]);

        $data = $this->validateFields($request);

        if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_customer_or_seller')
            ], 200);
        }

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }



        $data['customer_id'] = $request->customer_id?? null;
        $data['seller_id'] = $request->seller_id?? null;


        $files = $this->fileStorage($request);

        $data['files'] = $files;
        $data['status'] = 'new';

        $maintenance = Maintenance::create($data);
    
        if($maintenance->customer_id){
           Logs::createLog('اضافة صيانة جديدة','اضافة صيانة للزبون'.' '.$maintenance->customer->name,'maintenances');
        }
        else{
            Logs::createLog('اضافة صيانة جديدة','اضافة صيانة للتاجر'.' '.$maintenance->seller->name,'maintenances');
   
        }
            return response()->json([
                'status' => 'success',
                'message' => __('messages.maintenance_created_successfully')
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

    public function showMaintenance(Request $request){
        try{
            $request->validate([
                'maintenance_id'=>'required|exists:maintenance,id',
            ]);

            $maintenance = Maintenance::with('customer:id,name')->with('seller:id,name')
            ->findOrFail($request->maintenance_id);

            $files = [];
            if($maintenance->files && count($maintenance->files)>0){
                foreach($maintenance->files as $file){
                $files[]= 'public/MaintenanceFiles/'.$file;
                }
            }
            $maintenance['files']=$files;
            $maintenance->makeHidden(['customer_id','seller_id']);
            return response()->json([
                'status'=>'success',
                'maintenance'=>$maintenance,
            ],200);

        }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }
        catch(ModelNotFoundException $e){
            return response()->json([
                'status' => 'error',
                'message' => __('messages.maintenance_not_found')
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

    public function commonUpdate(Request $request){

        try{

       $data= $request->validate([
            'maintenance_id'=>'required|exists:maintenance,id',
            'customer_id'  => 'nullable|exists:customers,id',
            'seller_id'  => 'nullable|exists:sellers,id',
            'description'  => 'nullable|string',
            'receipt_date' => 'required|date',
            'receipt_time' => 'required|date_format:H:i',
            'files' => 'nullable|array',
            'files.*' => 'nullable',
            'status' => 'required|string|in:ongoing,ready,delivered,new',

    ]);
        if (!$request->filled('customer_id') && !$request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_customer_or_seller')
            ], 200);
        }

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller')
            ], 200);
        }
    
        
        $maintenance = Maintenance::findOrFail($request->maintenance_id);
        $oldStatus = $maintenance->status;
        $data['customer_id'] = $request->customer_id?? null;
        $data['seller_id'] = $request->seller_id?? null;

        // Merge existing and new files
        $data['files'] = CommonUse::handleImageUpdate($request,'files','MaintenanceFiles',$maintenance->files);
        $data['status'] = $request->status;


        $maintenance->update($data);

        if($request->status==='delivered' && $oldStatus !== 'delivered'){
            if($maintenance->customer_id){
                    Logs::createLog('تسليم صيانة',
                    'تم تسليم الصيانة للزبون'.' '.$maintenance->customer->name  ,'maintenances'
                );
          }
          else{
                Logs::createLog('تسليم صيانة',
                    'تم تسليم الصيانة للتاجر'.' '.$maintenance->seller->name  ,'maintenances'
                );            
          }
        }
        return response()->json([
            'status'=>'success',
            'message'=>__('messages.maintenance_updated_successfully'),
        ]);

        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        }
        catch(ModelNotFoundException $e){
            return response()->json([
                'status' => 'error',
                'message' => __('messages.maintenance_not_found')
            ], 200);
        }

        catch (QueryException $e) {
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

    public function changeToPending(Request $request){
        return $this->commonUpdate($request,'ongoing');
    }

    public function changeToReady(Request $request){
        return $this->commonUpdate($request,'ready');

    }

    public function changeToDone(Request $request){
        return $this->commonUpdate($request,'delivered');

    }

  

}

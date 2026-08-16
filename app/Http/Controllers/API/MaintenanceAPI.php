<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Maintenance;
use App\Models\MaintenanceDailyClosingRequest;
use App\Services\MaintenanceActivityLogger;
use App\Services\MaintenanceDailyBoxService;
use App\Services\MaintenanceDeliveryService;
use App\Services\MaintenanceInvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceAPI extends Controller
{
    public function __construct(
        protected MaintenanceDeliveryService $deliveryService,
        protected MaintenanceActivityLogger $activityLogger,
        protected MaintenanceInvoiceService $invoiceService,
        protected MaintenanceDailyBoxService $maintenanceDailyBoxService
    ) {}

    private function resolveContactPhone($maintenance): ?string
    {
        $phone = null;
        if ($maintenance->seller_id && $maintenance->seller) {
            $phone = $maintenance->seller->phone;
        } elseif ($maintenance->customer_id && $maintenance->customer) {
            $phone = $maintenance->customer->phone;
        }

        if ($phone === null || $phone === '') {
            return null;
        }

        return trim((string) $phone);
    }

    // get all maintenance details
    private function maintenances($status, bool $onlyTrashed = false){

      try{
        $query = Maintenance::query();
        if ($onlyTrashed) {
            $query->onlyTrashed();
        }

        $maintenances = $query->where('status',$status)
        ->with('customer:id,name,phone')
        ->with('seller:id,name,phone')
        ->withSum('products as parts_total', 'line_total')
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
                "contact_phone"=> $this->resolveContactPhone($maintenance),
                "customer_id"=> $maintenance->customer_id,
                "seller_id"=> $maintenance->seller_id,

                "receipt_date"=> $maintenance->receipt_date??null,
                "receipt_time"=> $maintenance->receipt_time??null,
                "created_at" => $maintenance->created_at->format('Y-m-d'),
                'status' => $maintenance->status?? 'unknown',
                'parts_total' => round((float) ($maintenance->parts_total ?? 0), 2),
                'labor_cost' => round((float) ($maintenance->labor_cost ?? 0), 2),
                'invoice_total' => round((float) ($maintenance->invoice_total ?? 0), 2),
                'paid_amount' => round((float) ($maintenance->paid_amount ?? 0), 2),
                'remaining_amount' => max(0, round((float) ($maintenance->invoice_total ?? 0) - (float) ($maintenance->paid_amount ?? 0), 2)),
                'instant_sale_id' => $maintenance->instant_sale_id,
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

    public function getArchivedMaintenances()
    {
        try {
            $maintenances = Maintenance::onlyTrashed()
                ->with('customer:id,name,phone')
                ->with('seller:id,name,phone')
                ->withSum('products as parts_total', 'line_total')
                ->get();

            $formatted = $maintenances->map(function ($maintenance) {
                $imagePath = null;

                if (is_array($maintenance->files) && count($maintenance->files) > 0) {
                    foreach ($maintenance->files as $file) {
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'tiff', 'webp', 'avif', 'svg+xml'])) {
                            $imagePath = 'public/MaintenanceFiles/' . $file;
                            break;
                        }
                    }
                }

                return [
                    'id' => $maintenance->id,
                    'customer_name' => $maintenance->customer_id ? $maintenance->customer->name : null,
                    'seller_name' => $maintenance->seller_id ? $maintenance->seller->name : null,
                    'contact_phone' => $this->resolveContactPhone($maintenance),
                    'customer_id' => $maintenance->customer_id,
                    'seller_id' => $maintenance->seller_id,
                    'receipt_date' => $maintenance->receipt_date ?? null,
                    'receipt_time' => $maintenance->receipt_time ?? null,
                    'created_at' => $maintenance->created_at->format('Y-m-d'),
                    'deleted_at' => optional($maintenance->deleted_at)->format('Y-m-d H:i:s'),
                    'status' => $maintenance->status ?? 'unknown',
                    'parts_total' => round((float) ($maintenance->parts_total ?? 0), 2),
                    'labor_cost' => round((float) ($maintenance->labor_cost ?? 0), 2),
                    'invoice_total' => round((float) ($maintenance->invoice_total ?? 0), 2),
                    'paid_amount' => round((float) ($maintenance->paid_amount ?? 0), 2),
                    'remaining_amount' => max(0, round((float) ($maintenance->invoice_total ?? 0) - (float) ($maintenance->paid_amount ?? 0), 2)),
                    'instant_sale_id' => $maintenance->instant_sale_id,
                    'media_files' => $imagePath ?? 'no image files',
                ];
            });

            return response()->json([
                'status' => 'success',
                'maintenance_details' => $formatted,
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_maintenances'),
            ], 200);
        }
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
        $this->activityLogger->log(
            $maintenance,
            $request->user(),
            'created',
            'إنشاء صيانة جديدة',
            $maintenance->customer_id
                ? 'تم إنشاء صيانة للزبون '.$maintenance->customer->name
                : 'تم إنشاء صيانة للتاجر '.$maintenance->seller->name,
            null,
            'new',
            [
                'customer_id' => $maintenance->customer_id,
                'seller_id' => $maintenance->seller_id,
                'receipt_date' => $maintenance->receipt_date,
                'receipt_time' => $maintenance->receipt_time,
                'files_count' => count($files),
            ]
        );
    
        if($maintenance->customer_id){
           Logs::createLog('اضافة صيانة جديدة','اضافة صيانة للزبون'.' '.$maintenance->customer->name,'maintenances');
        }
        else{
            Logs::createLog('اضافة صيانة جديدة','اضافة صيانة للتاجر'.' '.$maintenance->seller->name,'maintenances');
   
        }
            return response()->json([
                'status' => 'success',
                'message' => __('messages.maintenance_created_successfully'),
                'maintenance_id' => $maintenance->id,
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
            ->with([
                'products.product:id,nameAr,nameEng',
                'instantSale:id,serial_number',
                'activityLogs' => fn ($query) => $query->latest()->limit(500),
            ])
            ->findOrFail($request->maintenance_id);

            $files = [];
            if($maintenance->files && count($maintenance->files)>0){
                foreach($maintenance->files as $file){
                $files[]= 'public/MaintenanceFiles/'.$file;
                }
            }
            $maintenance['files']=$files;
            $maintenance['billing'] = $this->deliveryService->formatProductsSummary($maintenance);
            $maintenance['activity_logs'] = $maintenance->activityLogs->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'title' => $log->title,
                'description' => $log->description,
                'actor_name' => $log->actor_name,
                'actor_type' => $log->actor_type,
                'old_status' => $log->old_status,
                'new_status' => $log->new_status,
                'metadata' => $log->metadata,
                'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
            ])->values();
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

    public function deleteMaintenance(Request $request)
    {
        try {
            $data = $request->validate([
                'maintenance_id' => 'required|exists:maintenance,id',
            ]);

            $maintenance = Maintenance::with(['customer:id,name', 'seller:id,name'])
                ->findOrFail($data['maintenance_id']);

            if (! in_array($maintenance->status, ['new', 'ongoing'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.maintenance_delete_not_allowed'),
                ], 200);
            }

            if ($maintenance->instant_sale_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.maintenance_delete_not_allowed'),
                ], 200);
            }

            $name = $maintenance->customer_id
                ? ($maintenance->customer?->name ?? '#'.$maintenance->customer_id)
                : ($maintenance->seller?->name ?? '#'.$maintenance->seller_id);
            $logDescription = $maintenance->customer_id
                ? 'تم حذف طلب الصيانة للزبون '.$name
                : 'تم حذف طلب الصيانة للتاجر '.$name;
            DB::transaction(function () use ($maintenance, $logDescription) {
                Logs::createLog('حذف طلب صيانة', $logDescription, 'maintenances');
                $maintenance->delete();
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.maintenance_deleted_successfully'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.maintenance_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
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
            'labor_cost' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',

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
        $before = $maintenance->only([
            'customer_id',
            'seller_id',
            'description',
            'receipt_date',
            'receipt_time',
            'status',
            'labor_cost',
            'discount',
            'files',
        ]);
        $data['customer_id'] = $request->customer_id?? null;
        $data['seller_id'] = $request->seller_id?? null;

        // Merge existing and new files
        $data['files'] = CommonUse::handleImageUpdate($request,'files','MaintenanceFiles',$maintenance->files);
        $data['status'] = $request->status;
        unset($data['labor_cost'], $data['discount']);

        if ($request->has('labor_cost')) {
            $maintenance->labor_cost = max(0, round((float) $request->labor_cost, 2));
        }
        if ($request->has('discount')) {
            $maintenance->discount = max(0, round((float) $request->discount, 2));
        }

        $maintenance->update($data);
        $maintenance = $this->deliveryService->recalculateTotals($maintenance->fresh());
        $after = $maintenance->only([
            'customer_id',
            'seller_id',
            'description',
            'receipt_date',
            'receipt_time',
            'status',
            'labor_cost',
            'discount',
            'files',
        ]);
        $changes = $this->activityLogger->diff($before, $after);

        if ($changes !== []) {
            $this->activityLogger->log(
                $maintenance,
                $request->user(),
                $oldStatus !== $maintenance->status ? 'status_changed' : 'updated',
                $oldStatus !== $maintenance->status ? 'تغيير حالة الصيانة' : 'تعديل بيانات الصيانة',
                $oldStatus !== $maintenance->status
                    ? 'تم تغيير حالة الصيانة من '.$oldStatus.' إلى '.$maintenance->status
                    : 'تم تعديل بيانات الصيانة.',
                $oldStatus,
                $maintenance->status,
                ['changes' => $changes]
            );
        }

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
            'maintenance_id' => $maintenance->id,
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

    public function syncProducts(Request $request)
    {
        try {
            $data = $request->validate([
                'maintenance_id' => 'required|exists:maintenance,id',
                'labor_cost' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'products' => 'nullable|array',
                'products.*.product_id' => 'required|exists:products,id',
                'products.*.size_id' => 'nullable|integer|exists:sizes,id',
                'products.*.size_color_id' => 'nullable|integer|exists:size_colors,id',
                'products.*.quantity' => 'required|numeric|min:1',
                'products.*.unit_price' => 'required|numeric|min:0',
            ]);

            $maintenance = Maintenance::findOrFail($data['maintenance_id']);
            $maintenance = $this->deliveryService->syncProducts(
                $maintenance,
                $data['products'] ?? [],
                isset($data['labor_cost']) ? (float) $data['labor_cost'] : null,
                isset($data['discount']) ? (float) $data['discount'] : null,
                $request->user(),
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.maintenance_products_saved'),
                'billing' => $this->deliveryService->formatProductsSummary($maintenance),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.maintenance_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function deliver(Request $request)
    {
        try {
            $data = $request->validate([
                'maintenance_id' => 'required|exists:maintenance,id',
                'labor_cost' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'payment_amount' => 'nullable|numeric|min:0',
                'payment_box_id' => 'nullable|integer|exists:boxes,id',
                'payments' => 'nullable|array',
                'payments.*.method' => 'required_with:payments|string|in:cash',
                'payments.*.amount' => 'required_with:payments|numeric|min:0',
                'payments.*.note' => 'nullable|string|max:1000',
            ]);

            $maintenance = Maintenance::findOrFail($data['maintenance_id']);
            $result = $this->deliveryService->deliver(
                $maintenance,
                $request->user(),
                $data
            );

            $maintenance = $result['maintenance'];
            $instantSale = $result['instant_sale'];

            if ($maintenance->customer_id) {
                Logs::createLog(
                    'تسليم صيانة',
                    'تم تسليم الصيانة للزبون '.$maintenance->customer->name,
                    'maintenances'
                );
            } else {
                Logs::createLog(
                    'تسليم صيانة',
                    'تم تسليم الصيانة للتاجر '.$maintenance->seller->name,
                    'maintenances'
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.maintenance_delivered_successfully'),
                'billing' => $this->deliveryService->formatProductsSummary($maintenance),
                'instant_sale_id' => $instantSale?->id,
                'serial_number' => $instantSale?->serial_number,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.maintenance_not_found'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function activityLog(Request $request)
    {
        try {
            $data = $request->validate([
                'maintenance_id' => 'required|exists:maintenance,id',
            ]);

            $maintenance = Maintenance::findOrFail($data['maintenance_id']);
            $logs = $maintenance->activityLogs()
                ->latest()
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'title' => $log->title,
                    'description' => $log->description,
                    'actor_name' => $log->actor_name,
                    'actor_type' => $log->actor_type,
                    'old_status' => $log->old_status,
                    'new_status' => $log->new_status,
                    'metadata' => $log->metadata,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ]);

            return response()->json([
                'status' => 'success',
                'logs' => $logs,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function invoiceData(Request $request)
    {
        try {
            $data = $request->validate([
                'maintenance_id' => 'required|exists:maintenance,id',
            ]);

            $maintenance = Maintenance::findOrFail($data['maintenance_id']);

            return response()->json([
                'status' => 'success',
                'invoice' => $this->invoiceService->build($maintenance),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function invoicePdf(Request $request)
    {
        $data = $request->validate([
            'maintenance_id' => 'required|exists:maintenance,id',
        ]);

        $maintenance = Maintenance::findOrFail($data['maintenance_id']);
        $invoice = $this->invoiceService->build($maintenance);
        $html = view('pdf.maintenance-invoice', [
            'invoice' => $invoice,
        ])->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4')
            ->stream('maintenance-invoice-'.$maintenance->id.'.pdf');
    }

    public function dailyBox(Request $request)
    {
        try {
            $data = $request->validate([
                'date' => 'nullable|date',
            ]);

            return response()->json([
                'status' => 'success',
                'daily_box' => $this->maintenanceDailyBoxService->payload(
                    $data['date'] ?? null,
                    $request->user()
                ),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionCurrent(Request $request)
    {
        try {
            return response()->json([
                'status' => 'success',
                'daily_box' => $this->maintenanceDailyBoxService->payload(null, $request->user()),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionOpen(Request $request)
    {
        try {
            $data = $request->validate([
                'opening_balance' => 'nullable|numeric|min:0',
            ]);

            $session = $this->maintenanceDailyBoxService->openToday(
                $request->user(),
                null,
                (float) ($data['opening_balance'] ?? 0)
            );

            return response()->json([
                'status' => 'success',
                'message' => 'تم فتح صندوق الصيانة اليومي',
                'daily_box' => $this->maintenanceDailyBoxService->payload(null, $request->user()),
                'session_id' => $session->id,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionRequestClosing(Request $request)
    {
        try {
            $data = $request->validate([
                'note' => 'nullable|string|max:2000',
            ]);

            $closingRequest = $this->maintenanceDailyBoxService->requestClosing(
                $request->user(),
                $data['note'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'تم إرسال طلب إغلاق صندوق الصيانة',
                'daily_box' => $this->maintenanceDailyBoxService->payload(null, $request->user()),
                'closing_request_id' => $closingRequest->id,
                'session_id' => $closingRequest->session_id,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionPendingClosing(Request $request)
    {
        try {
            if (! $this->maintenanceDailyBoxService->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'closing_requests' => $this->maintenanceDailyBoxService->pendingClosingRequests(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionOpenSessions(Request $request)
    {
        try {
            return response()->json([
                'status' => 'success',
                'sessions' => $this->maintenanceDailyBoxService
                    ->listOpenSessions($request->user()),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.unauthorized'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessions(Request $request)
    {
        try {
            $filters = $request->validate([
                'business_date' => 'nullable|date',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
                'status' => 'nullable|string|in:open,closing_requested,closed',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            $result = $this->maintenanceDailyBoxService->listSessions($request->user(), $filters);

            return response()->json([
                'status' => 'success',
                'sessions' => $result['sessions']->values()->all(),
                'pagination' => $result['pagination'],
                'can_view_all' => $this->maintenanceDailyBoxService->canReviewClosing($request->user()),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionShow(Request $request, int $sessionId)
    {
        try {
            return response()->json([
                'status' => 'success',
                'session_detail' => $this->maintenanceDailyBoxService
                    ->buildSessionDetail($request->user(), $sessionId),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.unauthorized'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
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

    public function dailySessionDirectClose(Request $request)
    {
        try {
            if (! $this->maintenanceDailyBoxService->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'session_id' => 'required|integer|exists:maintenance_daily_sessions,id',
                'to_box_id' => 'nullable|integer|exists:boxes,id',
                'review_note' => 'nullable|string|max:2000',
            ]);

            $closingRequest = $this->maintenanceDailyBoxService->directClose(
                $request->user(),
                (int) $data['session_id'],
                isset($data['to_box_id']) ? (int) $data['to_box_id'] : null,
                $data['review_note'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'تم إغلاق صندوق الصيانة مباشرة',
                'closing_request' => $this->maintenanceDailyBoxService
                    ->formatClosingRequest($closingRequest),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionApproveClosing(Request $request)
    {
        try {
            if (! $this->maintenanceDailyBoxService->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'closing_request_id' => 'nullable|integer|exists:maintenance_daily_closing_requests,id',
                'session_id' => 'nullable|integer|exists:maintenance_daily_sessions,id',
                'to_box_id' => 'nullable|integer|exists:boxes,id',
                'review_note' => 'nullable|string|max:2000',
            ]);
            $requestId = $this->resolveMaintenanceClosingRequestId($data);

            $closingRequest = $this->maintenanceDailyBoxService->approveClosing(
                $request->user(),
                $requestId,
                isset($data['to_box_id']) ? (int) $data['to_box_id'] : null,
                $data['review_note'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'تم اعتماد إغلاق صندوق الصيانة',
                'closing_request' => $this->maintenanceDailyBoxService->formatClosingRequest($closingRequest),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function dailySessionRejectClosing(Request $request)
    {
        try {
            if (! $this->maintenanceDailyBoxService->canReviewClosing($request->user())) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $data = $request->validate([
                'closing_request_id' => 'nullable|integer|exists:maintenance_daily_closing_requests,id',
                'session_id' => 'nullable|integer|exists:maintenance_daily_sessions,id',
                'review_note' => 'nullable|string|max:2000',
            ]);
            $requestId = $this->resolveMaintenanceClosingRequestId($data);

            $closingRequest = $this->maintenanceDailyBoxService->rejectClosing(
                $request->user(),
                $requestId,
                $data['review_note'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'تم رفض طلب إغلاق صندوق الصيانة',
                'closing_request' => $this->maintenanceDailyBoxService->formatClosingRequest($closingRequest),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => collect($e->errors())->flatten()->first() ?: __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function resolveMaintenanceClosingRequestId(array $data): int
    {
        if (! empty($data['closing_request_id'])) {
            return (int) $data['closing_request_id'];
        }

        if (! empty($data['session_id'])) {
            $request = MaintenanceDailyClosingRequest::query()
                ->where('session_id', (int) $data['session_id'])
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            if ($request) {
                return (int) $request->id;
            }
        }

        throw ValidationException::withMessages([
            'closing_request_id' => ['طلب إغلاق صندوق الصيانة مطلوب.'],
        ]);
    }

}

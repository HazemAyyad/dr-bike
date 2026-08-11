<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\CheckSmsNotificationService;
use App\Services\DebtLedgerService;
use App\Models\Box;
use App\Models\Customer;
use App\Models\OutgoingCheck;
use App\Models\Seller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OutgoingChecks extends Controller
{
    public function store(Request $request)
{
    try {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'seller_id'   => 'nullable|exists:sellers,id',
            'total'       => 'required|numeric|min:1',
            'due_date'    => 'required|date',
            'currency'    => 'required|string',
            'check_id'    => 'required|string',
            'bank_name'   => 'required|string',
            'img'         => 'nullable|image',
            'notes' => 'nullable|string',

        ]);

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.must_select_either_customer_or_seller'),
            ], 200);
        }

        $data = IncomingChecks::handleImages($request, $data, [
            'img' => 'OutgoingChecksImages',
        ]);

        // Create the outgoing check
        $check = OutgoingCheck::create($data);

        app(DebtLedgerService::class)->syncOutgoingCheckToLedger($check->fresh());


        Logs::createLog(
            'اضافة شيك جديد',
            'تمت إضافة شيك جديد برقم ' . $request->check_id,
            'outgoing_checks'
        );

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.check_created_successfully')
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
            'errors'  => $e->errors()
        ], 200);

    } catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.create_data_error')
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
}

    private function commonData($status){
        try{
            $checks = OutgoingCheck::where('status',$status)
            ->with('customer:id,name')
            ->with('seller:id,name')
            ->get();
            return response()->json([
                'status'=>'success',
                'checks_status' =>$status,
                'checks_images_path' => 'public/OutgoingChecksImages',
                'front_checks_images_path' => 'public/OutgoingChecksImages',
                'back_checks_images_path' => 'public/OutgoingChecksImages/back',
                $status.'_'.'checks' => $checks,


                'checks_count' => OutgoingCheck::where('status',$status)->count(),
                'checks_total_dollar' => OutgoingCheck::where('status',$status)
                ->where('currency','دولار')->sum('total'),
                 'checks_total_shekel' => OutgoingCheck::where('status',$status)
                ->where('currency','شيكل')->sum('total'),  
                'checks_total_dinar' => OutgoingCheck::where('status',$status)
                ->where('currency','دينار')->sum('total'),       
                
                'boxes_total_dollar' => Box::totalDollar(),
                'boxes_total_shekel' => Box::totalShekel(),
                'boxes_total_dinar' => Box::totalDinar(),

                'cover_percentage' => $this->coverPercentage($status),

            ],200);
        }

        catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.retrieve_data_error')
        ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function notCashedChecks(){
        return $this->commonData('not_cashed');
    }

    public function cashedToPersonChecks(){
        return $this->commonData('cashed_to_person');
    }

    // public function cancelledChecks(){
    //     return $this->commonData('cancelled');
    // }

    // public function returnedChecks(){
    //     return $this->commonData('returned');
    // }

    // public function cashedChecks(){
    //     return $this->commonData('cashed');
    // }

    public function archive(){
                try{
            $checks = OutgoingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_from_box',
                    ])
            ->with('customer:id,name')
            ->with('seller:id,name')
            ->get();


            //for coverage percentage
            $totalArchivedDollar = OutgoingCheck::where('currency', 'دولار')
            ->whereIn('status', ['cancelled', 'returned','cashed_from_box'])
            ->sum('total');

            $totalArchivedDinar = OutgoingCheck::where('currency', 'دينار')
            ->whereIn('status', ['cancelled', 'returned','cashed_from_box'])
            ->sum('total');

            $totalArchivedShekel = OutgoingCheck::where('currency', 'شيكل')
            ->whereIn('status', ['cancelled', 'returned','cashed_from_box'])
            ->sum('total');

            $coverPercentage = [
                'dollar' => $totalArchivedDollar>0? (Box::totalDollar() / $totalArchivedDollar)*100 :0,
                'dinar' => $totalArchivedDinar>0? (Box::totalDinar() / $totalArchivedDinar)*100 :0,
                'shekel' => $totalArchivedShekel>0? (Box::totalShekel() / $totalArchivedShekel)*100 :0,

            ];

            return response()->json([
                'status'=>'success',
                'archived_checks' =>$checks,
                'checks_images_path' => 'public/OutgoingChecksImages',
                'front_checks_images_path' => 'public/OutgoingChecksImages',
                'back_checks_images_path' => 'public/OutgoingChecksImages/back',
                'checks_count' => OutgoingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_from_box',
                    ])->count(),
                'checks_total_dollar' => OutgoingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_from_box',
                    ])->where('currency','دولار')->sum('total'),

                'checks_total_shekel' => OutgoingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_from_box',
                    ])->where('currency','شيكل')->sum('total'),

                'checks_total_dinar' => OutgoingCheck::whereIn('status', [
                        'cancelled',
                        'returned',
                        'cashed_from_box',
                    ])->where('currency','دينار')->sum('total'),                    


                'boxes_total_dollar' => Box::totalDollar(),
                'boxes_total_shekel' => Box::totalShekel(),
                'boxes_total_dinar' => Box::totalDinar(),


                'cover_percentage' => $coverPercentage,

            ],200);
        }

        catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.retrieve_data_error')
        ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    private function changeCheckStatus(Request $request,$status){
        try{
            $request->validate(['outgoing_check_id'=>'required|exists:outgoing_checks,id']);
            $check = OutgoingCheck::findOrFail($request->outgoing_check_id);

            $check->update(['status' => $status]);
            $check = $check->fresh();

            app(DebtLedgerService::class)->syncOutgoingCheckToLedger($check);

            if ($status === 'returned' && ($check->customer_id || $check->seller_id)) {
                $personName = $check->customer_id ? $check->customer->name : $check->seller->name;
                Logs::createLog(
                    'ارجاع شيك صادر',
                    'تم ارجاع شيك صادر بقيمة '.$check->total.' '.$check->currency.' لصالح '.$personName.' وتحديث دفتر الديون',
                    'debts'
                );
                app(CheckSmsNotificationService::class)->dispatchForAction($check, 'returned');
            }

            Logs::createLog(
            'تغيير حالة الشيك ',
            'تم تغيير حالة الشيك بقيمة ' . $check->total.' '.$check->currency.' '.' الى'.' '.$status,
            'outgoing_checks'
        );
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.outgoing_check_'.$status),
            ],200);
        }

        catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

    } 
    catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.outgoing_check_not_found')
        ], 200);
    }
    catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong'),

        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong'),

        ], 200);

    }



}

    public function cancelCheck(Request $request){
        return $this->changeCheckStatus($request,'cancelled');
    }

    public function returnCheck(Request $request){
        return $this->changeCheckStatus($request,'returned');
    }

    // public function cashCheck(Request $request){
    //     return $this->changeCheckStatus($request,'cashed');
    // }

    public function cashCheckToPerson(Request $request){
        try{
            $request->validate([
            'outgoing_check_id'=>'required|exists:outgoing_checks,id',
            'customer_id' => 'nullable|exists:customers,id',
            'seller_id' => 'nullable|exists:sellers,id',

            ]);

                    // Enforce that one and only one of seller_id or customer_id is provided
        if (! $request->filled('customer_id') && ! $request->filled('seller_id')) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.one_person_required'),
            ], 200);
        }

        if ($request->filled('customer_id') && $request->filled('seller_id')) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.only_one_person_allowed'),
            ], 200);
        }
            $check = OutgoingCheck::findOrFail($request->outgoing_check_id);

            $personName = 'غير معروف';

            if ($request->filled('customer_id')) {
                $customer = Customer::findOrFail($request->customer_id);
                $check->customer_id = $request->customer_id;
                $personName = $customer->name;
            } elseif ($request->filled('seller_id')) {
                $seller = Seller::findOrFail($request->seller_id);
                $check->seller_id = $request->seller_id;
                $personName = $seller->name;
            }
            $check->status = 'cashed_to_person';
            $check->save();

            $check = $check->fresh();
            app(DebtLedgerService::class)->syncOutgoingCheckToLedger($check);
            app(CheckSmsNotificationService::class)->dispatchForAction($check, 'cashed');

            Logs::createLog(
                'التصرف في شيك',
                'تم صرف شيك صادر بقيمة '.$check->total.' '.$check->currency.
                ' لصالح '.$personName.' وتم تحديث دفتر الديون',
                'debts'
            );

            Logs::createLog(
            'التصرف في شيك',
            'تم التصرف في الشيك الصادر بقيمة ' .' '. $check->total .' '.$check->currency.' '. ' لصالح ' . $personName,
            'outgoing_checks'
        );

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.check_cashed'),
            ],200);
        }

        catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

    } 
    catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);

    }
    }

    // delete check
    public function deleteCheck(Request $request){
        try{

            $request->validate(['outgoing_check_id'=>'required|exists:outgoing_checks,id',]);

            $check = OutgoingCheck::findOrFail($request->outgoing_check_id);

            $deletableStatuses = ['not_cashed', 'cancelled', 'returned'];

            if (! in_array($check->status, $deletableStatuses)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.cannot_delete_check'),
                ], 200);
            }

            app(DebtLedgerService::class)->deleteSourceLedger('outgoing_check', (int) $check->id);

            Logs::createLog('حذف شيك صادر', 'تم حذف شيك صادر بقيمة '.$check->total.' '.$check->currency, 'outgoing_checks');

            $check->delete();


                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.check_deleted'),
                ], 200);


        }

        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);

        } 
        catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);

        }
    }

    private function calculateCoverage(string $currency, float $totalBoxes): array
    {
        $totalNotCashed = OutgoingCheck::where('currency', $currency)
            ->where('status', 'not_cashed')
            ->sum('total');

        $totalCashed = OutgoingCheck::where('currency', $currency)
            ->where('status', 'cashed_to_person')
            ->sum('total');

        $totalArchived = OutgoingCheck::where('currency', $currency)
            ->whereIn('status', ['cashed', 'cancelled', 'returned'])
            ->sum('total');

        return [
            'not_cashed' => $totalNotCashed>0 ? ($totalBoxes / $totalNotCashed) * 100 : 0,
            'cashed_to_person'     => $totalCashed>0 ? ($totalBoxes / $totalCashed) * 100 : 0,
        ];
    }

    private function coverPercentage($status)
    {
        $results = [];

        $results['dollar'] = $this->calculateCoverage('دولار', Box::totalDollar())[$status];
        $results['dinar']  = $this->calculateCoverage('دينار', Box::totalDinar())[$status];
        $results['shekel'] = $this->calculateCoverage('شيكل', Box::totalShekel())[$status];

        return $results;
    }

    // info for main outgoing checks page
    // public function generalOutgoingChecksData(){
    //     try{
    //         $data = [
    //             'outgoing_checks_count' => OutgoingCheck::checksCount(),
    //             'total_outgoing_checks' => OutgoingCheck::totalAmount(),
    //             'total_boxes' => Box::totalAmount(),
    //             'coverage_percentage' => $this->coverPercentage(),
    //             'not_cashed_checks_count' => OutgoingCheck::where('status','not_cashed')->count(),
    //             'cashed_checks_count' => OutgoingCheck::where('status','cashed_to_person')->count(),
    //             'archive_checks_count' => OutgoingCheck::whereIn('status',['cancelled','returned','cashed'])->count(),

    //         ];
    //         return response()->json([
    //             'status'=>'success',
    //             'data' => $data,
    //         ],200);
    //     }

    //         catch (QueryException $e) {
    //     return response()->json([
    //         'status'  => 'error',
    //         'message' => __('messages.something_wrong')
    //     ], 200);

    // } catch (\Exception $e) {
    //     return response()->json([
    //         'status'  => 'error',
    //         'message' => __('messages.something_wrong')
    //     ], 200);

    // }
    // }

    public function generalDataFirstPage(){
        try{

            $data = OutgoingCheck::generalChecksData();
            $user = request()->user();
            $canViewAll = $user?->type === 'admin' || $this->userHasPermission($user, 'Checks');
            $canViewIncoming = $canViewAll || $this->userHasPermission($user, 'Checks Incoming View');
            $canViewOutgoing = $canViewAll || $this->userHasPermission($user, 'Checks Outgoing View');

            if (! $canViewIncoming) {
                $data['not_cashed_incoming_checks_count'] = 0;
                $data['cashed_incoming_checks_count'] = 0;
                $data['cashed_to_box_incoming_checks_count'] = 0;
                $data['total_incoming_checks_dollar'] = 0;
                $data['total_incoming_checks_dinar'] = 0;
                $data['total_incoming_checks_shekel'] = 0;
            }

            if (! $canViewOutgoing) {
                $data['not_cashed_outgoing_checks_count'] = 0;
                $data['cashed_outgoing_checks_count'] = 0;
                $data['total_outgoing_checks_dollar'] = 0;
                $data['total_outgoing_checks_dinar'] = 0;
                $data['total_outgoing_checks_shekel'] = 0;
            }

            return response()->json([
                'status'=>'success',
                'data' => $data,
            ],200);
        }

    catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);

    }
    }

    private function userHasPermission($user, string $permission): bool
    {
        if (! $user || $user->type !== 'employee') {
            return false;
        }

        return $user->employee?->permissions()
            ->whereHas('permission', fn ($q) => $q->where('name_en', $permission))
            ->exists() ?? false;
    }

   public function editCheck(Request $request){
      try{

            $data = $request->validate([
                'outgoing_check_id' => 'required|integer|exists:outgoing_checks,id',
                'total' => 'required|numeric|min:1',
                'due_date' => 'required|date',
                'currency' => 'required|string',
                'check_id' => 'required|string',
                'bank_name' => 'required|string',
                'img'   => 'nullable',
                'back_image' => 'nullable',
                'notes' => 'nullable|string',

            ]);

            $outgoingCheck = OutgoingCheck::
            findOrFail($request->outgoing_check_id);

            $data = IncomingChecks::handleImages($request, $data, [
                'img' => 'OutgoingChecksImages',
                'back_image' => 'OutgoingChecksImages/back',
            ], $outgoingCheck);

            $outgoingCheck->update($data);

            app(DebtLedgerService::class)->syncOutgoingCheckToLedger($outgoingCheck->fresh());

            return response()->json([
                'status'=>'success',
                'message' => __('messages.check_updated'),
            ],200);
            
    }


     catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
            'errors'  => $e->errors()

        ], 200);
     } 
        catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    
     catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }
 

       public function cashFromBox(Request $request){
      try{

            $data = $request->validate([
                'outgoing_check_id' => 'required|integer|exists:outgoing_checks,id',
                'box_id' =>'required|integer|exists:boxes,id',

            ]);

            $outgoingCheck = OutgoingCheck::
            findOrFail($request->outgoing_check_id);

        $box = Box::findOrFail($request->box_id);

        if($outgoingCheck->currency !== $box->currency){
            return response()->json([
                'status'=>'error',
                'message' => __('messages.must_be_same_currency_check'),

            ]);
        }

        if($outgoingCheck->total > $box->total){
            return response()->json([
                'status'=>'error',
                'message' => __('messages.box_out_of_money'),

            ]);
        }

        $box->update(['total'=> $box->total - $outgoingCheck->total]);
        $outgoingCheck->update(['status'=>'cashed_from_box']);

        $outgoingCheck = $outgoingCheck->fresh();
        app(DebtLedgerService::class)->syncOutgoingCheckToLedger($outgoingCheck);
        app(CheckSmsNotificationService::class)->dispatchForAction($outgoingCheck, 'cashed');

        BoxLogs::createBoxLog($box,'تم صرف شيك صادر برقم '.' '.($outgoingCheck->check_id??'غير معروف').' '.'من الصندوق'
        ,'minus',$outgoingCheck->total);

            Logs::createLog(
            'صرف شيك صادر من صندوق',
            'تم صرف الشيك الصادر بقيمة ' . $outgoingCheck->total.' '.$outgoingCheck->currency.' ' . ' من الصندوق' .' '. $box->name,
            'outgoing_checks'
        );

            return response()->json([
                'status'=>'success',
                'message' => __('messages.check_cashed_from_box'),
            ],200);
            
    }


     catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
            'errors'  => $e->errors()

        ], 200);
     } 
        catch (ModelNotFoundException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    
     catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }
}

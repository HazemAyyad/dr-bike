<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\IncomingCheck;
use App\Models\Log;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Customers extends Controller
{

    private function getPersons(string $type,callable $query){
    
    try{
        $persons = $query();
        $persons->transform(function ($person) use ($type) {
            if ($person->ID_image && count($person->ID_image)>0) {
                $firstImg = $person->ID_image[0];
                $person->ID_image = 'public/'.$type.'Images/ID/' . $firstImg;

            } 
            return $person;
        });
            return response()->json([
                'status' => 'success',
                'data' => $persons,
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

    //helper
private function hasCompleteData($query, $fields)
{
    foreach ($fields as $field) {
        if (in_array($field, ['ID_image'])) {
            // Check that it's not null AND not an empty array string AND not empty
            $query->whereNotNull($field)
                  ->where($field, '!=', '[]')
                  ->where($field, '!=', '');
        } else {
            $query->whereNotNull($field)
                  ->where($field, '!=', '');
        }
    }
    return $query;
}



    // for main display
    public function getCustomersForMainPage(){



        return $this->getPersons('customer', function () {


        $query = Customer::select('id','phone','job_title','name','is_canceled','ID_image');

        return $query->get();
    });
    }

    public function getSellersForMainPage(){



        return $this->getPersons('seller', function () {


        $query = Seller::select('id','phone','job_title','name','is_canceled','ID_image');

        return $query->get();
    });

    }

    // get incompleted data persons
public function getIncompletePersons()
{
    $fields = [
            'name','phone'

    ];

    // Customers
    $incompleteCustomers = Customer::select('id','phone','job_title','name','is_canceled','ID_image')
        ->where(function ($q) use ($fields) {
            foreach ($fields as $field) {
                $q->orWhereNull($field)->orWhere($field, '');
            }
            // check ID images too
            $q->orWhereNull('ID_image')->orWhere('ID_image','[]');
        })
        ->get()
        ->map(function ($person) {
            if ($person->ID_image && count($person->ID_image)>0) {
                $person->ID_image = 'public/customerImages/ID/' . $person->ID_image[0];
            }

            $person->type = 'customer'; 

            return $person;
        });

    // Sellers
    $incompleteSellers = Seller::select('id','phone','job_title','name','is_canceled','ID_image')
        ->where(function ($q) use ($fields) {
            foreach ($fields as $field) {
                $q->orWhereNull($field)->orWhere($field,'');
            }
            $q->orWhereNull('ID_image')->orWhere('ID_image','[]');
        })
        ->get()
        ->map(function ($person) {
            if ($person->ID_image && count($person->ID_image)>0) {
                $person->ID_image = 'public/sellerImages/ID/' . $person->ID_image[0];
            }


            $person->type = 'seller'; 

            return $person;
        });

        $allData = array_merge($incompleteCustomers->toArray(),$incompleteSellers->toArray());
        return response()->json([
        'status'    => 'success',
        'data' => $allData,
    ]);
}



    
    public function store(Request $request)
{
 try{
    $data = $request->validate([
       'person_type' =>'required|string|in:customer,seller',
        'name'     => 'required|string|max:255',
        'phone' => [
                        'nullable',
                        'regex:/^\+\d{3}\ \d{9}$/',
                        'unique:customers,phone',
                    ],  
        'sub_phone'           => 'nullable|string|regex:/^\+\d{3}\ \d{9}$/',
        'job_title'          => 'nullable|string',
        'address'          => 'nullable|string',
        'facebook_username'  => 'nullable|string',
        'facebook_link'      => 'nullable|string|url',
        'instagram_username' => 'nullable|string',
        'instagram_link'     => 'nullable|string|url',

        'related_people'     => 'nullable|string',


        'work_address'       => 'nullable|string',
        'relative_phone'     => 'nullable|string|regex:/^\+\d{3}\ \d{9}$/',
        'relative_job_title' => 'nullable|string',
        'ID_image'           => 'nullable|array',
        'ID_image.*'           => 'required|file|image',

        'license_image'      => 'nullable|array',
        'license_image.*'      => 'required|file|image',
        'type' => 'required|string',


    ]);



    // Handle image uploads
    $idImageNames = [];
    $licenseImageNames = [];

    if ($request->hasFile('ID_image')) {
        foreach($request->file('ID_image') as $file){
       
            $idImageName = $file->getClientOriginalName();
            $file->move(public_path($request->person_type.'Images/ID'), $idImageName);
            $idImageNames[] = $idImageName;
    }
  }

    if ($request->hasFile('license_image')) {
        foreach($request->file('license_image') as $file){
        $licenseImageName = $file->getClientOriginalName();
        $file->move(public_path($request->person_type.'Images/License'), $licenseImageName);
        $licenseImageNames[] = $licenseImageName;
    }
   }

    $data['ID_image'] = $idImageNames;
    $data['license_image'] = $licenseImageNames;



    if($request->person_type ==='customer'){
        // Create customer
        Customer::create($data);
        Logs::createLog('اضافة زبون جديد','تم اضافة زبون جديد باسم'.' '.$request->name,'customers');

        }
    elseif($request->person_type==='seller'){
            Seller::create($data);
           Logs::createLog('اضافة تاجر جديد','تم اضافة تاجر جديد باسم'.' '.$request->name,'sellers');

        }


            return response()->json([
                'status' => 'success',
                'message' => __('messages.created_'.$request->person_type.'_successfully')
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


    public function deleteCustomer(Request $request){
        try{
            $request->validate(['customer_id'=>'required|exists:customers,id']);
            $customer = Customer::findOrFail($request->customer_id);
            $customer->update(['is_canceled'=>1]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.customer_deleted_successfully')
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
                'message' => __('messages.customer_not_found')
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.delete_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_delete_customer')
            ], 200);
        }


}

        public function restoreCustomer(Request $request){
        try{
            $request->validate(['customer_id'=>'required|exists:customers,id']);
            $customer = Customer::findOrFail($request->customer_id);
            $customer->update(['is_canceled'=>0]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.customer_restored_successfully')
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
                'message' => __('messages.customer_not_found')
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.restore_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_restore_customer')
            ], 200);
        }


}

    public function showCustomer(Request $request){
        try{
        $request->validate([
            'customer_id'=>'nullable|exists:customers,id',
            'seller_id'=>'nullable|exists:sellers,id',
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
        if($request->filled('customer_id')){
            $customer = Customer::findOrFail($request->customer_id);

            $idImages = [];
            if($customer->ID_image){
            foreach($customer->ID_image as $idImage){
                $idImages[] = 'public/customerImages/ID/'.$idImage;
            } }

            $licenseImages = [];
            if($customer->license_image){
            foreach($customer->license_image as $licenseImage){
                $licenseImages[] = 'public/customerImages/License/'.$licenseImage;
            } }
            $customer['ID_image'] = $idImages;
            $customer['license_image'] = $licenseImages;

                return response()->json([
                    'status' => 'success',
                    'person_details' => $customer
                ], 200); 
        }

    
    elseif($request->filled('seller_id')){
        $seller = Seller::findOrFail($request->seller_id);

        $idImages = [];
        if($seller->ID_image){
        foreach($seller->ID_image as $idImage){
            $idImages[] = 'public/sellerImages/ID/'.$idImage;
        } }

        $licenseImages = [];
        if($seller->license_image){
        foreach($seller->license_image as $licenseImage){
            $licenseImages[] = 'public/sellerImages/License/'.$licenseImage;
        }}
            $seller['ID_image'] = $idImages;
            $seller['license_image'] = $licenseImages;
            return response()->json([
                'status' => 'success',
                'person_details' => $seller
            ], 200); 
    }
  }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.customer_not_found')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_customer_details')
            ], 200);
        }       
    }



    public function editPerson(Request $request){
        try{
        $data = $request->validate([
        'customer_id' =>'nullable|integer|exists:customers,id',
        'seller_id' =>'nullable|integer|exists:sellers,id',

            'name'     => 'required|string|max:255',
            'phone' => [
                            'nullable',
                            'regex:/^\+\d{3}\ \d{9}$/',
                        ],  
            'sub_phone'           => 'nullable|string|regex:/^\+\d{3}\ \d{9}$/',
            'job_title'          => 'nullable|string',
            'address'          =>   'nullable|string',
            'facebook_username'  => 'nullable|string',
            'facebook_link'      => 'nullable|string|url',
            'instagram_username' => 'nullable|string',
            'instagram_link'     => 'nullable|string|url',
            'related_people'     => 'nullable|string',
            'work_address'       => 'nullable|string',
            'relative_phone'     => 'nullable|string|regex:/^\+\d{3}\ \d{9}$/',
            'relative_job_title' => 'nullable|string',
            'ID_image'           => 'nullable|array',
            'ID_image.*'           => 'nullable',

            'license_image'      => 'nullable|array',
            'license_image.*'      => 'nullable',
            'type' => 'required|string',
                
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


        if($request->filled('customer_id')){
            $customer = Customer::findOrFail($request->customer_id);
            $idImages = CommonUse::handleImageUpdate($request,'ID_image','customerImages/ID',$customer->ID_image);
            $licenseImages = CommonUse::handleImageUpdate($request,'license_image','customerImages/License',$customer->license_image);
            $data['ID_image'] = $idImages;
            $data['license_image'] = $licenseImages;
            $customer->update($data);
            Logs::createLog('تعديل بيانات زبون','تم تعديل بيانات الزبون  '.' '.$customer->name,'customers');


        }

        elseif($request->filled('seller_id')){
            $seller = Seller::findOrFail($request->seller_id);
            $idImages = CommonUse::handleImageUpdate($request,'ID_image','sellerImages/ID',$seller->ID_image);
            $licenseImages = CommonUse::handleImageUpdate($request,'license_image','sellerImages/License',$seller->license_image);
            $data['ID_image'] = $idImages;
            $data['license_image'] = $licenseImages;
            $seller->update($data);
            Logs::createLog('تعديل بيانات تاجر','تم تعديل بيانات التاجر  '.' '.$seller->name,'sellers');

        }

        return response()->json([
            'status'=>'success',
            'message'=>__('messages.person_updated'),
        ],200);
        }
             catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()

            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.customer_not_found')
            ], 200);

        }catch (QueryException $e) {
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

    public function allCustomers(){

        try {
            $customers = Customer::get();

            return response()->json([
                'status' => 'success',
                'all_customers' => $customers,
                'ID_images_path' => 'public/customerImages/ID',
                'license_images_path' => 'public/customerImages/License',

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


    public function allSellers(){

        try {
            $sellers = Seller::get();

            return response()->json([
                'status' => 'success',
                'all_sellers' => $sellers,
                'ID_images_path' => 'public/sellerImages/ID',
                'license_images_path' => 'public/sellerImages/License',

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

    public function deletePerson(Request $request){
        try{
            $request->validate([
                'customer_id' =>'nullable|integer|exists:customers,id',
                'seller_id' =>'nullable|integer|exists:sellers,id',

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

        if($request->filled('customer_id')){
            $customer = Customer::findOrFail($request->customer_id);
            $debts = Debt::where('customer_id',$customer->id)->where('status','unpaid')->exists();
            $incominChecks = IncomingCheck::where('from_customer',$customer->id)
            ->where('status','not_cashed')->exists();

            if($incominChecks){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.cannot_delete_person_for_check'),
                ],200);
            }

            if($debts){
                //  لو الديون الي اله الغير مدفوعة  والي عليه الغير مدفوعة متساوية يعني رصيده صفر وبقدر احذفه
                $totalToMe = Debt::where('customer_id',$customer->id)
                ->where('type','we owe')->where('status','unpaid')->sum('total');
                $totalOnMe = Debt::where('customer_id',$customer->id)
                ->where('type','owed to us')->where('status','unpaid')->sum('total');                
               if($totalOnMe !== $totalToMe){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.cannot_delete_person'),
                ],200);
              }
            }
            $customer->delete();
        }
        elseif($request->filled('seller_id')){

            $seller = Seller::findOrFail($request->seller_id);
            $debts = Debt::where('seller_id',$seller->id)->where('status','unpaid')->exists();
            $incominChecks = IncomingCheck::where('from_seller',$seller->id)
            ->where('status','not_cashed')->exists();
            if($incominChecks){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.cannot_delete_person_for_check'),
                ],200);
            }
            if($debts){
                $totalToMe = Debt::where('seller_id',$seller->id)
                ->where('type','we owe')->where('status','unpaid')->sum('total');
                $totalOnMe = Debt::where('seller_id',$seller->id)
                ->where('type','owed to us')->where('status','unpaid')->sum('total');                
               if($totalOnMe !== $totalToMe){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.cannot_delete_person'),
                ],200);
              }
            }
            $seller->delete();
        }

        return response()->json([
            'status'=>'success',
            'message'=>__('messages.person_deleted'),
        ],200);

        }

        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),

            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);

        }catch (QueryException $e) {
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
}

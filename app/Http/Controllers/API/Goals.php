<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\GoalCategory;
use App\Models\GoalLog;
use App\Models\GoalPeople;
use App\Models\GoalProduct;
use App\Models\GoalSubCategory;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\isNull;

class Goals extends Controller
{
private function validateGoalForm(Request $request)
{
    $type = $request->type;
    $form = $request->form;

    /**
     * === 1. TYPE: finish_tasks ===
     */
    if ($type === 'finish_tasks') {
        if (is_null($request->employee_id)) {
            return __('messages.must_select_employee');
        }

        // if ($form !== 'employee') {
        //     return __('messages.form_must_be_employee');
        // }
    }

    /**
     * === 2. TYPE: pay_person ===
     */
    elseif ($type === 'pay_person') {

        $hasPeople = $request->filled('people');
        $hasEmployee = $request->filled('employee_id');

        // Must select one
        if (!$hasPeople && !$hasEmployee) {
            return __('messages.must_select_perosn');
        }

        // Cannot select both
        if ($hasPeople && $hasEmployee) {
            return __('messages.must_select_one_perosn');
        }

        // If people provided → must have either customer_id or seller_id and form = person
        if ($hasPeople) {
            if (count($request->people) > 1) {
                return __('messages.must_select_one_person');
            }

            $person = $request->people[0] ?? null;
            if (empty($person['customer_id']) && empty($person['seller_id'])) {
                return __('messages.must_select_customer_or_seller');
            }

            // if ($form !== 'people') {
            //     return __('messages.form_must_be_people');
            // }
        }

        // If employee selected → form must be employee
        // if ($hasEmployee && $form !== 'employee') {
        //     return __('messages.form_must_be_employee');
        // }
    }

    /**
     * === 3. TYPE: deposit_to_box ===
     */
    elseif ($type === 'deposit_to_box') {
        if (!$request->filled('box_id')) {
            return __('messages.must_select_box');
        }

        // if ($form !== 'box') {
        //     return __('messages.form_must_be_box');
        // }
    }

    /**
     * === 4. TYPES: total_sell_values / net_profit / sell_pieces / purchase_pieces / total_purchase_values ===
     */
    elseif (in_array($type, ['total_sell_values', 'net_profit', 'sell_pieces', 'purchase_pieces', 'total_purchase_values'])) {

        $fieldsToCheck = ['main_categories', 'sub_categories', 'products'];

        if ($type === 'total_purchase_values') {
            $fieldsToCheck[] = 'people';
        }

        // Determine which ones are filled
        $filledFields = collect($fieldsToCheck)->filter(fn($f) => $request->filled($f))->values();

        // None filled
        if ($filledFields->isEmpty()) {
            return __('messages.must_select_choice');
        }

        // More than one filled → only one allowed
        if ($filledFields->count() > 1) {
            return __('messages.must_select_one_choice');
        }

        // Get the only filled one
        $selectedField = $filledFields->first();

        // Check form consistency
        if ($type === 'total_sell_values') {
            if (!in_array($form, ['main_categories', 'sub_categories', 'products'])) {
                return __('messages.invalid_form_for_sell_type');
            }
        } elseif ($type === 'total_purchase_values') {
            if (!in_array($form, ['main_categories', 'sub_categories', 'products', 'people'])) {
                return __('messages.invalid_form_for_purchase_type');
            }
        } else {
            if (!in_array($form, ['main_categories', 'sub_categories', 'products'])) {
                return __('messages.invalid_form_for_general_type');
            }
        }

        // Ensure form matches the filled list
        if ($selectedField !== $form) {
            return __('messages.form_does_not_match_selected_field');
        }
    }

    return null; // No error
}


public function createGoal(Request $request)
{
    try {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'type'            => 'required|string|in:total_sell_values,net_profit,sell_pieces,purchase_pieces,total_purchase_values,finish_tasks,pay_person,deposit_to_box',
 //           'form'            => 'required|string|in:main_categories,sub_categories,products,employee,people,box',
            'form' => 'nullable|string|in:main_categories,sub_categories,products,employee,people,box|required_unless:type,finish_tasks,deposit_to_box,pay_person',

            'targeted_value'  => 'required|numeric|min:0',
            'notes'           => 'nullable|string',
            'scope'           => 'nullable|string|in:public,private',
            'due_date'        => 'nullable|date',

            // Relations
            'people'                     => 'nullable|array',
            'people.*.customer_id'       => 'nullable|integer|exists:customers,id',
            'people.*.seller_id'         => 'nullable|integer|exists:sellers,id',

            'products'                   => 'nullable|array',
            'products.*.product_id'      => 'required|integer|exists:products,id',

            'main_categories'            => 'nullable|array',
            'main_categories.*.main_category_id' => 'required|integer|exists:categories,id',

            'sub_categories'             => 'nullable|array',
            'sub_categories.*.sub_category_id'   => 'required|integer|exists:sub_categories,id',

            'employee_id'  => 'nullable|exists:employee_details,id',
            'box_id'       => 'nullable|exists:boxes,id',
        ]);

        if ($error = $this->validateGoalForm($request)) {
            return response()->json([
                'status'  => 'error',
                'message' => $error
            ], 200);
        }
        if($request->type ==='deposit_to_box'){
            $data['form'] = 'box';
        }
        elseif($request->type ==='finish_tasks'){
            $data['form'] = 'employee';
        }
        elseif($request->type ==='pay_person'){
            if($request->employee_id){
                $data['form'] = 'employee';
            }
            elseif($request->filled('people')){
                $data['form'] = 'people';

            }
        }

        if ($request->filled('people')) {
            foreach ($request->people as $index => $person) {
                $customer = $person['customer_id'] ?? null;
                $seller   = $person['seller_id'] ?? null;

                // Allow only one of them to be filled
                if (($customer && $seller) || (!$customer && !$seller)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('messages.must_select_either_customer_or_seller'),
                    ], 200);
                }
            }
}


        $goal = Goal::create($data);

        /**
         * === 1. Handle Relations by Form ===
         */
        switch ($goal->form) {

            case 'main_categories':
                foreach ($request->main_categories ?? [] as $mainCategory) {
                    GoalCategory::create([
                        'goal_id'     => $goal->id,
                        'category_id' => $mainCategory['main_category_id'],
                    ]);
                }
                break;

            case 'sub_categories':
                foreach ($request->sub_categories ?? [] as $subCategory) {
                    GoalSubCategory::create([
                        'goal_id'        => $goal->id,
                        'sub_category_id'=> $subCategory['sub_category_id'],
                    ]);
                }
                break;

            case 'products':
                foreach ($request->products ?? [] as $product) {
                    GoalProduct::create([
                        'goal_id'    => $goal->id,
                        'product_id' => $product['product_id'],
                    ]);
                }
                break;


            case 'people':
                foreach ($request->people ?? [] as $person) {
                    GoalPeople::create([
                        'goal_id'     => $goal->id,
                        'customer_id' => $person['customer_id'] ?? null,
                        'seller_id'   => $person['seller_id'] ?? null,
                    ]);
                }
                break;


        }

        $translated = $this->translateTypeAndForm();
        GoalLog::create([
            'title'=>'اضافة هدف جديد',
            'description'=>'تم اضافة هدف جديد باسم'.' '.$goal->name.
            ' '.'نوعه'.' '.$translated[$goal->type].' '.'بصيغة'.' '.$translated[$goal->form],
            'goal_id' => $goal->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.goal_created_successfully')
        ], 200);
    }

    // === Error handling ===
    catch (ValidationException $e) {
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
            'message' => __('messages.failed_to_create_goal')
        ], 200);
    }
}

    private function translateTypeAndForm()
    {
        return [
            // 🔹 Types
            'total_sell_values'     => 'مجموع قيم البيع',
            'net_profit'            => 'صافي الربح',
            'sell_pieces'           => ' بيع عدد معين من القطع',
            'purchase_pieces'       => 'شراء عدد معين من القطع ',
            'total_purchase_values' => 'مجموع قيم الشراء',
            'finish_tasks'          => 'انجاز مهمات اساسية ',
            'pay_person'            => 'دفع قيمة مالية لشخص',
            'deposit_to_box'        => 'ترصيد في صندوق',

            // 🔹 Forms
            'main_categories' => 'الأقسام الرئيسية',
            'sub_categories'  => 'الأقسام الفرعية',
            'products'        => 'المنتجات',
            'employee'        => 'الموظف',
            'people'          => 'الأشخاص',
            'box'             => 'الصندوق',
        ];
    }


    public function showGoal(Request $request){
        try{
        $request->validate(['goal_id'=>'required|exists:goals,id']);


        $goal = Goal::with('box:id,name')
        ->with('employee.user:id,name')
        ->findOrFail($request->goal_id);

        if($goal->employee_id){
        $goal['employee'] = [
            'id' => $goal->employee_id,
            'name' => $goal->employee->user->name,
        ];
        $goal->unsetRelation('employee');
      }

        $mainGategoriesList = [];
        $subCategoriesList  = [];
        $productsList = [];
        $peopleList = [];

        $categories = GoalCategory::where('goal_id', $goal->id)->get();
        if($categories){
            $mainGategoriesList = $categories->map(function($goalCategory){
                return [
                    'category_id'=> $goalCategory->category->id,
                    'category_name' => $goalCategory->category->nameAr
                ];
            });
        }

        $subCategories = GoalSubCategory::where('goal_id', $goal->id)->get();
        if($subCategories){
            $subCategoriesList = $subCategories->map(function($goalsubCategory){
                return [
                    'sub_category_id'=> $goalsubCategory->subCategory->id,
                    'sub_category_name' => $goalsubCategory->subCategory->nameAr
                ];
            });
        }

        $products = GoalProduct::where('goal_id', $goal->id)->get();
        if($products){
            $productsList = $products->map(function($productGoal){
                return [
                    'product_id'=> $productGoal->product->id,
                    'product_name' => $productGoal->product->nameAr
                ];
            });
        }

        $people = GoalPeople::where('goal_id', $goal->id)->get();
        if($people){
            $peopleList = $people->map(function($peopleGoal){
                return [
                    'customer_id'=> $peopleGoal->customer_id? $peopleGoal->customer_id:null,
                    'customer_name' => $peopleGoal->customer_id? $peopleGoal->customer->name:null,

                    'seller_id'=> $peopleGoal->seller_id? $peopleGoal->seller_id:null,
                    'seller_name' => $peopleGoal->seller_id? $peopleGoal->seller->name:null,

                ];
            });
        }

        $goal['main_categories']= $mainGategoriesList;
        $goal['sub_categories']= $subCategoriesList;
        $goal['products']= $productsList;
        $goal['people']= $peopleList; 
        $goalLogs = $goal->logs()->get(['title','description']);
        
      $goal->makeHidden(['product_id','customer_id','employee_id','seller_id','box_id']);
                return response()->json([
                'status' => 'success',
                'goal' => $goal,
                'goal_logs'=> $goalLogs,
               
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
                'message' => __('messages.goal_not_found')
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
                'message' => __('messages.failed_to_load_goal'),
            ], 200);
        }

    }

public function editGoal(Request $request)
{
    try {
        // 🔹 Validate input
        $data = $request->validate([
            'goal_id'         => 'required|integer|exists:goals,id',
            'name'            => 'required|string|max:255',
            'type'            => 'required|string|in:total_sell_values,net_profit,sell_pieces,purchase_pieces,total_purchase_values,finish_tasks,pay_person,deposit_to_box',
            'form' => 'nullable|string|in:main_categories,sub_categories,products,employee,people,box|required_unless:type,finish_tasks,deposit_to_box,pay_person',
            'targeted_value'  => 'required|numeric|min:0',
            'current_value'   => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string',
            'scope'           => 'nullable|string|in:public,private',
            'due_date'        => 'nullable|date',

            // Relations
            'people'                     => 'nullable|array',
            'people.*.customer_id'       => 'nullable|integer|exists:customers,id',
            'people.*.seller_id'         => 'nullable|integer|exists:sellers,id',

            'products'                   => 'nullable|array',
            'products.*.product_id'      => 'required|integer|exists:products,id',

            'main_categories'            => 'nullable|array',
            'main_categories.*.main_category_id' => 'required|integer|exists:categories,id',

            'sub_categories'             => 'nullable|array',
            'sub_categories.*.sub_category_id'   => 'required|integer|exists:sub_categories,id',

            'employee_id'  => 'nullable|exists:employee_details,id',
            'box_id'       => 'nullable|exists:boxes,id',
        ]);

        // 🔹 Find goal
        $goal = Goal::find($request->goal_id);
        if (!$goal) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.goal_not_found')
            ], 200);
        }

        // 🔹 Logical validation (same as create)
        if ($error = $this->validateGoalForm($request)) {
            return response()->json([
                'status'  => 'error',
                'message' => $error
            ], 200);
        }

        if($request->type ==='deposit_to_box'){
            $data['form'] = 'box';
        }
        elseif($request->type ==='finish_tasks'){
            $data['form'] = 'employee';
        }
        elseif($request->type ==='pay_person'){
            if($request->employee_id){
                $data['form'] = 'employee';
            }
            elseif($request->filled('people')){
                $data['form'] = 'people';

            }
        }

        // 🔹 Validate people input (only one of customer/seller allowed)
        if ($request->filled('people')) {
            foreach ($request->people as $person) {
                $customer = $person['customer_id'] ?? null;
                $seller   = $person['seller_id'] ?? null;

                if (($customer && $seller) || (!$customer && !$seller)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('messages.must_select_either_customer_or_seller'),
                    ], 200);
                }
            }
        }

        // 🔹 Recalculate achievement percentage
        $achievement_percentage = $data['targeted_value'] > 0
            ? (($data['current_value'] ?? 0) / $data['targeted_value']) * 100
            : 0;
        $data['achievement_percentage'] = $achievement_percentage;

        // 🔹 Update goal
        $goal->update($data);

        // 🔹 Clear old relations before reattaching
        GoalCategory::where('goal_id', $goal->id)->delete();
        GoalSubCategory::where('goal_id', $goal->id)->delete();
        GoalProduct::where('goal_id', $goal->id)->delete();
        GoalPeople::where('goal_id', $goal->id)->delete();

        // 🔹 Reattach new relations
        switch ($goal->form) {
            case 'main_categories':
                foreach ($request->main_categories ?? [] as $mainCategory) {
                    GoalCategory::create([
                        'goal_id'     => $goal->id,
                        'category_id' => $mainCategory['main_category_id'],
                    ]);
                }
                break;

            case 'sub_categories':
                foreach ($request->sub_categories ?? [] as $subCategory) {
                    GoalSubCategory::create([
                        'goal_id'        => $goal->id,
                        'sub_category_id'=> $subCategory['sub_category_id'],
                    ]);
                }
                break;

            case 'products':
                foreach ($request->products ?? [] as $product) {
                    GoalProduct::create([
                        'goal_id'    => $goal->id,
                        'product_id' => $product['product_id'],
                    ]);
                }
                break;

            case 'people':
                foreach ($request->people ?? [] as $person) {
                    GoalPeople::create([
                        'goal_id'     => $goal->id,
                        'customer_id' => $person['customer_id'] ?? null,
                        'seller_id'   => $person['seller_id'] ?? null,
                    ]);
                }
                break;
        }

        GoalLog::create([
            'title'=>'تعديل بيانات هدف ',
            'description'=>'تم تعديل بيانات هدف  باسم'.' '.$goal->name.
            ' '.'نسبة الانجاز الحالية'.' '.'%'.$goal->achievement_percentage,
            'goal_id' => $goal->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.goal_updated_successfully')
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.validation_failed'),
            'errors'  => $e->errors()
        ], 200);
    } 
    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.goal_not_found')
            ], 200);
        } 
    catch (QueryException $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.update_data_error')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status'  => 'error',
            'message' => __('messages.failed_to_update_goal')
        ], 200);
    }
}


    public function getGoals(){
        try{

                $goals = Goal::
                get(['id','scope','name','achievement_percentage','targeted_value','current_value','is_canceled',
                'created_at','due_date']);


           return response()->json([
                'status' => 'success',
                'goals' => $goals
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_load_goals')
            ], 200);
        }

        
    }
    // public function publicGoals(){
    //     return $this->getGoals('public');
    // }

    // public function privateGoals(){
    //     return $this->getGoals('private');

    // }

    //  public function completedGoals(){
    //     return $this->getGoals('completed');

    // }

    // public function canceledGoals(){
    //   try{
    //     $goals = Goal::where('is_canceled',1)
    //     ->orderBy('created_at','desc')
    //     ->get(['id','name','achievement_percentage','targeted_value','type']);

    //        return response()->json([
    //             'status' => 'success',
    //             'goals' => $goals
    //         ], 200);
    //     } catch (QueryException $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => __('messages.retrieve_data_error')
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => __('messages.failed_to_load_goals')
    //         ], 200);
    //     }

    // }

    private function changeGoal(Request $request,$filed,$value, $logTitle, $logMessage,$msgType){
        try{
        $request->validate(['goal_id'=>'required|exists:goals,id']);

        $goal = Goal::findOrFail($request->goal_id);
        $goal->update([$filed=>$value]);

        if($logTitle && $logMessage){
  
            GoalLog::create([

                'title'=>$logTitle,
                'description'=>$logMessage.' '.$goal->name,
                'goal_id' => $goal->id,
                 
                ]
            
            );
        }
            return response()->json([
                'status' => 'success',
                'message' => __('messages.goal_'.$msgType.'_successfully')
            ], 200);
            }

            catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed')
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.goal_not_found')
            ], 200);
        }
            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_cancel_goal')
            ], 200); }
         catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

    }


    public function cancelGoal(Request $request){
        return $this->changeGoal($request,'is_canceled',1 ,'الغاء هدف','تم الغاء هدف باسم','cancelled');

    }

public function transferGoal(Request $request)
{
    try {
        $request->validate(['goal_id' => 'required|exists:goals,id']);

        $goal = Goal::findOrFail($request->goal_id);

        // Toggle type
        $newType = $goal->scope === 'private' ? 'public' : 'private';

        // Update type
        $goal->update(['scope' => $newType]);

        // Log

        GoalLog::create([
            'title'=>'نقل هدف ',
            'description'=>'تم نقل هدف  باسم'.' '.$goal->name
            .' '.'الى'.' '.($newType==='public'?'عام':'خاص'),
            'goal_id' => $goal->id,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => __('messages.goal_transferred_successfully')
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed')
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.goal_not_found')
        ], 200);
    } catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.failed_to_transfer_goal')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
}


    //     public function restoreGoal(Request $request){
    //     try{
    //     $request->validate(['goal_id'=>'required|exists:goals,id']);

    //     $goal = Goal::findOrFail($request->goal_id);
    //     $goal->update(['is_canceled'=>0]);
    //     Logs::createLog('استعادة هدف ','استعادة هدف  باسم'.' '.$goal->name,'goals');

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => __('messages.goal_restored_successfully')
    //         ], 200);
    //         }

    //         catch (ValidationException $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => __('messages.validation_failed')
    //         ], 200);
    //     } catch (ModelNotFoundException $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => __('messages.goal_not_found')
    //         ], 200);
    //     }
    //         catch (QueryException $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => __('messages.failed_to_restore_goal')
    //         ], 200); }
    //      catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => __('messages.something_wrong')
    //         ], 200);
    //     }

    // }

    public function deleteGoal(Request $request){
        try{
            $request->validate(['goal_id'=>'required|integer|exists:goals,id']);

            $goal = Goal::findOrFail($request->goal_id);
            $goal->delete();

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.goal_deleted'),
            ],200);
        }
        catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed')
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.goal_not_found')
        ], 200);
    } catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.failed_to_transfer_goal')
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong')
        ], 200);
    }
    }
}

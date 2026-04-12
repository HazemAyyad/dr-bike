<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\Expense;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
class ExpensesAPI extends Controller
{
    private $expensesMediaPath = 'Expenses/ExpensesMedia';
    private $invoiceImagesPath = 'Expenses/InvoiceImages';

    private function mediaStorage(Request $request,$type,$fileName,$path){

        
        $files = [];
       // $imageName = null;
        if ($request->hasFile($fileName)) {
            if($type==='media'){
            foreach ($request->file($fileName) as $file) {
                $mimeType = $file->getMimeType();
                $folder = str_starts_with($mimeType, 'image') ? 'images' : 'videos';
                $fullName = $file->getClientOriginalName();
                $file->move(public_path($path . '/' . $folder), $fullName);
                $files[] = $fullName;
              }
           }
        //    elseif($type==='singleFile'){
        //         $imageName = $request->file($fileName)->getClientOriginalName();
        //         $request->file($fileName)->move(public_path($path), $imageName);
        //         $imageName = $imageName;
        //         return $imageName;
        //    }
            elseif($type==='multiImages'){
                foreach($request->file($fileName) as $imageFile){
                    $imageName = $imageFile->getClientOriginalName();
                    $imageFile->move(public_path($path), $imageName);
                    $files[] = $imageName;
                }

           }
           

        }

           return $files;

    }

    public function store(Request $request){
        try{
            $data = $request->validate([
                'name'=>'required|string|max:255',
                'price'=>'required|numeric|min:1',
                'notes' => 'nullable|string',
//                'payment_method' => 'required|string|max:255',
                'invoice_img' => 'nullable|array',
                'invoice_img.*' => 'nullable|file',

                'media' => 'nullable|array',
                'media.*' => 'file|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',

                'box_id' => 'required|integer|exists:boxes,id',
            ]);

            $box = Box::findOrFail($request->box_id);
            if(!$box->currency || $box->currency !== 'شيكل'){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.box_must_be_shekel'),
                ],200);
            }

            if($request->price > $box->total){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.box_out_of_money'),
                ],200);  
            }

            $files = $this->mediaStorage($request,'media','media',$this->expensesMediaPath);
            $invoice_img = $this->mediaStorage($request,'multiImages','invoice_img',$this->invoiceImagesPath);
            $data['media'] = $files;
            $data['invoice_img'] = $invoice_img;

            Expense::create($data);
            $box->total-= $request->price;
            $box->save();
            Logs::createLog('اضافة مصروف جديد','تم اضافة المصروف'.' '.$request->name.' '.'بسعر'.
            ' '. $request->price
        
            ,'expenses');

            BoxLogs::createBoxLog($box,'تم سحب رصيد من الصندوق لصرف مصروف باسم '.' '.$request->name
            ,'minus',$request->price);

            return response()->json([
                'status'=>'success',
                'message' => __('messages.expense_created'),
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


        public function showExpense(Request $request){
        try{

            $request->validate(['expense_id'=>'required|integer|exists:expenses,id']);

            $expense = Expense::with('box:id,name,total,currency')->
            findOrFail($request->expense_id);

            $formattedMedia  = [];
            if($expense->media && count($expense->media) > 0){
                foreach($expense->media as $file){
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                            $formattedMedia[] = 'public/'.$this->expensesMediaPath.'/images/'.$file;
                        }
                        else{
                            $formattedMedia[] = 'public/'.$this->expensesMediaPath.'/videos/'.$file;

                        }
            }

                }
        $formattedInvoice = [];
        if($expense->invoice_img && count($expense->invoice_img)>0){
            foreach($expense->invoice_img as $invoiceImage){
                $formattedInvoice[] = 'public/'.$this->invoiceImagesPath.'/'.$invoiceImage;
            }
        }
        $expense['media'] = $formattedMedia;
        $expense['invoice_img'] = $formattedInvoice;
        $expense->makeHidden('payment_method','box_id');
        return response()->json([
            'status' =>'success',
            'expense' => $expense,
        ],200);

      }  catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
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
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

        public function editExpense(Request $request){
        try{

            $data = $request->validate([
                'expense_id' => 'required|integer|exists:expenses,id',
                'name'=>'required|string|max:255',
 //               'price'=>'required|numeric|min:1',
 //               'payment_method' => 'required|string',
                'notes' => 'nullable|string',
                'invoice_img' => 'nullable|array',
                'invoice_img.*' => 'nullable',

                'media' => 'nullable|array',
                'media.*' => [
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if (is_string($value)) {
                            // must be a string filename, skip further checks
                            return;
                        }

                        if ($value instanceof \Illuminate\Http\UploadedFile) {
                             $allowed = ['image/jpeg','image/png','image/jpg','image/gif','image/tiff','image/webp','image/avif','image/svg+xml','video/mp4','video/quicktime','video/x-msvideo','video/x-ms-wmv','video/x-matroska','video/webm'];
                            if (! in_array($value->getMimeType(), $allowed)) {
                                $fail("The {$attribute} must be a valid image or video file.");
                            }
                        } else {
                            $fail("The {$attribute} must be either a filename or an uploaded file.");
                        }
                    },
                ],
            ]);

            $expense = Expense::findOrFail($data['expense_id']);
            $updatedData = Arr::except($data, ['expense_id', 'media','invoice_img']);
            $expense->update($updatedData);

            $finalMedia = Assets::handleMediaUpdate($request, 'media', $this->expensesMediaPath, $expense->media);
            $invoiceImages = CommonUse::handleImageUpdate($request,'invoice_img',$this->invoiceImagesPath,$expense->invoice_img);
            $expense->update([
                'media' => $finalMedia,
                'invoice_img' => $invoiceImages,
            ]);   
            Logs::createLog('تعديل بيانات مصروف ','تم تعديل بيانات المصروف'.' '.$expense->name.' '.'بسعر'.
            ' '. $expense->price
        
            ,'expenses');
            return response()->json([
                'status'=>'success',
                'message'=> __('messages.expense_updated'),
            ],200);
}

       catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
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
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function getExpenses(){
        try{
            $expenses = Expense::all();
            $formatted = $expenses->map(function($expense){

                    $imagePath = null;

                    if (is_array($expense->media) && count($expense->media) > 0) {
                        foreach ($expense->media as $file) {
                            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                            if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                                // found the first image → stop searching
                                $imagePath = 'public/'.$this->expensesMediaPath.'/images/' . $file;
                                break;
                            }
                        }
                    }
                return [
                    'id'=>$expense->id,
                    'name' => $expense->name,
                    'price' => $expense->price,
                    'created_at' => $expense->created_at? $expense->created_at->format('Y-m-d') : 'no date',
                    'image'=> $imagePath,
                ];
            });
            return response()->json([
                'status'=>'success',
                'expenses' => $formatted,
  
            ]);
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
}

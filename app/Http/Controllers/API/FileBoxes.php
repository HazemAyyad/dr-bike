<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FileBox;
use App\Models\Treasury;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class FileBoxes extends Controller
{
      public function store(Request $request){
        try{
           $data = $request->validate([
                'name'=>'required|string|max:255',
                'treasury_id' => 'required|exists:treasuries,id'
            ]);

            FileBox::create($data);
            $treasury = Treasury::findOrFail($request->treasury_id);
            Logs::createLog('اضافة فايل بوكس جديد','تم اضافة فايل بوكس جديد باسم'.' '.$request->name
            
            .' '.'تابع للخزنة'.' '.$treasury->name
            
            ,'fileboxes');
        
            return response()->json([
                'status'=>'success',
                'message' => __('messages.fileBox_created'),
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

    public function allFileBoxes(){
        try{

        $fileBoxes = FileBox::where('is_cancelled', 0)
            ->with(['files' => function($q) {
                $q->where('is_canceled', 0)
                ->select('id', 'file_box_id', 'name');
            }])
            ->get(['id', 'name']);

            return response()->json([
                'status'=>'success',
                'file_boxes' => $fileBoxes,
            ],200);

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

    public function fileBoxDetails(Request $request){
        try{
            $request->validate(['file_box_id'=>'required|integer|exists:file_boxes,id']);
            $fileBox = FileBox::
            findOrFail($request->file_box_id);

            $files = $fileBox->files()->where('is_canceled',0)->get();
            $formatted = $files->map( function($file) use($fileBox){
                return [
                    'file_box_id' => $fileBox->id,
                    'file_box_name' => $fileBox->name,

                    'file_id'=> $file->id,
                    'file_name'=> $file->name,
                    'treasury_id' => $fileBox->treasury_id,
                    'treasury_name' => $fileBox->treasury->name,

                ];
            });

            return response()->json([
                'status'=>'success',
                'filebox_details' => $formatted,
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
                'message' => __('messages.something_wrong')
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

      public function cancelFileBox(Request $request){
        try{
            $request->validate([
                'file_box_id'=>'required|integer|exists:file_boxes,id'
            ]);

            $fileBox = FileBox::findOrFail($request->file_box_id);
            $fileBox->update(['is_cancelled'=>1]);
            $fileBox->files()->update(['is_canceled'=>1]);
           foreach ($fileBox->files as $file) {
                foreach ($file->papers as $paper) {  
                                $paper->update(['is_cancelled' => 1]);


                        }
        }
            Logs::createLog('حذف فايل بوكس ','تم حذف فايل بوكس باسم'.' '.$fileBox->name
            
            .' '.'تابع للخزنة'.' '.$fileBox->treasury->name
            
            ,'fileboxes');
            return response()->json([
                'status'=>'success',
                'message' =>__('messages.fileBox_cancelled'),
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
                        'message' => __('messages.something_wrong')
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

}
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\FileBox;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
class Files extends Controller
{
          public function store(Request $request){
        try{
           $data = $request->validate([
                'name'=>'required|string|max:255',
                'file_box_id' => 'required|exists:file_boxes,id'
            ]);

            File::create($data);
            $fileBox = FileBox::findOrFail($request->file_box_id);
            Logs::createLog('اضافة فايل  جديد','تم اضافة فايل  جديد باسم'.' '.$request->name
           
           .' '.'تابع للفايل بوكس'.' '. $fileBox->name.' '.'داخل الخزنة'.' '.$fileBox->treasury->name
           
           , 'files');
        
            return response()->json([
                'status'=>'success',
                'message' => __('messages.file_created'),
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

    public function cancelFile(Request $request){
        try{
            $request->validate(['file_id'=>'required|integer|exists:files,id']);

            $file = File::findOrFail($request->file_id);
            $file->update(['is_canceled'=>1]);
            $file->papers()->update(['is_cancelled'=>1]);
            Logs::createLog('حذف فايل  ','تم حذف فايل باسم'.' '.$file->name
           
           .' '.'تابع للفايل بوكس'.' '. $file->fileBox->name.' '.'داخل الخزنة'.' '.$file->fileBox->treasury->name
           
           , 'files');
            return response()->json([
                'status'=>'success',
                'message' => __('messages.file_deleted'),
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
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }


        public function allFiles(Request $request){
        try{
            $filters = $request->validate([
                'search' => 'nullable|string|max:255',
                'file_box_id' => 'nullable|integer|exists:file_boxes,id',
                'treasury_id' => 'nullable|integer|exists:treasuries,id',
                'status' => 'nullable|string|in:active,archived,all',
            ]);
            $status = $filters['status'] ?? 'active';
            $files = File::query()
                ->with('fileBox.treasury:id,name')
                ->when($status === 'active', fn ($q) => $q->where('is_canceled', 0))
                ->when($status === 'archived', fn ($q) => $q->where('is_canceled', 1))
                ->when($filters['file_box_id'] ?? null, fn ($q, $id) => $q->where('file_box_id', $id))
                ->when($filters['treasury_id'] ?? null, fn ($q, $id) => $q->whereHas('fileBox', fn ($box) => $box->where('treasury_id', $id)))
                ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
                ->get();
            return response()->json([
                'status'=>'success',
                'files' => $files,
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

    public function getFileDetails(Request $request){
            try{
            $request->validate(['file_id'=>'required|integer|exists:files,id']);
            $file = File::
            findOrFail($request->file_id);

            $papers = $file->papers()->where('is_cancelled',0)->get();
            $formatted = $papers->map( function($paper) use($file){
                return [
                    'file_id' => $file->id,
                    'file_name' => $file->name,

                    'paper_id'=> $paper->id,
                    'paper_name'=> $paper->name,
                    'paper_image' => $paper->img? 'public/Papers/'.$paper->img[0]: 'no img',
                    'file_box_name' => $file->fileBox?->name,
                    'treasury_name' => $file->fileBox?->treasury?->name,

                ];
            });

            return response()->json([
                'status'=>'success',
                'file_papers' => $formatted,
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
}

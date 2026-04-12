<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\FileBox;
use App\Models\Paper;
use App\Models\Treasury;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Papers extends Controller
{


    private  function storeImage(Request $request,$fileName,$path){
        $imgNames = [];
        if ($request->hasFile($fileName)) {
            foreach($request->file($fileName) as $file){
                $fullName = $file->getClientOriginalName();
                $file->move(public_path($path.'/'), $fullName);
                $imgNames[] = $fullName;
            }
        }

        return $imgNames;
    }
    public function store(Request $request){
        try{
            $data = $request->validate([
                'name'=>'required|string|max:255',
                'file_id' => 'required|integer|exists:files,id',
                'img'=>'nullable|array',
                'img.*' => 'required|file',
                'notes'=>'nullable|string',

            ]);

        // // Step 1: Check if file_box belongs to treasury
        // $fileBox = FileBox::where('id', $data['file_box_id'])
        //     ->where('treasury_id', $data['treasury_id'])
        //     ->first();

        // if (!$fileBox) {
        //     return response()->json([
        //         'status'=>'error',
        //         'message'=>__('messages.file_box_not_for_treasury_selected'),
        //     ],200);
        // }

        // //  Step 2: Check if file belongs to file_box
        // $file = File::where('id', $data['file_id'])
        //     ->where('file_box_id', $data['file_box_id'])
        //     ->first();

        // if (!$file) {
        //     return response()->json([
        //         'status'=>'error',
        //         'message'=>__('messages.file_not_for_fil_box_selected'),
        //     ],200);
        // }
            $imageNames = $this->storeImage($request,'img','Papers');
            $data['img'] = $imageNames;
            Paper::create($data);

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.paper_created'),
            ]);


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
                'message' => __('messages.create_data_error')
            ], 200);
        }
        
        catch (QueryException $e) {
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


    public function getPapers(){
        try{
            $papers = Paper::where('is_cancelled',0)
            ->get(['id','file_id','name','img','created_at','notes']);

            $formatted = $papers->map(function($paper){
                $images = [];
                if($paper->img && count($paper->img)>0){
                    foreach($paper->img as $img){
                        $images[] = 'public/Papers/'.$img;
                    }
                }
                return [
                    'paper_id'=>$paper->id,
                    'paper_name'=>$paper->name,
                    'treasury_name'=>$paper->file->fileBox->treasury->name,
                    'file_box_name'=>$paper->file->fileBox->name,
                    'file_name'=>$paper->file->name,

                    'img'=>$images,
                    'created_at' => $paper->created_at? $paper->created_at->format('Y-m-d'):'no date',
                    'note' => $paper->notes,
                ];
            });

            return response()->json([
                'status'=>'success',
                'papers'=>$formatted,
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


    public function cancelPaper(Request $request){
        try{

            $request->validate(['paper_id'=>'required|integer|exists:papers,id']);

            $paper = Paper::findOrFail($request->paper_id);
            $paper->update(['is_cancelled'=>1]);

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.paper_cancelled'),
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

    public function showPaper(Request $request){
        try{
            $request->validate(['paper_id'=>'required|integer|exists:papers,id']);

            $paper = Paper::findOrFail($request->paper_id);
            $images = [];
                if($paper->img && count($paper->img)>0){
                    foreach($paper->img as $img){
                        $images[] = 'public/Papers/'.$img;
                    }
                }
            $formatted =  [
                    'paper_id'=>$paper->id,
                    'paper_name'=>$paper->name,
                    'treasury_name'=>$paper->file->fileBox->treasury->name,
                    'file_box_name'=>$paper->file->fileBox->name,
                    'file_name'=>$paper->file->name,

                    'img'=>$images,
                    'created_at' => $paper->created_at? $paper->created_at->format('Y-m-d'):'no date',

                ];
            return response()->json([
                'status'=>'success',
                'paper'=> $formatted,
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


        public function editPaper(Request $request){
        try{

            $data = $request->validate([
                'paper_id'=>'required|integer|exists:papers,id',
                'name'=>'required|string|max:255',
                'file_id' => 'required|integer|exists:files,id',
                'img'=>'nullable|array',
                'img.*' => 'nullable',
                'notes'=>'nullable|string',
            ]);

            $paper = Paper::findOrFail($request->paper_id);
            $data['img'] = CommonUse::handleImageUpdate($request,'img','Papers',$paper->img);
            $paper->update($data);
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.paper_updated'),
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

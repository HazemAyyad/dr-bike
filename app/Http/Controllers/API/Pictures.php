<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Picture;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class Pictures extends Controller
{
public static function storeImage(Request $request, string $fileName, string $path, $existing = null)
{
    if ($request->hasFile($fileName)) {
        // delete old file if exists
        if ($existing && file_exists(public_path($path . '/' . $existing))) {
            unlink(public_path($path . '/' . $existing));
        }

        // store new file
        $file = $request->file($fileName);
        $fullName = $file->getClientOriginalName();
        $file->move(public_path($path . '/'), $fullName);

        return $fullName;
    }

    if(is_string($request->input($fileName))){
       return basename($request->input($fileName));
    }
}


    public function store(Request $request){
        try{
            $data = $request->validate([
                'name'=>'required|string|max:255',
                'description'=>'nullable|string',
                'file'=>'nullable|file|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',
            ]);

            $imgName = $this->storeImage($request,'file','Pictures');
            Picture::create([
                'name'=>$data['name'],
                'description' => $data['description'],
                'file'=> $imgName,
            ]);

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.picture_created'),
            ],200);
        }

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
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function getAllPictures(){
        try{
            
            $pictures = Picture::all();

            $formatted = $pictures->map(function($picture){
                return [
                    'id'=> $picture->id,
                    'name' => $picture->name,
                    'description' => $picture->description,
                    'file' => $picture->file? 'public/Pictures/'.$picture->file:'no file',
                    'created_at' => $picture->created_at? $picture->created_at->format('Y-m-d'):'no date',
                ];
            });

            
            return response()->json([
                'status'=>'success',
                'pictures'=>$formatted,
            ],200);
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

    public function showPicture(Request $request){
        try{

            $request->validate(['picture_id'=>'required|integer|exists:pictures,id']);

            $picture = Picture::findOrFail($request->picture_id);
            $picture['file'] = $picture->file? 'public/Pictures/'.$picture->file:'no file';
            $picture['created_at'] = $picture->created_at? $picture->created_at->format('Y-m-d'):'no date';
            $picture->makeHidden('updated_at');

            return response()->json([
                'status'=>'success',
                'picture'=> $picture,
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

    public function editPicture(Request $request){
        try{

            $data = $request->validate([
               'picture_id'=>'required|integer|exists:pictures,id',
                'name'=>'required|string|max:255',
                'description'=>'nullable|string',
                'file'=>'nullable',
            ]);

            $picture = Picture::findOrFail($request->picture_id);
            $data['file'] = $this->storeImage($request, 'file', 'Pictures', $picture->file);
            $picture->update($data);
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.picture_updated'),
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

    public function deletePicture(Request $request){
        try{

            $data = $request->validate([
               'picture_id'=>'required|integer|exists:pictures,id',
             ]);

            $picture = Picture::findOrFail($request->picture_id);


            if ($picture->file && file_exists(public_path('Pictures/' . $picture->file))) {
                unlink(public_path('Pictures/' . $picture->file));
            }

            $picture->delete();

            return response()->json([
                'status'=>'success',
                'message'=>__('messages.picture_deleted'),
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



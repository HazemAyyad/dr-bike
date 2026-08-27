<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Picture;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class Pictures extends Controller
{
public static function storeImage(Request $request, string $fileName, string $path, $existing = null)
{
    if ($request->hasFile($fileName)) {
        // store new file
        $file = $request->file($fileName);
        $extension = strtolower($file->getClientOriginalExtension());
        $fullName = (string) Str::uuid().($extension ? '.'.$extension : '');
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
                'file'=>'nullable|file|max:30720|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',
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

    public function getAllPictures(Request $request){
        try{
            $filters = $request->validate([
                'search' => 'nullable|string|max:255',
                'status' => 'nullable|string|in:active,archived,all',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'has_media' => 'nullable|boolean',
            ]);
            $status = $filters['status'] ?? 'active';
            $pictures = Picture::query()
                ->when($status === 'active', fn ($q) => $q->where('is_cancelled', false))
                ->when($status === 'archived', fn ($q) => $q->where('is_cancelled', true))
                ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")))
                ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
                ->when(isset($filters['has_media']), fn ($q) => $filters['has_media'] ? $q->whereNotNull('file') : $q->whereNull('file'))
                ->latest('id')
                ->get();

            $formatted = $pictures->map(function($picture){
                return [
                    'id'=> $picture->id,
                    'name' => $picture->name,
                    'description' => $picture->description,
                    'file' => $picture->file? 'public/Pictures/'.$picture->file:'no file',
                    'created_at' => $picture->created_at? $picture->created_at->format('Y-m-d'):'no date',
                    'is_cancelled' => (bool) $picture->is_cancelled,
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
                'file' => [
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if (is_string($value)) {
                            return; // Preserve the existing file reference.
                        }
                        $allowed = ['image/jpeg','image/png','image/jpg','image/gif','image/tiff','image/webp','image/avif','image/svg+xml','video/mp4','video/quicktime','video/x-msvideo','video/x-ms-wmv','video/x-matroska','video/webm'];
                        if (! $value instanceof \Illuminate\Http\UploadedFile || ! in_array($value->getMimeType(), $allowed, true)) {
                            $fail("The {$attribute} must be a valid image or video file.");
                            return;
                        }
                        if ($value->getSize() > 30 * 1024 * 1024) {
                            $fail("The {$attribute} may not be greater than 30 MB.");
                        }
                    },
                ],
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


            $picture->update([
                'is_cancelled' => true,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $request->user()?->id,
            ]);

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

<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class Assets extends Controller
{
    private $assetMediaPath = 'AssetsMedia';
    private function fileStorage(Request $request){
        $files = [];
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $mimeType = $file->getMimeType();
                $folder = str_starts_with($mimeType, 'image') ? 'images' : 'videos';

                $fullName = $file->getClientOriginalName();
                $file->move(public_path($this->assetMediaPath.'/'.$folder), $fullName);
                $files[] = $fullName;
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
                'depreciation_rate' => 'required|numeric|min:0',
                'months_number' => 'required|numeric|min:1',
                'media' => 'nullable|array',
                'media.*' => 'file|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',

            ]);

            $files = $this->fileStorage($request);
            $data['media'] = $files;
            $data['depreciation_price'] = $request->price;
            $data['depreciation_rate'] = $request->depreciation_rate/100;

           $asset = Asset::create($data);
            Logs::createLog('اضافة أصل جديد','تم اضافة الأصل'.' '.$request->name.' '.'بسعر'.

            ' '.$request->price.' '.'ونسبة هلاك بقيمة'.' '. $request->depreciation_rate
            
            ,'assets');
            AssetLog::create([
                'asset_id'=> $asset->id,
                'total' => $asset->depreciation_price??0,
                'type' =>'create',
            ]);

            return response()->json([
                'status'=>'success',
                'message' => __('messages.asset_created'),
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


    public function getAssets(){
        try{

            $assets = Asset::all();
            $formatted = AssetResource::collection($assets);

            return response()->json([
                'status'=>'success',
                'assets' => $formatted,
                'total_assets_original_prices' => Asset::assetsCurrentDepricationSum(),
                'total_assets_depreciate_prices' => Asset::assetsCurrentDepricationSum(),
                'average_depreciation_rate' => Asset::depreciateAverage(),
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


    private function commonDepreciate(Asset $asset){
            if($asset->depreciation_price >0){
                    $depreciation_value = $asset->depreciation_price * $asset->depreciation_rate;
                    $asset->depreciation_price -= $depreciation_value;
                    $asset->save();

                    AssetLog::create([
                        'asset_id' => $asset->id,
                        'total' => $asset->depreciation_price,
                        'type' =>'depreciate',

                    ]);

                return response()->json([
                'status'=>'success',
                'message' => __('messages.asset_depreciated'),
            ],200);
    }
    else{
        return response()->json([
            'status'=>'error',
            'message'=>__('messages.cannot_depreciate'),
        ],200);
    }
}
    public function depreciateOneAsset(Request $request){
        try{
            $request->validate(['asset_id'=>'required|integer|exists:assets,id']);

            $asset = Asset::findOrFail($request->asset_id);
           return $this->commonDepreciate($asset);


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
    public function depreciatAllAssets(){
        try{
            $assets = Asset::all();
            foreach($assets as $asset){
                if($asset->depreciation_price >0){
                        $depreciation_value = $asset->depreciation_price * $asset->depreciation_rate;
                        $asset->depreciation_price -= $depreciation_value;
                        $asset->save();

                        AssetLog::create([
                            'asset_id' => $asset->id,
                            'total' => $asset->depreciation_price,
                            'type' =>'depreciate',

                        ]);
                }


        }

            return response()->json([
                'status'=>'success',
                'message' => __('messages.asset_depreciated'),
            ],200);

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

    public function showAsset(Request $request){
        try{


            $request->validate(['asset_id'=>'required|integer|exists:assets,id']);

            $asset = Asset::findOrFail($request->asset_id);

            $formattedMedia  = [];
            if($asset->media && count($asset->media) > 0){
                foreach($asset->media as $file){
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                            $formattedMedia[] = 'public/'.$this->assetMediaPath.'/images/'.$file;
                        }
                        else{
                            $formattedMedia[] = 'public/'.$this->assetMediaPath.'/videos/'.$file;

                        }
            }

        }
        $asset['media'] = $formattedMedia;
        $asset->makeHidden(['depreciation_price']);
        $asset['logs'] = $asset->logs()->get(['total','created_at','type']);
        return response()->json([
            'status' =>'success',
            'asset' => $asset,
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


    public function editAsset(Request $request){
        try{

            $data = $request->validate([
                'asset_id' => 'required|integer|exists:assets,id',
                'name'=>'required|string|max:255',
                'price'=>'required|numeric|min:1',
                'notes' => 'nullable|string',
                'depreciation_rate' => 'required|numeric|min:0',
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

            $asset = Asset::findOrFail($data['asset_id']);
            $updatedData = Arr::except($data, ['asset_id', 'media']);
            $data['depreciation_rate'] = $request->depreciation_rate/100;
            $asset->update($updatedData);
            $finalMedia = $this->handleMediaUpdate($request, 'media', $this->assetMediaPath, $asset->media);
            $asset->update(['media' => $finalMedia]);   

            return response()->json([
                'status'=>'success',
                'message'=> __('messages.asset_updated'),
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



    // for assets edit media
    public static function handleMediaUpdate(Request $request, string $field, string $basePath, array $currentFiles = []): array
{
      $keepFiles = [];
      $newFiles = [];

    // 1. Keep existing files if user sends full path string
    $requestItems = $request->input($field, []);
    foreach ($requestItems as $item) {
        if (is_string($item)) {
            $filename = basename($item); // extract filename if it's a URL
            if (in_array($filename, $currentFiles)) {
                $keepFiles[] = $filename;
            }      
          }
    }

    // 2. Handle new uploads
    if ($request->hasFile($field)) {
        foreach ($request->file($field) as $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $mimeType = $file->getMimeType();
                $folder = str_starts_with($mimeType, 'image') ? 'images' : 'videos';

                $fileName = $file->getClientOriginalName();
                $file->move(public_path($basePath.'/'.$folder), $fileName);

                // Store full relative path (same style as you send in request)
                $newFiles[] = $fileName;
            }
        }
    }

    // 3. Delete removed files
    $removedFiles = array_diff($currentFiles, $keepFiles);
    foreach ($removedFiles as $oldFile) {
        $filePath = public_path(str_replace('public/', '', $oldFile));
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // 4. Return merged array
    return array_merge($keepFiles, $newFiles);

}

    public function deleteAsset(Request $request){
        try{
            $request->validate(['asset_id' => 'required|integer|exists:assets,id',
        ]);

            $asset = Asset::findOrFail($request->asset_id);
            if($asset->media && is_array($asset->media) && count($asset->media)>0){
                foreach($asset->media as $file){
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        $type = '';
                        if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                            $type= 'images';
                        }
                        else{
                            $type = 'videos';
                        }

                        $filePath = public_path($this->assetMediaPath . '/' . $type.'/'.$file);
                        if (file_exists($filePath)) {
                            unlink($filePath);
        
                        }
                }
            }

            $asset->delete();
            return response()->json([
                'status'=>'success',
                'message'=>__('messages.asset_deleted'),
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


}
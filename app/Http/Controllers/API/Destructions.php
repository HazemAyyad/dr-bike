<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Destruction;
use App\Http\Resources\DestructionResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
class Destructions extends Controller
{

    private $destructionMediaPath = 'DestructionsMedia';

    private function fileStorage(Request $request)
    {
        $files = [];
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $mimeType = $file->getMimeType();
                $folder = str_starts_with($mimeType, 'image') ? 'images' : 'videos';

                $destinationPath = public_path($this->destructionMediaPath . '/' . $folder);

                $fullName =  $file->getClientOriginalName();
                $file->move($destinationPath, $fullName);
                $files[] = $fullName;
            }
        }
        return $files;
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'product_id' => 'required|exists:products,id',
                'pieces_number' => 'required|integer|min:1',
                'destruction_reason' => 'nullable|string',
                'media' => 'nullable|array',
                'media.*' => 'file|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',
            ]);

            $files = $this->fileStorage($request);
            $data['media'] = $files;
            $product = Product::findOrFail($request->product_id);
            if( ($product->stock <= 0) || ($product->stock < $request->pieces_number)){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.stcok_failed'),
                ],200);
            }
            $destruction = Destruction::create($data);
            $newStock = $product->stock - $request->pieces_number;
            $product->update(['stock'=> $newStock ]);
            if ($product->stock === 0) {
                $closeout = $product->closeout;

                if ($closeout) { // check if it exists
                    $closeout->status = 'archived'; 
                    $closeout->save();
                }
            }

            Logs::createLog(
                'اضافة اتلاف بضاعة جديد',
                'تم اضافة اتلاف البضاعة ' . ($destruction->product->nameAr ? $destruction->product->nameAr : 'غير معروف') . ' بنجاح',
                'destructions'
            );


            return response()->json([
                'status' => 'success',
                'message' => __('messages.destruction_created'),
            ], 200);
        }
        catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error')
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function getDestructions()
    {
        try {
            $destructions = Destruction::all();
            $formatted = DestructionResource::collection($destructions);

            return response()->json([
                'status' => 'success',
                'destructions' => $formatted,
            ], 200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 500);
        }

            catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    public function showDestruction(Request $request){
        try{
            $request->validate(['destruction_id'=>'required|integer|exists:destructions,id']);

            $destruction = Destruction::with('product:id,nameAr')
            ->findOrFail($request->destruction_id);

            $media = [];
            if($destruction->media && count($destruction->media)>0){
                foreach($destruction->media as $file){
                        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        $type = null;
                        if(in_array($extension,['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])){
                            $type='images';
                        }
                        elseif(in_array($extension,['mp4','quicktime','x-msvideo','x-ms-wmv','x-matroska','webm'])){
                            $type='videos';
                        }
                    $media[] = 'public/DestructionsMedia/'.$type.'/'.$file;
                }
            }
            $destruction->makeHidden('product_id');
            $destruction['media'] = $media;

            return response()->json([
                'status'=>'success',
                'destruction' => $destruction,
            ],200);

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

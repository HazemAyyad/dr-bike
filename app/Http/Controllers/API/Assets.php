<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetLog;
use App\Services\MonthlyAssetDepreciationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Assets extends Controller
{
    private $assetMediaPath = 'AssetsMedia';
    private function fileStorage(Request $request){
        $files = [];
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $mimeType = $file->getMimeType();
                $folder = str_starts_with($mimeType, 'image') ? 'images' : 'videos';

                $extension = strtolower($file->getClientOriginalExtension());
                $fullName = (string) Str::uuid().($extension ? '.'.$extension : '');
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
                'media' => 'nullable|array|max:15',
                'media.*' => 'file|max:30720|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',

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


    public function getAssets(Request $request){
        try{
            $filters = $request->validate([
                'search' => 'nullable|string|max:255',
                'from' => 'nullable|date',
                'to' => 'nullable|date|after_or_equal:from',
                'status' => 'nullable|string|in:active,fully_depreciated,depreciated_this_month,pending_this_month',
                'min_value' => 'nullable|numeric|min:0',
                'max_value' => 'nullable|numeric|gte:min_value',
            ]);
            $period = now()->format('Y-m');
            $query = Asset::query()
                ->withExists([
                    'logs as depreciated_this_month' => fn (Builder $log) =>
                        $log->where('depreciation_period', $period),
                ])
                ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where('name', 'like', "%{$search}%"))
                ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
                ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to))
                ->when(isset($filters['min_value']), fn (Builder $q) => $q->where('depreciation_price', '>=', $filters['min_value']))
                ->when(isset($filters['max_value']), fn (Builder $q) => $q->where('depreciation_price', '<=', $filters['max_value']))
                ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->where('depreciation_price', '>', 0))
                ->when(($filters['status'] ?? null) === 'fully_depreciated', fn (Builder $q) => $q->where('depreciation_price', '<=', 0))
                ->when(($filters['status'] ?? null) === 'depreciated_this_month', fn (Builder $q) => $q->whereHas('logs', fn (Builder $log) => $log->where('depreciation_period', $period)))
                ->when(($filters['status'] ?? null) === 'pending_this_month', fn (Builder $q) => $q->where('depreciation_price', '>', 0)->whereDoesntHave('logs', fn (Builder $log) => $log->where('depreciation_period', $period)));
            $assets = $query->latest('id')->get();
            $formatted = AssetResource::collection($assets);

            return response()->json([
                'status'=>'success',
                'assets' => $formatted,
                'total_assets_original_prices' => round((float) (clone $query)->sum('price'), 2),
                'total_assets_depreciate_prices' => round((float) (clone $query)->sum('depreciation_price'), 2),
                'accumulated_depreciation' => round((float) ((clone $query)->sum('price') - (clone $query)->sum('depreciation_price')), 2),
                'average_depreciation_rate' => round((float) (clone $query)->avg('depreciation_rate'), 4),
                'assets_count' => $assets->count(),
                'depreciation_period' => $period,
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


    public function depreciateOneAsset(Request $request, MonthlyAssetDepreciationService $service){
        try{
            $request->validate(['asset_id'=>'required|integer|exists:assets,id']);

            $result = $service->run(now()->format('Y-m'), $request->user()?->id, (int) $request->asset_id);
            return response()->json([
                'status' => $result['processed'] > 0 ? 'success' : 'error',
                'message' => $result['processed'] > 0
                    ? __('messages.asset_depreciated')
                    : 'تم تنفيذ إهلاك هذا الأصل مسبقًا لهذا الشهر أو أن قيمته صفر.',
                'depreciation_period' => now()->format('Y-m'),
                'result' => $result,
            ]);


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
    public function depreciatAllAssets(Request $request, MonthlyAssetDepreciationService $service){
        try{
            $result = $service->run(now()->format('Y-m'), $request->user()?->id);

            return response()->json([
                'status'=>'success',
                'message' => __('messages.asset_depreciated'),
                'depreciation_period' => now()->format('Y-m'),
                'result' => $result,
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

    public function depreciationPreview(Request $request)
    {
        try {
            $period = now()->format('Y-m');
            $assets = Asset::query()
                ->withExists([
                    'logs as depreciated_this_month' => fn ($query) =>
                        $query->where('depreciation_period', $period),
                ])
                ->orderBy('name')
                ->get();

            $rows = $assets->map(function (Asset $asset) use ($period) {
                $before = max(0, (float) $asset->depreciation_price);
                $rate = max(0, (float) $asset->depreciation_rate);
                $alreadyProcessed = (bool) $asset->depreciated_this_month;
                $eligible = ! $alreadyProcessed && $before > 0 && $rate > 0;
                $amount = $eligible ? min($before, round($before * $rate, 2)) : 0;

                return [
                    'asset_id' => $asset->id,
                    'name' => $asset->name,
                    'period' => $period,
                    'value_before' => round($before, 2),
                    'depreciation_rate' => $rate,
                    'depreciation_amount' => round($amount, 2),
                    'value_after' => round(max(0, $before - $amount), 2),
                    'eligible' => $eligible,
                    'already_depreciated' => $alreadyProcessed,
                    'skip_reason' => $eligible
                        ? null
                        : ($alreadyProcessed
                            ? 'تم إهلاك الأصل لهذا الشهر'
                            : ($before <= 0 ? 'اكتمل إهلاك الأصل' : 'نسبة الإهلاك صفر')),
                ];
            });

            $eligible = $rows->where('eligible')->values();

            return response()->json([
                'status' => 'success',
                'period' => $period,
                'summary' => [
                    'assets_count' => $assets->count(),
                    'eligible_count' => $eligible->count(),
                    'skipped_count' => $assets->count() - $eligible->count(),
                    'value_before' => round((float) $eligible->sum('value_before'), 2),
                    'depreciation_amount' => round((float) $eligible->sum('depreciation_amount'), 2),
                    'value_after' => round((float) $eligible->sum('value_after'), 2),
                ],
                'assets' => $eligible,
                'skipped_assets' => $rows->where('eligible', false)->values(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
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
        $period = now()->format('Y-m');
        $asset['depreciated_this_month'] = $asset->logs()
            ->where('depreciation_period', $period)
            ->exists();
        $asset['depreciation_period'] = $period;
        $asset['logs'] = $asset->logs()
            ->get(['total','created_at','type','depreciation_period']);
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
                'media' => 'nullable|array|max:15',
                'media.*' => [
                    'nullable',
                    function ($attribute, $value, $fail) {
                        if (is_string($value)) {
                            // must be a string filename, skip further checks
                            return;
                        }

                        if ($value instanceof \Illuminate\Http\UploadedFile) {
                            if ($value->getSize() > 30 * 1024 * 1024) {
                                $fail("The {$attribute} may not be greater than 30 MB.");
                                return;
                            }
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

                $extension = strtolower($file->getClientOriginalExtension());
                $fileName = (string) Str::uuid().($extension ? '.'.$extension : '');
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

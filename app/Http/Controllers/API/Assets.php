<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\AccountingJournalEntry;
use App\Models\Asset;
use App\Models\AssetLog;
use App\Models\Box;
use App\Services\AssetDepreciationCalculator;
use App\Services\ExpenseBoxAccessService;
use App\Services\MonthlyAssetDepreciationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Assets extends Controller
{
    private $assetMediaPath = 'AssetsMedia';

    private function fileStorage(Request $request)
    {
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

    public function store(Request $request, ExpenseBoxAccessService $access)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'price' => 'required|numeric|min:1',
                'notes' => 'nullable|string',
                'depreciation_rate' => 'nullable|numeric|min:0',
                'months_number' => 'required|integer|min:1',
                'box_id' => 'required|integer|exists:boxes,id',
                'acquired_at' => 'nullable|date',
                'media' => 'nullable|array|max:15',
                'media.*' => 'file|max:30720|mimetypes:image/jpeg,image/png,image/jpg,image/gif,image/tiff,image/webp,image/avif,image/svg+xml,video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/x-matroska,video/webm',

            ]);

            if (! $access->canUse($request->user(), (int) $data['box_id'])) {
                throw ValidationException::withMessages(['box_id' => ['الصندوق غير مسموح للموظف أو أن جلسته اليومية مغلقة.']]);
            }

            $files = $this->fileStorage($request);
            $data['media'] = $files;
            $data['depreciation_price'] = $request->price;
            $data['depreciation_rate'] = round(1 / (int) $data['months_number'], 8);
            $data['acquired_at'] = $data['acquired_at'] ?? now()->toDateString();

            DB::transaction(function () use ($data, $request) {
                $box = Box::query()->lockForUpdate()->findOrFail($data['box_id']);
                if ((float) $box->total + 0.0001 < (float) $data['price']) {
                    throw ValidationException::withMessages(['price' => [__('messages.box_out_of_money')]]);
                }
                $data['currency'] = $box->currency ?: 'شيكل';
                $box->update(['total' => (float) $box->total - (float) $data['price']]);
                $asset = Asset::create($data);
                BoxLogs::createBoxLog($box, 'شراء أصل: '.$asset->name, 'minus', (float) $asset->price, $asset->notes);
                Logs::createLog(
                    'اضافة أصل جديد',
                    'تم اضافة الأصل '.$request->name.' بسعر '.$request->price.' وعمر إنتاجي '.$data['months_number'].' شهرًا',
                    'assets'
                );
                AssetLog::create([
                    'asset_id' => $asset->id,
                    'total' => $asset->depreciation_price ?? 0,
                    'type' => 'create',
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => __('messages.asset_created'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.create_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function getAssets(Request $request)
    {
        try {
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
                    'logs as depreciated_this_month' => fn (Builder $log) => $log->where('depreciation_period', $period),
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
                'status' => 'success',
                'assets' => $formatted,
                'total_assets_original_prices' => round((float) (clone $query)->sum('price'), 2),
                'total_assets_depreciate_prices' => round((float) (clone $query)->sum('depreciation_price'), 2),
                'accumulated_depreciation' => round((float) ((clone $query)->sum('price') - (clone $query)->sum('depreciation_price')), 2),
                'average_depreciation_rate' => round((float) $assets
                    ->filter(fn (Asset $asset) => (float) $asset->months_number > 0)
                    ->avg(fn (Asset $asset) => 1 / (float) $asset->months_number), 8),
                'average_depreciation_rate_percent' => round((float) $assets
                    ->filter(fn (Asset $asset) => (float) $asset->months_number > 0)
                    ->avg(fn (Asset $asset) => 100 / (float) $asset->months_number), 4),
                'assets_count' => $assets->count(),
                'depreciation_period' => $period,
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function depreciateOneAsset(Request $request, MonthlyAssetDepreciationService $service)
    {
        try {
            $request->validate(['asset_id' => 'required|integer|exists:assets,id']);

            $result = $service->run(now()->format('Y-m'), $request->user()?->id, (int) $request->asset_id);

            return response()->json([
                'status' => $result['processed'] > 0 ? 'success' : 'error',
                'message' => $result['processed'] > 0
                    ? __('messages.asset_depreciated')
                    : 'تم تنفيذ إهلاك هذا الأصل مسبقًا لهذا الشهر أو أن قيمته صفر.',
                'depreciation_period' => now()->format('Y-m'),
                'result' => $result,
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function depreciatAllAssets(Request $request, MonthlyAssetDepreciationService $service)
    {
        try {
            $result = $service->run(now()->format('Y-m'), $request->user()?->id);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.asset_depreciated'),
                'depreciation_period' => now()->format('Y-m'),
                'result' => $result,
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function depreciationPreview(Request $request, AssetDepreciationCalculator $calculator)
    {
        try {
            $period = now()->format('Y-m');
            $assets = Asset::query()
                ->withExists([
                    'logs as depreciated_this_month' => fn ($query) => $query->where('depreciation_period', $period),
                ])
                ->orderBy('name')
                ->get();

            $rows = $assets->map(fn (Asset $asset) => $calculator->calculate($asset, $period));

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

    public function showAsset(Request $request)
    {
        try {

            $request->validate(['asset_id' => 'required|integer|exists:assets,id']);

            $asset = Asset::findOrFail($request->asset_id);

            $formattedMedia = [];
            if ($asset->media && count($asset->media) > 0) {
                foreach ($asset->media as $file) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'tiff', 'webp', 'avif', 'svg+xml'])) {
                        $formattedMedia[] = 'public/'.$this->assetMediaPath.'/images/'.$file;
                    } else {
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
            $asset['acquired_at'] = $asset->acquired_at?->format('Y-m-d');
            $asset['depreciation_rate'] = (float) $asset->months_number > 0
                ? round(1 / (float) $asset->months_number, 8)
                : 0;
            $asset['depreciation_rate_percent'] = (float) $asset->months_number > 0
                ? round(100 / (float) $asset->months_number, 6)
                : 0;
            $asset['logs'] = $asset->logs()
                ->get(['total', 'created_at', 'type', 'depreciation_period']);

            return response()->json([
                'status' => 'success',
                'asset' => $asset,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function editAsset(Request $request)
    {
        try {

            $data = $request->validate([
                'asset_id' => 'required|integer|exists:assets,id',
                'name' => 'required|string|max:255',
                'price' => 'required|numeric|min:1',
                'notes' => 'nullable|string',
                'depreciation_rate' => 'nullable|numeric|min:0',
                'months_number' => 'required|integer|min:1',
                'acquired_at' => 'nullable|date',
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
                            $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/tiff', 'image/webp', 'image/avif', 'image/svg+xml', 'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-ms-wmv', 'video/x-matroska', 'video/webm'];
                            if (! in_array($value->getMimeType(), $allowed)) {
                                $fail("The {$attribute} must be a valid image or video file.");
                            }
                        } else {
                            $fail("The {$attribute} must be either a filename or an uploaded file.");
                        }
                    },
                ],
            ]);

            $data['depreciation_rate'] = round(1 / (int) $data['months_number'], 8);
            $updatedData = Arr::except($data, ['asset_id', 'media']);
            $asset = DB::transaction(function () use ($data, $updatedData) {
                $asset = Asset::query()->lockForUpdate()->findOrFail($data['asset_id']);
                $newPrice = round((float) $updatedData['price'], 4);
                $oldPrice = round((float) $asset->price, 4);
                if (abs($newPrice - $oldPrice) > 0.0001) {
                    throw ValidationException::withMessages([
                        'price' => ['لا يمكن تعديل تكلفة الأصل بعد تسجيله. يجب استخدام عملية تعديل تكلفة أصل مستقلة.'],
                    ]);
                }

                if (array_key_exists('acquired_at', $updatedData)) {
                    $oldAcquiredAt = $asset->acquired_at?->format('Y-m-d');
                    $newAcquiredAt = $updatedData['acquired_at']
                        ? Carbon::parse($updatedData['acquired_at'])->toDateString()
                        : null;
                    $updatedData['acquired_at'] = $newAcquiredAt;
                    if ($newAcquiredAt !== $oldAcquiredAt) {
                        $hasDepreciation = $asset->logs()->where('type', 'depreciate')->exists();
                        $hasAccountingJournal = AccountingJournalEntry::query()
                            ->where('source_type', 'asset')
                            ->where('source_id', $asset->id)
                            ->exists();
                        if ($hasDepreciation || $hasAccountingJournal) {
                            throw ValidationException::withMessages([
                                'acquired_at' => ['لا يمكن تغيير تاريخ اقتناء أصل بدأ استخدامه محاسبيًا. استخدم إجراء تصحيح محاسبي مستقل.'],
                            ]);
                        }
                    }
                }

                $asset->update($updatedData);

                return $asset->fresh();
            }, 3);
            $finalMedia = $this->handleMediaUpdate($request, 'media', $this->assetMediaPath, $asset->media);
            $asset->update(['media' => $finalMedia]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.asset_updated'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
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

    public function deleteAsset(Request $request, ExpenseBoxAccessService $access)
    {
        try {
            $request->validate(['asset_id' => 'required|integer|exists:assets,id',
            ]);

            $files = DB::transaction(function () use ($request, $access) {
                $asset = Asset::query()->lockForUpdate()->findOrFail($request->asset_id);
                if ($asset->logs()->where('type', '!=', 'create')->exists()) {
                    throw ValidationException::withMessages([
                        'asset_id' => ['لا يمكن حذف أصل بدأ إهلاكه. استخدم قيد استبعاد أصل حتى يبقى الأثر المحاسبي محفوظًا.'],
                    ]);
                }
                if (! $asset->box_id) {
                    throw ValidationException::withMessages([
                        'asset_id' => ['لا يمكن حذف أصل افتتاحي بلا مصدر دفع. استخدم قيد استبعاد أصل حتى لا يتغير رصيد الصندوق خطأً.'],
                    ]);
                }
                if (! $access->canUse($request->user(), (int) $asset->box_id)) {
                    throw ValidationException::withMessages([
                        'asset_id' => ['الصندوق الممول للأصل غير مسموح للموظف أو أن جلسته اليومية مغلقة.'],
                    ]);
                }

                $box = Box::query()->lockForUpdate()->findOrFail($asset->box_id);
                $box->update(['total' => (float) $box->total + (float) $asset->price]);
                BoxLogs::createBoxLog($box, 'عكس شراء أصل: '.$asset->name, 'plus', (float) $asset->price, 'حذف أصل قبل بدء إهلاكه');
                $files = is_array($asset->media) ? $asset->media : [];
                $asset->delete();

                return $files;
            });

            if (count($files) > 0) {
                foreach ($files as $file) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $type = '';
                    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'tiff', 'webp', 'avif', 'svg+xml'])) {
                        $type = 'images';
                    } else {
                        $type = 'videos';
                    }

                    $filePath = public_path($this->assetMediaPath.'/'.$type.'/'.$file);
                    if (file_exists($filePath)) {
                        unlink($filePath);

                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.asset_deleted'),
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}

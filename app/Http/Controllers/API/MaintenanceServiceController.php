<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceService;
use App\Models\MaintenanceServiceMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MaintenanceServiceController extends Controller
{
    private const MEDIA_DIR = 'MaintenanceServiceMedia';

    public function index(Request $request)
    {
        $query = $this->baseQuery($request->query('search'));

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $services = $query
            ->latest()
            ->limit((int) $request->query('limit', 100))
            ->get()
            ->map(fn (MaintenanceService $service) => $this->formatService($service))
            ->values();

        return response()->json([
            'status' => 'success',
            'services' => $services,
        ]);
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        $services = $this->baseQuery($data['q'] ?? null)
            ->where('is_active', true)
            ->limit((int) ($data['limit'] ?? 10))
            ->get()
            ->map(fn (MaintenanceService $service) => $this->formatService($service))
            ->values();

        return response()->json([
            'status' => 'success',
            'services' => $services,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $data = $this->validatePayload($request);

            $service = DB::transaction(function () use ($request, $data) {
                $service = MaintenanceService::create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'price' => round((float) $data['price'], 2),
                    'is_active' => $request->boolean('is_active', true),
                ]);

                $this->storeMedia($request, $service);

                return $service->fresh('media');
            });

            return response()->json([
                'status' => 'success',
                'message' => 'تم حفظ خدمة الصيانة بنجاح',
                'service' => $this->formatService($service),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('maintenance_service.store_failed', [
                'exception' => $e,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'تعذر قراءة ملف الوسائط. تأكد من حجم الملف ونوعه ثم حاول مرة أخرى.',
            ], 200);
        }
    }

    public function show(int $service)
    {
        try {
            $service = MaintenanceService::with('media')
                ->findOrFail($service);

            return response()->json([
                'status' => 'success',
                'service' => $this->formatService($service),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'الخدمة غير موجودة',
            ], 200);
        }
    }

    public function update(Request $request, int $service)
    {
        try {
            $data = $this->validatePayload($request);

            $service = DB::transaction(function () use ($request, $service, $data) {
                $service = MaintenanceService::with('media')->findOrFail($service);
                $service->update([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'price' => round((float) $data['price'], 2),
                    'is_active' => $request->boolean('is_active', true),
                ]);

                $keepIds = collect($request->input('keep_media_ids', []))
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->values()
                    ->all();

                $service->media()
                    ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
                    ->get()
                    ->each(fn (MaintenanceServiceMedia $media) => $this->deleteMedia($media));

                $this->storeMedia($request, $service);

                return $service->fresh('media');
            });

            return response()->json([
                'status' => 'success',
                'message' => 'تم تحديث خدمة الصيانة بنجاح',
                'service' => $this->formatService($service),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'الخدمة غير موجودة',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('maintenance_service.update_failed', [
                'service_id' => $service,
                'exception' => $e,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'تعذر قراءة ملف الوسائط. تأكد من حجم الملف ونوعه ثم حاول مرة أخرى.',
            ], 200);
        }
    }

    public function destroy(int $service)
    {
        try {
            DB::transaction(function () use ($service) {
                $service = MaintenanceService::with('media')->findOrFail($service);
                $service->media->each(fn (MaintenanceServiceMedia $media) => $this->deleteMedia($media));
                $service->delete();
            });

            return response()->json([
                'status' => 'success',
                'message' => 'تم حذف خدمة الصيانة بنجاح',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'الخدمة غير موجودة',
            ], 200);
        }
    }

    private function baseQuery(?string $search): Builder
    {
        $query = MaintenanceService::query()
            ->with(['media' => fn ($media) => $media->orderBy('sort_order')->orderBy('id')]);

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return $query->orderBy('name');
    }

    private function validatePayload(Request $request): array
    {
        $this->validateReadableMediaFiles($request);

        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'media' => 'nullable|array',
            'media.*' => 'file|max:512000',
            'keep_media_ids' => 'nullable|array',
            'keep_media_ids.*' => 'integer|exists:maintenance_service_media,id',
        ]);
    }

    private function validateReadableMediaFiles(Request $request): void
    {
        $files = $request->file('media', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        foreach ((array) $files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (! $file->isValid() || ! is_readable($file->getPathname())) {
                throw ValidationException::withMessages([
                    'media' => ['تعذر قراءة ملف الوسائط. قد يكون الملف كبيراً أو لم يكتمل رفعه.'],
                ]);
            }
        }
    }

    private function storeMedia(Request $request, MaintenanceService $service): void
    {
        if (! $request->hasFile('media')) {
            return;
        }

        File::ensureDirectoryExists(public_path(self::MEDIA_DIR));
        $sort = (int) $service->media()->max('sort_order');
        $allowedExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff', 'webp', 'avif', 'svg',
            'heic', 'heif', 'mp4', 'mov', 'qt', 'avi', 'wmv', 'mkv', 'webm',
            'm4v', '3gp', '3g2',
        ];

        foreach ($request->file('media') as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            if (! in_array($extension, $allowedExtensions, true)) {
                throw ValidationException::withMessages([
                    'media' => ['نوع الملف غير مدعوم. الرجاء رفع صورة أو فيديو فقط.'],
                ]);
            }

            // Read the MIME type before moving the upload. The original temporary
            // path no longer exists after move(), so inspecting it afterwards
            // makes otherwise valid image/video uploads fail.
            $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType());
            $videoExtensions = ['mp4', 'mov', 'qt', 'avi', 'wmv', 'mkv', 'webm', 'm4v', '3gp', '3g2'];
            $fileType = str_starts_with($mimeType, 'video/') || in_array($extension, $videoExtensions, true)
                ? 'video'
                : 'image';
            $fileName = Str::uuid().'.'.$extension;
            $file->move(public_path(self::MEDIA_DIR), $fileName);

            $service->media()->create([
                'file_name' => $fileName,
                'file_type' => $fileType,
                'sort_order' => ++$sort,
            ]);
        }
    }

    private function deleteMedia(MaintenanceServiceMedia $media): void
    {
        $path = public_path(self::MEDIA_DIR.DIRECTORY_SEPARATOR.$media->file_name);
        if (File::exists($path)) {
            File::delete($path);
        }
        $media->delete();
    }

    private function formatService(MaintenanceService $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => (string) ($service->description ?? ''),
            'price' => round((float) $service->price, 2),
            'is_active' => (bool) $service->is_active,
            'media' => $service->media
                ->map(fn (MaintenanceServiceMedia $media) => [
                    'id' => $media->id,
                    'file_name' => $media->file_name,
                    'file_type' => $media->file_type,
                    'url' => 'public/'.self::MEDIA_DIR.'/'.$media->file_name,
                ])
                ->values(),
            'created_at' => optional($service->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($service->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}

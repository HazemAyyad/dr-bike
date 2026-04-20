<?php

namespace App\Http\Controllers\API;

use App\Helpers\ThumbnailHelper;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    // ── helpers ────────────────────────────────────────────────────────────────

    /** Store an uploaded image on the 'public' disk and generate a thumbnail. */
    private function storeImage(\Illuminate\Http\UploadedFile $file, string $folder): string
    {
        $path = $file->store($folder, 'public');          // e.g. category-images/xyz.jpg

        try {
            ThumbnailHelper::makeThumbForDiskPath($path);
        } catch (\Throwable) {
            // thumbnail failure must never block the save
        }

        return '/storage/' . $path;
    }

    /** Resolve the imageUrl to return (full URL or empty string). */
    private function resolveImageUrl(?string $raw): string
    {
        if ($raw === null || $raw === '' || $raw === 'no image') {
            return '';
        }
        // If already absolute, return as-is
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }
        return $raw;  // relative paths like /storage/... are fine for the app
    }

    /** Format one category for API response. */
    private function formatCategory(Category $c, int $subCount = 0): array
    {
        return [
            'id'                   => $c->id,
            'nameAr'               => $c->nameAr ?? '',
            'nameEng'              => $c->nameEng ?? '',
            'nameAbree'            => $c->nameAbree ?? '',
            'isShow'               => (bool) $c->isShow,
            'sub_categories_count' => $subCount,
            'imageUrl'             => $this->resolveImageUrl($c->imageUrl ?? null),
        ];
    }

    /** Format one subcategory for API response. */
    private function formatSub(SubCategory $s): array
    {
        return [
            'id'             => $s->id,
            'nameAr'         => $s->nameAr ?? '',
            'nameEng'        => $s->nameEng ?? '',
            'nameAbree'      => $s->nameAbree ?? '',
            'isShow'         => (bool) $s->isShow,
            'mainCategoryId' => $s->mainCategoryId,
            'imageUrl'       => $this->resolveImageUrl($s->imageUrl ?? null),
        ];
    }

    // ── categories ─────────────────────────────────────────────────────────────

    public function getAllCategories()
    {
        try {
            $categories = Category::withCount('subCategories')
                ->select('id', 'nameAr', 'nameEng', 'nameAbree', 'isShow', 'imageUrl')
                ->orderBy('id')
                ->get()
                ->map(fn ($c) => $this->formatCategory($c, $c->sub_categories_count));

            return response()->json(['status' => 'success', 'categories' => $categories]);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    public function storeCategory(Request $request)
    {
        try {
            $request->validate([
                'nameAr'    => 'required|string|max:255',
                'nameEng'   => 'nullable|string|max:255',
                'nameAbree' => 'nullable|string|max:255',
                'image'     => 'nullable|image|max:5120',
            ]);

            $nextId  = (Category::max('id') ?? 0) + 1;
            $imgPath = $request->hasFile('image')
                ? $this->storeImage($request->file('image'), 'category-images')
                : null;

            $category = Category::create([
                'id'        => $nextId,
                'nameAr'    => $request->nameAr,
                'nameEng'   => $request->nameEng ?? '',
                'nameAbree' => $request->nameAbree ?? '',
                'isShow'    => 1,
                'imageUrl'  => $imgPath,
                'userAdd'   => auth()->user()?->name ?? 'admin',
                'dateAdd'   => now(),
            ]);

            return response()->json([
                'status'   => 'success',
                'message'  => 'تم إضافة الفئة بنجاح.',
                'category' => $this->formatCategory($category, 0),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    public function updateCategory(Request $request)
    {
        try {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'nameAr'      => 'required|string|max:255',
                'nameEng'     => 'nullable|string|max:255',
                'nameAbree'   => 'nullable|string|max:255',
                'image'       => 'nullable|image|max:5120',
            ]);

            $category = Category::findOrFail($request->category_id);

            $data = [
                'nameAr'    => $request->nameAr,
                'nameEng'   => $request->nameEng ?? $category->nameEng,
                'nameAbree' => $request->nameAbree ?? $category->nameAbree,
                'userEdit'  => auth()->user()?->name ?? 'admin',
                'dateEdit'  => now(),
            ];

            if ($request->hasFile('image')) {
                // Delete old image if it's a local storage file
                if ($category->imageUrl && str_starts_with($category->imageUrl, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $category->imageUrl);
                    Storage::disk('public')->delete($oldPath);
                    Storage::disk('public')->delete(dirname($oldPath) . '/thumb/' . basename($oldPath));
                }
                $data['imageUrl'] = $this->storeImage($request->file('image'), 'category-images');
            }

            $category->update($data);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث الفئة بنجاح.',
                'category' => $this->formatCategory($category->fresh(), $category->subCategories()->count()),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    public function toggleCategoryStatus(Request $request)
    {
        try {
            $request->validate(['category_id' => 'required|exists:categories,id']);
            $category  = Category::findOrFail($request->category_id);
            $newStatus = !((bool) $category->isShow);
            $category->update(['isShow' => $newStatus ? 1 : 0]);

            return response()->json([
                'status'  => 'success',
                'message' => $newStatus ? 'تم تفعيل الفئة.' : 'تم إخفاء الفئة.',
                'isShow'  => $newStatus,
            ]);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    // ── subcategories ───────────────────────────────────────────────────────────

    public function getSubCategoriesByCategory(Request $request)
    {
        try {
            $request->validate(['category_id' => 'required|exists:categories,id']);

            $subs = SubCategory::where('mainCategoryId', $request->category_id)
                ->select('id', 'nameAr', 'nameEng', 'nameAbree', 'isShow', 'mainCategoryId', 'imageUrl')
                ->orderBy('id')
                ->get()
                ->map(fn ($s) => $this->formatSub($s));

            return response()->json(['status' => 'success', 'sub_categories' => $subs]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    public function storeSubCategory(Request $request)
    {
        try {
            $request->validate([
                'nameAr'         => 'required|string|max:255',
                'nameEng'        => 'nullable|string|max:255',
                'nameAbree'      => 'nullable|string|max:255',
                'mainCategoryId' => 'required|exists:categories,id',
                'image'          => 'nullable|image|max:5120',
            ]);

            $nextId  = (SubCategory::max('id') ?? 0) + 1;
            $imgPath = $request->hasFile('image')
                ? $this->storeImage($request->file('image'), 'sub-category-images')
                : null;

            $sub = SubCategory::create([
                'id'             => $nextId,
                'nameAr'         => $request->nameAr,
                'nameEng'        => $request->nameEng ?? '',
                'nameAbree'      => $request->nameAbree ?? '',
                'isShow'         => 1,
                'mainCategoryId' => $request->mainCategoryId,
                'imageUrl'       => $imgPath,
                'userAdd'        => auth()->user()?->name ?? 'admin',
                'dateAdd'        => now(),
            ]);

            return response()->json([
                'status'       => 'success',
                'message'      => 'تم إضافة الفئة الفرعية بنجاح.',
                'sub_category' => $this->formatSub($sub),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    public function updateSubCategory(Request $request)
    {
        try {
            $request->validate([
                'sub_category_id' => 'required|exists:sub_categories,id',
                'nameAr'          => 'required|string|max:255',
                'nameEng'         => 'nullable|string|max:255',
                'nameAbree'       => 'nullable|string|max:255',
                'image'           => 'nullable|image|max:5120',
            ]);

            $sub  = SubCategory::findOrFail($request->sub_category_id);
            $data = [
                'nameAr'    => $request->nameAr,
                'nameEng'   => $request->nameEng ?? $sub->nameEng,
                'nameAbree' => $request->nameAbree ?? $sub->nameAbree,
                'userEdit'  => auth()->user()?->name ?? 'admin',
                'dateEdit'  => now(),
            ];

            if ($request->hasFile('image')) {
                if ($sub->imageUrl && str_starts_with($sub->imageUrl, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $sub->imageUrl);
                    Storage::disk('public')->delete($oldPath);
                    Storage::disk('public')->delete(dirname($oldPath) . '/thumb/' . basename($oldPath));
                }
                $data['imageUrl'] = $this->storeImage($request->file('image'), 'sub-category-images');
            }

            $sub->update($data);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث الفئة الفرعية بنجاح.',
                'sub_category' => $this->formatSub($sub->fresh()),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    public function toggleSubCategoryStatus(Request $request)
    {
        try {
            $request->validate(['sub_category_id' => 'required|exists:sub_categories,id']);
            $sub       = SubCategory::findOrFail($request->sub_category_id);
            $newStatus = !((bool) $sub->isShow);
            $sub->update(['isShow' => $newStatus ? 1 : 0]);

            return response()->json([
                'status'  => 'success',
                'message' => $newStatus ? 'تم تفعيل الفئة الفرعية.' : 'تم إخفاء الفئة الفرعية.',
                'isShow'  => $newStatus,
            ]);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }
}

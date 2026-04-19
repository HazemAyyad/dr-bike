<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    /**
     * Get all categories with subcategory count and status.
     */
    public function getAllCategories()
    {
        try {
            $categories = Category::withCount('subCategories')
                ->select('id', 'nameAr', 'nameEng', 'nameAbree', 'isShow')
                ->orderBy('id')
                ->get()
                ->map(fn ($c) => [
                    'id'                  => $c->id,
                    'nameAr'              => $c->nameAr,
                    'nameEng'             => $c->nameEng ?? '',
                    'nameAbree'           => $c->nameAbree ?? '',
                    'isShow'              => (bool) $c->isShow,
                    'sub_categories_count'=> $c->sub_categories_count,
                ]);

            return response()->json([
                'status'     => 'success',
                'categories' => $categories,
            ]);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    /**
     * Store a new category.
     */
    public function storeCategory(Request $request)
    {
        try {
            $request->validate([
                'nameAr'    => 'required|string|max:255',
                'nameEng'   => 'nullable|string|max:255',
                'nameAbree' => 'nullable|string|max:255',
            ]);

            $nextId = (Category::max('id') ?? 0) + 1;

            $category = Category::create([
                'id'        => $nextId,
                'nameAr'    => $request->nameAr,
                'nameEng'   => $request->nameEng ?? '',
                'nameAbree' => $request->nameAbree ?? '',
                'isShow'    => 1,
                'userAdd'   => auth()->user()?->name ?? 'admin',
                'dateAdd'   => now(),
            ]);

            return response()->json([
                'status'   => 'success',
                'message'  => 'تم إضافة الفئة بنجاح.',
                'category' => [
                    'id'                   => $category->id,
                    'nameAr'               => $category->nameAr,
                    'nameEng'              => $category->nameEng ?? '',
                    'nameAbree'            => $category->nameAbree ?? '',
                    'isShow'               => (bool) $category->isShow,
                    'sub_categories_count' => 0,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    /**
     * Update an existing category.
     */
    public function updateCategory(Request $request)
    {
        try {
            $request->validate([
                'category_id' => 'required|exists:categories,id',
                'nameAr'      => 'required|string|max:255',
                'nameEng'     => 'nullable|string|max:255',
                'nameAbree'   => 'nullable|string|max:255',
            ]);

            $category = Category::findOrFail($request->category_id);
            $category->update([
                'nameAr'    => $request->nameAr,
                'nameEng'   => $request->nameEng ?? $category->nameEng,
                'nameAbree' => $request->nameAbree ?? $category->nameAbree,
                'userEdit'  => auth()->user()?->name ?? 'admin',
                'dateEdit'  => now(),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث الفئة بنجاح.',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    /**
     * Toggle isShow for a category.
     */
    public function toggleCategoryStatus(Request $request)
    {
        try {
            $request->validate(['category_id' => 'required|exists:categories,id']);

            $category = Category::findOrFail($request->category_id);
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

    /**
     * Get subcategories for a given main category.
     */
    public function getSubCategoriesByCategory(Request $request)
    {
        try {
            $request->validate(['category_id' => 'required|exists:categories,id']);

            $subCategories = SubCategory::where('mainCategoryId', $request->category_id)
                ->select('id', 'nameAr', 'nameEng', 'nameAbree', 'isShow', 'mainCategoryId')
                ->orderBy('id')
                ->get()
                ->map(fn ($s) => [
                    'id'             => $s->id,
                    'nameAr'         => $s->nameAr,
                    'nameEng'        => $s->nameEng ?? '',
                    'nameAbree'      => $s->nameAbree ?? '',
                    'isShow'         => (bool) $s->isShow,
                    'mainCategoryId' => $s->mainCategoryId,
                ]);

            return response()->json([
                'status'         => 'success',
                'sub_categories' => $subCategories,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    /**
     * Store a new subcategory.
     */
    public function storeSubCategory(Request $request)
    {
        try {
            $request->validate([
                'nameAr'         => 'required|string|max:255',
                'nameEng'        => 'nullable|string|max:255',
                'nameAbree'      => 'nullable|string|max:255',
                'mainCategoryId' => 'required|exists:categories,id',
            ]);

            $nextId = (SubCategory::max('id') ?? 0) + 1;

            $sub = SubCategory::create([
                'id'             => $nextId,
                'nameAr'         => $request->nameAr,
                'nameEng'        => $request->nameEng ?? '',
                'nameAbree'      => $request->nameAbree ?? '',
                'isShow'         => 1,
                'mainCategoryId' => $request->mainCategoryId,
                'userAdd'        => auth()->user()?->name ?? 'admin',
                'dateAdd'        => now(),
            ]);

            return response()->json([
                'status'       => 'success',
                'message'      => 'تم إضافة الفئة الفرعية بنجاح.',
                'sub_category' => [
                    'id'             => $sub->id,
                    'nameAr'         => $sub->nameAr,
                    'nameEng'        => $sub->nameEng ?? '',
                    'nameAbree'      => $sub->nameAbree ?? '',
                    'isShow'         => (bool) $sub->isShow,
                    'mainCategoryId' => $sub->mainCategoryId,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    /**
     * Update an existing subcategory.
     */
    public function updateSubCategory(Request $request)
    {
        try {
            $request->validate([
                'sub_category_id' => 'required|exists:sub_categories,id',
                'nameAr'          => 'required|string|max:255',
                'nameEng'         => 'nullable|string|max:255',
                'nameAbree'       => 'nullable|string|max:255',
            ]);

            $sub = SubCategory::findOrFail($request->sub_category_id);
            $sub->update([
                'nameAr'    => $request->nameAr,
                'nameEng'   => $request->nameEng ?? $sub->nameEng,
                'nameAbree' => $request->nameAbree ?? $sub->nameAbree,
                'userEdit'  => auth()->user()?->name ?? 'admin',
                'dateEdit'  => now(),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'تم تحديث الفئة الفرعية بنجاح.',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'بيانات غير صحيحة.', 'errors' => $e->errors()], 422);
        } catch (QueryException $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ في قاعدة البيانات.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'حدث خطأ غير متوقع.'], 200);
        }
    }

    /**
     * Toggle isShow for a subcategory.
     */
    public function toggleSubCategoryStatus(Request $request)
    {
        try {
            $request->validate(['sub_category_id' => 'required|exists:sub_categories,id']);

            $sub = SubCategory::findOrFail($request->sub_category_id);
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

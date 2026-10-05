<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreBanner;
use App\Models\OnlineStore\OnlineStoreCategory;
use App\Models\OnlineStore\OnlineStoreHomeSection;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\BannerService;
use App\Services\OnlineStore\HomeSectionService;
use App\Services\OnlineStore\OnlineStoreCategoryService;
use App\Support\OnlineStore\OnlineStoreValues;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StorefrontContentController extends Controller
{
    private const CATEGORIES_PERMISSION = 'Online Store Categories Manage';

    private const CONTENT_PERMISSION = 'Online Store Content Manage';

    public function categories(Request $request)
    {
        $this->enforce($request, self::CATEGORIES_PERMISSION);

        return response()->json(['data' => OnlineStoreCategory::query()->with('parent')->orderBy('parent_id')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function category(Request $request, OnlineStoreCategory $category)
    {
        $this->enforce($request, self::CATEGORIES_PERMISSION, true);

        return response()->json(['data' => $category->load(['parent', 'memberships'])]);
    }

    public function storeCategory(Request $request, OnlineStoreCategoryService $service)
    {
        $this->enforce($request, self::CATEGORIES_PERMISSION);

        return response()->json(['data' => $service->save(null, $this->categoryData($request), $request->user())], 201);
    }

    public function updateCategory(Request $request, OnlineStoreCategory $category, OnlineStoreCategoryService $service)
    {
        $this->enforce($request, self::CATEGORIES_PERMISSION, true);

        return response()->json(['data' => $service->save($category, $this->categoryData($request, true), $request->user())]);
    }

    public function deleteCategory(Request $request, OnlineStoreCategory $category, OnlineStoreCategoryService $service)
    {
        $this->enforce($request, self::CATEGORIES_PERMISSION, true);
        $disposition = $service->delete($category, $request->user());

        return response()->json(['data' => ['disposition' => $disposition, 'category' => $category->fresh()]]);
    }

    public function replaceCategoryListings(Request $request, OnlineStoreCategory $category, OnlineStoreCategoryService $service)
    {
        $this->enforce($request, self::CATEGORIES_PERMISSION, true);
        $data = $request->validate(['items' => ['required', 'array'], 'items.*.listing_id' => ['required', 'integer'], 'items.*.sort_order' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $service->replaceMemberships($category, $data['items'], $request->user())]);
    }

    public function reorderCategories(Request $request, OnlineStoreCategoryService $service)
    {
        $this->enforce($request, self::CATEGORIES_PERMISSION);
        $data = $request->validate(['parent_id' => ['nullable', 'integer', 'exists:online_store_categories,id'], 'category_ids' => ['required', 'array'], 'category_ids.*' => ['integer']]);
        $service->reorder($data['parent_id'] ?? null, $data['category_ids']);

        return response()->json(['data' => true]);
    }

    public function sections(Request $request)
    {
        $this->enforce($request, self::CONTENT_PERMISSION);

        return response()->json(['data' => OnlineStoreHomeSection::query()->with('items')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function section(Request $request, OnlineStoreHomeSection $section)
    {
        $this->enforce($request, self::CONTENT_PERMISSION, true);

        return response()->json(['data' => $section->load('items')]);
    }

    public function storeSection(Request $request, HomeSectionService $service)
    {
        $this->enforce($request, self::CONTENT_PERMISSION);

        return response()->json(['data' => $service->save(null, $this->sectionData($request), $request->user()->getKey())], 201);
    }

    public function updateSection(Request $request, OnlineStoreHomeSection $section, HomeSectionService $service)
    {
        $this->enforce($request, self::CONTENT_PERMISSION, true);

        return response()->json(['data' => $service->save($section, $this->sectionData($request, true), $request->user()->getKey())]);
    }

    public function deleteSection(Request $request, OnlineStoreHomeSection $section)
    {
        $this->enforce($request, self::CONTENT_PERMISSION, true);
        $section->delete();

        return response()->json(null, 204);
    }

    public function replaceSectionItems(Request $request, OnlineStoreHomeSection $section, HomeSectionService $service)
    {
        $this->enforce($request, self::CONTENT_PERMISSION, true);
        $data = $request->validate(['items' => ['required', 'array'], 'items.*.target_type' => ['required', OnlineStoreValues::targetTypeRule()], 'items.*.target_id' => ['required', 'integer'], 'items.*.sort_order' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $service->replaceItems($section, $data['items'])]);
    }

    public function reorderSections(Request $request, HomeSectionService $service)
    {
        $this->enforce($request, self::CONTENT_PERMISSION);
        $data = $request->validate(['section_ids' => ['required', 'array'], 'section_ids.*' => ['integer']]);
        $service->reorder($data['section_ids']);

        return response()->json(['data' => true]);
    }

    public function banners(Request $request)
    {
        $this->enforce($request, self::CONTENT_PERMISSION);

        return response()->json(['data' => OnlineStoreBanner::query()->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function banner(Request $request, OnlineStoreBanner $banner)
    {
        $this->enforce($request, self::CONTENT_PERMISSION, true);

        return response()->json(['data' => $banner]);
    }

    public function storeBanner(Request $request, BannerService $service)
    {
        $this->enforce($request, self::CONTENT_PERMISSION);

        return response()->json(['data' => $service->save(null, $this->bannerData($request), $request->user()->getKey())], 201);
    }

    public function updateBanner(Request $request, OnlineStoreBanner $banner, BannerService $service)
    {
        $this->enforce($request, self::CONTENT_PERMISSION, true);

        return response()->json(['data' => $service->save($banner, $this->bannerData($request, true), $request->user()->getKey())]);
    }

    public function deleteBanner(Request $request, OnlineStoreBanner $banner)
    {
        $this->enforce($request, self::CONTENT_PERMISSION, true);
        $banner->delete();

        return response()->json(null, 204);
    }

    public function reorderBanners(Request $request, BannerService $service)
    {
        $this->enforce($request, self::CONTENT_PERMISSION);
        $data = $request->validate(['banner_ids' => ['required', 'array'], 'banner_ids.*' => ['integer']]);
        $service->reorder($data['banner_ids']);

        return response()->json(['data' => true]);
    }

    private function categoryData(Request $request, bool $update = false): array
    {
        $sometimes = $update ? 'sometimes' : 'required';

        return $request->validate(['parent_id' => ['sometimes', 'nullable', 'integer', 'exists:online_store_categories,id'], 'name_translations' => [$sometimes, 'array'], 'name_translations.*' => ['nullable', 'string'], 'description_translations' => ['sometimes', 'nullable', 'array'], 'description_translations.*' => ['nullable', 'string'], 'image_path' => ['sometimes', 'nullable', 'string', 'max:2048'], 'is_active' => ['sometimes', 'boolean'], 'show_on_home' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
    }

    private function sectionData(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return $request->validate(['key' => [$required, 'string', 'max:100', Rule::unique('online_store_home_sections', 'key')->ignore($request->route('section'))], 'section_type' => [$required, OnlineStoreValues::sectionTypeRule()], 'title_translations' => ['sometimes', 'nullable', 'array'], 'title_translations.*' => ['nullable', 'string'], 'selection_mode' => [$required, OnlineStoreValues::sectionModeRule()], 'selection_config' => ['sometimes', 'nullable', 'array'], 'is_visible' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
    }

    private function bannerData(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return $request->validate(['image_path' => [$required, 'string', 'max:2048'], 'title_translations' => ['sometimes', 'nullable', 'array'], 'title_translations.*' => ['nullable', 'string'], 'content_translations' => ['sometimes', 'nullable', 'array'], 'content_translations.*' => ['nullable', 'string'], 'is_active' => ['sometimes', 'boolean'], 'starts_at' => ['sometimes', 'nullable', 'date'], 'ends_at' => ['sometimes', 'nullable', 'date'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'action_type' => [$required, Rule::in(OnlineStoreValues::BANNER_ACTION_TYPES)], 'action_target_id' => ['sometimes', 'nullable', 'integer'], 'action_url' => ['sometimes', 'nullable', 'string', 'max:2048']]);
    }

    private function enforce(Request $request, string $permission, bool $resource = false): void
    {
        $response = $resource ? app(OnlineStorePolicy::class)->authorizeResource($request->user(), $permission, true) : app(OnlineStorePolicy::class)->authorize($request->user(), $permission);
        $response->authorize();
    }
}

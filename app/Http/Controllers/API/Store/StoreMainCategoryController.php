<?php

namespace App\Http\Controllers\API\Store;

use App\Models\StoreSection;

class StoreMainCategoryController extends StoreBaseController
{
    public function getAllShowMainCategories()
    {
        $rows = StoreSection::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (StoreSection $section) => [
                'id' => (int) $section->id,
                'nameAr' => (string) $section->name,
                'nameEng' => (string) $section->name,
                'nameAbree' => (string) $section->name,
                'descriptionAr' => (string) ($section->description ?? ''),
                'descriptionEng' => (string) ($section->description ?? ''),
                'descriptionAbree' => (string) ($section->description ?? ''),
                'imageUrl' => '',
                'isShow' => true,
                'userAdd' => '',
                'dateAdd' => $this->dateString($section->created_at),
                'userEdit' => '',
                'dateEdit' => $this->dateString($section->updated_at),
                'supCategories' => [],
            ]);

        return response()->json($this->rowsResponse($rows));
    }
}

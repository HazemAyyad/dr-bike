<?php

namespace Database\Seeders;

use App\Models\OnlineStore\OnlineStoreCategory;
use App\Models\OnlineStore\OnlineStoreListing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OnlineStoreShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        // The existing home seeder is ownership-safe and skips administrator content.
        $this->call(OnlineStoreHomeDemoSeeder::class);

        DB::transaction(function () {
            $categories = OnlineStoreCategory::query()->orderBy('sort_order')->orderBy('id')->get();
            if ($categories->isEmpty()) {
                $categories = collect($this->categories())->map(function (array $data) {
                    return OnlineStoreCategory::query()->create($data);
                });
                $this->assignListings($categories->keyBy('sort_order'));
            }

            $published = OnlineStoreListing::query()
                ->where('status', 'published')
                ->where('readiness_state', 'complete')
                ->orderBy('sort_order')->orderBy('id');
            if (! (clone $published)->where('is_featured', true)->exists()) {
                (clone $published)->limit(10)->get()->each->update(['is_featured' => true]);
            }
            if (! (clone $published)->where('show_on_home', true)->exists()) {
                (clone $published)->limit(12)->get()->each->update(['show_on_home' => true]);
            }
            if (! (clone $published)->where('is_new', true)->exists()) {
                (clone $published)->latest('id')->limit(10)->get()->each->update(['is_new' => true]);
            }
        });

        $this->command?->info('Online Store showcase categories and safe presentation defaults are ready.');
    }

    private function categories(): array
    {
        $paths = OnlineStoreHomeDemoSeeder::BANNER_PATHS;

        return [
            $this->category('دراجات كهربائية', 'Electric bikes', 'אופניים חשמליים', 'دراجات كهربائية للتنقل والعمل والرحلات.', $paths[0], 0),
            $this->category('سكوترات كهربائية', 'Electric scooters', 'קורקינטים חשמליים', 'سكوترات عملية للاستخدام اليومي داخل المدينة.', $paths[0], 1),
            $this->category('بطاريات وشواحن', 'Batteries & chargers', 'סוללות ומטענים', 'بطاريات وشواحن وملحقات الطاقة.', $paths[1], 2),
            $this->category('خوذ وحماية', 'Helmets & protection', 'קסדות ומיגון', 'خوذ ومعدات سلامة للقيادة الآمنة.', $paths[2], 3),
            $this->category('إضاءة وكهرباء', 'Lighting & electrical', 'תאורה וחשמל', 'إضاءة وقطع كهربائية وتحكم.', $paths[2], 4),
            $this->category('إطارات وفرامل', 'Tires & brakes', 'צמיגים ובלמים', 'إطارات وأنظمة فرامل وقطع حركة.', $paths[1], 5),
            $this->category('قطع غيار وإكسسوارات', 'Parts & accessories', 'חלפים ואביזרים', 'قطع غيار وإكسسوارات مختارة للدراجة والسكوتر.', $paths[2], 6),
        ];
    }

    private function category(string $ar, string $en, string $he, string $description, string $image, int $order): array
    {
        return [
            'parent_id' => null,
            'name_translations' => ['ar' => $ar, 'en' => $en, 'he' => $he],
            'description_translations' => ['ar' => $description, 'en' => $en, 'he' => $he],
            'image_path' => $image,
            'is_active' => true,
            'show_on_home' => true,
            'sort_order' => $order,
        ];
    }

    private function assignListings($categoriesByOrder): void
    {
        OnlineStoreListing::query()->with('product')->orderBy('id')->get()->each(function (OnlineStoreListing $listing) use ($categoriesByOrder) {
            $text = mb_strtolower(implode(' ', array_filter([
                $listing->product?->nameAr, $listing->product?->nameEng,
                $listing->product?->model, $listing->product?->descriptionAr,
            ])));
            $order = match (true) {
                $this->contains($text, ['سكوتر', 'scooter']) => 1,
                $this->contains($text, ['بطارية', 'بطاريه', 'شاحن', 'battery', 'charger']) => 2,
                $this->contains($text, ['خوذة', 'خوذه', 'حماية', 'helmet', 'protection']) => 3,
                $this->contains($text, ['ضوء', 'اضاءة', 'إضاءة', 'لمبة', 'كهرباء', 'light', 'controller']) => 4,
                $this->contains($text, ['إطار', 'اطار', 'كوشوك', 'فرامل', 'بريك', 'tire', 'brake']) => 5,
                $this->contains($text, ['دراجة', 'دراجه', 'bike', 'bicycle']) => 0,
                default => 6,
            };
            $category = $categoriesByOrder->get($order);
            if (! $category) {
                return;
            }
            DB::table('online_store_category_listing')->updateOrInsert(
                ['online_store_category_id' => $category->getKey(), 'online_store_listing_id' => $listing->getKey()],
                ['sort_order' => (int) $listing->sort_order, 'created_at' => now(), 'updated_at' => now()],
            );
        });
    }

    private function contains(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}

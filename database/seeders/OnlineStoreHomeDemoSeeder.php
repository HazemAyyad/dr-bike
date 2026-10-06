<?php

namespace Database\Seeders;

use App\Models\OnlineStore\OnlineStoreBanner;
use App\Models\OnlineStore\OnlineStoreHomeSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OnlineStoreHomeDemoSeeder extends Seeder
{
    public const SECTION_KEYS = [
        'hero', 'categories', 'featured', 'recent', 'best_sellers', 'offers', 'maintenance',
    ];

    public const BANNER_PATHS = [
        'images/online-store/demo/electric-mobility-hero.jpg',
        'images/online-store/demo/electric-bike-service.jpg',
        'images/online-store/demo/electric-bike-accessories.jpg',
    ];

    public function run(): void
    {
        if ($this->alreadySeeded()) {
            $this->command?->info('Online Store home demo data already exists; skipped.');

            return;
        }

        DB::transaction(function () {
            foreach ($this->sections() as $section) {
                OnlineStoreHomeSection::query()->updateOrCreate(
                    ['key' => $section['key']],
                    $section,
                );
            }

            foreach ($this->banners() as $banner) {
                OnlineStoreBanner::query()->updateOrCreate(
                    ['image_path' => $banner['image_path']],
                    $banner,
                );
            }
        });

        $this->command?->info('Online Store electric-bike home demo data seeded.');
    }

    public static function exists(): bool
    {
        // Existing content belongs to the administrator. Never overwrite it
        // with demo content, even when only one section or banner exists.
        return OnlineStoreHomeSection::query()->exists()
            || OnlineStoreBanner::query()->exists();
    }

    private function alreadySeeded(): bool
    {
        return self::exists();
    }

    private function sections(): array
    {
        return [
            $this->section('hero', 'hero', 'عالم التنقل الكهربائي', 'Electric mobility', 'dedicated_banners', null, 0),
            $this->section('categories', 'categories', 'تسوّق حسب القسم', 'Shop by category', 'automatic', ['selector' => 'active_categories', 'limit' => 10], 1),
            $this->section('featured', 'custom', 'مختارات دكتور بايك', 'Doctor Bike picks', 'automatic', ['selector' => 'featured', 'limit' => 10], 2),
            $this->section('recent', 'recent', 'وصل حديثاً', 'New arrivals', 'automatic', ['selector' => 'recent', 'limit' => 10], 3),
            $this->section('best_sellers', 'best_sellers', 'الأكثر مبيعاً', 'Best sellers', 'automatic', ['selector' => 'best_sellers', 'limit' => 10], 4),
            $this->section('offers', 'offers', 'عروض مميزة', 'Special offers', 'automatic', ['selector' => 'offers', 'limit' => 10], 5),
            $this->section('maintenance', 'maintenance', 'صيانة الدراجات الكهربائية', 'Electric bike service', 'automatic', ['destination' => 'maintenance.request'], 6),
        ];
    }

    private function section(string $key, string $type, string $ar, string $en, string $mode, ?array $config, int $order): array
    {
        return [
            'key' => $key,
            'section_type' => $type,
            'title_translations' => ['ar' => $ar, 'en' => $en, 'he' => $en],
            'selection_mode' => $mode,
            'selection_config' => $config,
            'is_visible' => true,
            'sort_order' => $order,
        ];
    }

    private function banners(): array
    {
        return [
            [
                'image_path' => self::BANNER_PATHS[0],
                'title_translations' => ['ar' => 'تحرّك بذكاء', 'en' => 'Move smarter', 'he' => 'Move smarter'],
                'content_translations' => ['ar' => 'دراجات وسكوترات كهربائية لحياة المدينة', 'en' => 'Electric bikes and scooters for city life', 'he' => 'Electric bikes and scooters for city life'],
                'is_active' => true, 'starts_at' => null, 'ends_at' => null,
                'sort_order' => 0, 'action_type' => 'none', 'action_target_id' => null, 'action_url' => null,
            ],
            [
                'image_path' => self::BANNER_PATHS[1],
                'title_translations' => ['ar' => 'صيانة كهربائية متخصصة', 'en' => 'Expert electric service', 'he' => 'Expert electric service'],
                'content_translations' => ['ar' => 'فحص البطارية والمحرك وأنظمة التحكم باحتراف', 'en' => 'Professional battery, motor and controller diagnostics', 'he' => 'Professional battery, motor and controller diagnostics'],
                'is_active' => true, 'starts_at' => null, 'ends_at' => null,
                'sort_order' => 1, 'action_type' => 'none', 'action_target_id' => null, 'action_url' => null,
            ],
            [
                'image_path' => self::BANNER_PATHS[2],
                'title_translations' => ['ar' => 'جهّز رحلتك', 'en' => 'Gear up for every ride', 'he' => 'Gear up for every ride'],
                'content_translations' => ['ar' => 'خوذ وأقفال وإضاءة وشواحن مختارة بعناية', 'en' => 'Helmets, locks, lights and chargers selected for you', 'he' => 'Helmets, locks, lights and chargers selected for you'],
                'is_active' => true, 'starts_at' => null, 'ends_at' => null,
                'sort_order' => 2, 'action_type' => 'none', 'action_target_id' => null, 'action_url' => null,
            ],
        ];
    }
}

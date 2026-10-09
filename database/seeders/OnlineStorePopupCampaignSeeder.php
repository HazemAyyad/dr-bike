<?php

namespace Database\Seeders;

use App\Models\OnlineStore\OnlineStorePopupCampaign;
use Illuminate\Database\Seeder;

class OnlineStorePopupCampaignSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->campaigns() as $campaign) {
            OnlineStorePopupCampaign::query()->updateOrCreate(
                ['name' => $campaign['name']],
                $campaign,
            );
        }

        $this->command?->info('Online Store popup campaign designs are ready.');
    }

    private function campaigns(): array
    {
        return [
            [
                'name' => 'ترحيب المتجر الكهربائي',
                'image_path' => 'images/online-store/demo/electric-mobility-hero.jpg',
                'title_translations' => ['ar' => 'أهلاً بك في متجر دكتور بايك', 'en' => 'Welcome to Doctor Bike', 'he' => 'ברוכים הבאים לדוקטור בייק'],
                'content_translations' => ['ar' => 'كل ما تحتاجه لدراجتك وسكوترك الكهربائي في مكان واحد.', 'en' => 'Everything for your electric bike and scooter in one place.', 'he' => 'כל מה שצריך לאופניים ולקורקינט החשמלי במקום אחד.'],
                'button_translations' => ['ar' => 'ابدأ التسوق', 'en' => 'Start shopping', 'he' => 'התחילו לקנות'],
                'theme' => 'brand', 'audience_type' => 'all', 'audience_days' => null,
                'display_frequency' => 'once', 'action_type' => 'none', 'action_target_id' => null,
                'action_url' => null, 'is_active' => true, 'starts_at' => null, 'ends_at' => null, 'priority' => 10,
            ],
            [
                'name' => 'ترحيب بالعملاء الجدد',
                'image_path' => 'images/online-store/demo/electric-bike-accessories.jpg',
                'title_translations' => ['ar' => 'بداية موفقة معنا', 'en' => 'A great start with us', 'he' => 'התחלה מצוינת איתנו'],
                'content_translations' => ['ar' => 'اكتشف المنتجات والعروض المختارة خصيصاً لعملائنا الجدد.', 'en' => 'Discover products and offers selected for new customers.', 'he' => 'גלו מוצרים ומבצעים שנבחרו ללקוחות חדשים.'],
                'button_translations' => ['ar' => 'اكتشف الآن', 'en' => 'Explore now', 'he' => 'גלו עכשיו'],
                'theme' => 'success', 'audience_type' => 'new_users', 'audience_days' => 14,
                'display_frequency' => 'once', 'action_type' => 'none', 'action_target_id' => null,
                'action_url' => null, 'is_active' => false, 'starts_at' => null, 'ends_at' => null, 'priority' => 20,
            ],
            [
                'name' => 'تشجيع أول طلب',
                'image_path' => 'images/online-store/demo/electric-bike-service.jpg',
                'title_translations' => ['ar' => 'طلبك الأول يبدأ من هنا', 'en' => 'Your first order starts here', 'he' => 'ההזמנה הראשונה מתחילה כאן'],
                'content_translations' => ['ar' => 'تصفح تشكيلتنا واختر ما يناسب رحلتك القادمة.', 'en' => 'Browse our collection and choose what suits your next ride.', 'he' => 'עיינו במבחר ובחרו את מה שמתאים לנסיעה הבאה.'],
                'button_translations' => ['ar' => 'تصفح المنتجات', 'en' => 'Browse products', 'he' => 'עיינו במוצרים'],
                'theme' => 'warm', 'audience_type' => 'no_orders', 'audience_days' => null,
                'display_frequency' => 'once', 'action_type' => 'none', 'action_target_id' => null,
                'action_url' => null, 'is_active' => false, 'starts_at' => null, 'ends_at' => null, 'priority' => 30,
            ],
        ];
    }
}

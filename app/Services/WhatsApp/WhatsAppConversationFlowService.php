<?php

namespace App\Services\WhatsApp;

use App\Models\Category;
use App\Models\EmployeeDetail;
use App\Models\MetaCatalogProductSync;
use App\Models\Product;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WhatsAppConversationFlowService
{
    private const CATEGORY_PAGE_SIZE = 7;

    public function handle(
        WhatsAppCloudApiService $api,
        WhatsAppMessage $message,
        array $incoming
    ): bool {
        $conversation = $message->conversation;
        if (! $conversation) {
            return false;
        }

        $replyId = $this->replyId($incoming);
        $text = trim((string) data_get($incoming, 'text.body', ''));
        $command = mb_strtolower($text);

        if ($replyId === 'flow:home' || in_array($command, ['القائمة', 'القائمة الرئيسية', 'الرئيسية'], true)) {
            $this->reset($conversation);
            $api->sendWelcomeMenu($conversation->phone);

            return true;
        }

        if (in_array($command, ['إلغاء', 'الغاء', 'إلغاء الطلب', 'الغاء الطلب'], true)) {
            $this->reset($conversation);
            $api->sendText($conversation->phone, 'تم إلغاء الخطوات الحالية. يمكنك اختيار خدمة جديدة من القائمة.', null, null, true);
            $api->sendWelcomeMenu($conversation->phone);

            return true;
        }

        if (in_array($replyId, ['products', 'maintenance', 'sell', 'inquiries', 'employee'], true)) {
            return $this->startFromMainMenu($api, $conversation, $replyId);
        }

        return match ($conversation->automation_flow) {
            'products' => $this->continueProducts($api, $conversation, $message, $incoming, $replyId),
            'maintenance' => $this->continueMaintenance($api, $conversation, $message, $incoming, $replyId),
            'sell' => $this->continueSell($api, $conversation, $message, $incoming, $replyId),
            'inquiries' => $this->continueInquiry($api, $conversation, $message, $incoming, $replyId),
            default => false,
        };
    }

    private function startFromMainMenu(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        string $selection
    ): bool {
        return match ($selection) {
            'products' => $this->startProducts($api, $conversation),
            'maintenance' => $this->startMaintenance($api, $conversation),
            'sell' => $this->startSell($api, $conversation),
            'inquiries' => $this->startInquiry($api, $conversation),
            'employee' => $this->handoff($api, $conversation, 'تواصل مع موظف'),
        };
    }

    private function startProducts(WhatsAppCloudApiService $api, WhatsAppConversation $conversation): bool
    {
        $this->start($conversation, 'products', 'category');
        $this->addTag($conversation, 'طلب منتجات', '#0ea5e9');
        $this->sendProductCategories($api, $conversation, 1);

        return true;
    }

    private function continueProducts(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        array $incoming,
        ?string $replyId
    ): bool {
        if ($conversation->automation_step === 'category') {
            if (preg_match('/^flow:products:page:(\d+)$/', (string) $replyId, $matches)) {
                $this->sendProductCategories($api, $conversation, max((int) $matches[1], 1));

                return true;
            }

            if (! preg_match('/^flow:products:category:(\d+)$/', (string) $replyId, $matches)) {
                $api->sendText($conversation->phone, 'اختر أحد تصنيفات المنتجات من القائمة، أو اكتب «القائمة الرئيسية» للرجوع.', null, null, true);

                return true;
            }

            $category = $this->availableCategories($conversation)
                ->firstWhere('id', (int) $matches[1]);
            if (! $category) {
                $api->sendText($conversation->phone, 'هذا التصنيف غير متاح حاليًا. اختر تصنيفًا آخر.', null, null, true);
                $this->sendProductCategories($api, $conversation, 1);

                return true;
            }

            $retailerIds = $this->catalogProductRetailerIds($conversation, (int) $category->id);
            if ($retailerIds->isEmpty()) {
                $api->sendText($conversation->phone, 'لا توجد منتجات متاحة في هذا التصنيف حاليًا. اختر تصنيفًا آخر.', null, null, true);
                $this->sendProductCategories($api, $conversation, 1);

                return true;
            }

            $this->advance($conversation, 'awaiting_order', [
                'category_id' => (int) $category->id,
                'category_name' => (string) $category->nameAr,
            ]);
            $api->sendCatalogProductRetailerIds($conversation->phone, $retailerIds->all());
            $api->sendText(
                $conversation->phone,
                'أضف المنتجات والكميات إلى السلة ثم أرسل الطلب. للرجوع اكتب «القائمة الرئيسية».',
                null,
                null,
                true
            );

            return true;
        }

        if ($conversation->automation_step === 'awaiting_order') {
            if ((string) data_get($incoming, 'type') !== 'order') {
                $api->sendText($conversation->phone, 'أرسل سلة المنتجات من الكتالوج، أو اكتب «القائمة الرئيسية» للبدء من جديد.', null, null, true);

                return true;
            }

            $data = $this->data($conversation);
            $data['order_summary'] = $message->body;
            $this->finish($conversation, $data);
            $api->sendText(
                $conversation->phone,
                "تم استلام طلب المنتجات ✅\nسيراجع الموظف التوفر والسعر والتوصيل معك قريبًا.",
                null,
                null,
                true
            );

            return true;
        }

        return false;
    }

    private function startMaintenance(WhatsAppCloudApiService $api, WhatsAppConversation $conversation): bool
    {
        $this->start($conversation, 'maintenance', 'item_type');
        $this->addTag($conversation, 'صيانة', '#f59e0b');
        $this->sendItemTypes($api, $conversation, 'maintenance', 'ما الشيء الذي يحتاج إلى صيانة؟');

        return true;
    }

    private function continueMaintenance(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        array $incoming,
        ?string $replyId
    ): bool {
        $step = $conversation->automation_step;
        if ($step === 'item_type') {
            $type = $this->selectedItemType($replyId, 'maintenance');
            if (! $type) {
                $this->sendItemTypes($api, $conversation, 'maintenance', 'اختر نوع الشيء الذي يحتاج إلى صيانة.');

                return true;
            }
            $this->advance($conversation, 'issue', ['item_type' => $type]);
            $api->sendText($conversation->phone, 'اكتب وصف المشكلة أو العطل بالتفصيل.', null, null, true);

            return true;
        }

        if ($step === 'issue') {
            if (! $this->hasUsefulAnswer($incoming)) {
                $api->sendText($conversation->phone, 'أرسل وصف المشكلة برسالة نصية.', null, null, true);

                return true;
            }
            $this->advance($conversation, 'model', ['issue' => $this->answer($message, $incoming)]);
            $api->sendText($conversation->phone, 'اكتب الماركة والموديل إن كانا معروفين، أو اكتب «تخطي».', null, null, true);

            return true;
        }

        if ($step === 'model') {
            if (! $this->hasUsefulAnswer($incoming)) {
                $api->sendText($conversation->phone, 'اكتب الماركة والموديل، أو اكتب «تخطي».', null, null, true);

                return true;
            }
            $value = $this->answer($message, $incoming);
            $this->advance($conversation, 'media', ['model' => $this->isSkip($value) ? null : $value]);
            $this->askForMedia($api, $conversation, 'maintenance');

            return true;
        }

        if ($step === 'media') {
            if ($replyId === 'flow:maintenance:media_done' || $replyId === 'flow:maintenance:media_skip') {
                $this->advance($conversation, 'delivery');
                $this->sendMaintenanceDeliveryOptions($api, $conversation);

                return true;
            }
            if ($this->isMedia($incoming)) {
                $this->appendMedia($conversation, $message, $incoming);
                $this->askForMedia($api, $conversation, 'maintenance', true);

                return true;
            }
            $this->askForMedia($api, $conversation, 'maintenance');

            return true;
        }

        if ($step === 'delivery') {
            if ($replyId === 'flow:maintenance:delivery:shop') {
                return $this->completeMaintenance($api, $conversation, 'إحضاره إلى الفرع');
            }
            if ($replyId === 'flow:maintenance:delivery:pickup') {
                $this->advance($conversation, 'location', ['delivery' => 'طلب استلام من الموقع']);
                $api->sendText($conversation->phone, 'أرسل موقع الاستلام من واتساب، أو اكتب اسم المنطقة والعنوان.', null, null, true);

                return true;
            }
            $this->sendMaintenanceDeliveryOptions($api, $conversation);

            return true;
        }

        if ($step === 'location') {
            if (! $this->isLocationOrText($incoming)) {
                $api->sendText($conversation->phone, 'أرسل موقع الاستلام، أو اكتب اسم المنطقة والعنوان.', null, null, true);

                return true;
            }

            return $this->completeMaintenance($api, $conversation, null, $this->answer($message, $incoming));
        }

        return false;
    }

    private function completeMaintenance(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        ?string $delivery = null,
        ?string $location = null
    ): bool {
        $data = $this->data($conversation);
        if ($delivery) {
            $data['delivery'] = $delivery;
        }
        if ($location) {
            $data['location'] = $location;
        }
        $this->finish($conversation, $data);

        $summary = "تم استلام طلب الصيانة ✅\n"
            .'النوع: '.($data['item_type'] ?? 'غير محدد')."\n"
            .'المشكلة: '.($data['issue'] ?? 'غير محددة')."\n"
            .(filled($data['model'] ?? null) ? 'الماركة/الموديل: '.$data['model']."\n" : '')
            .'المرفقات: '.count($data['media'] ?? [])."\n"
            .'طريقة التسليم: '.($data['delivery'] ?? 'غير محددة')."\n"
            .(filled($data['location'] ?? null) ? 'الموقع: '.$data['location']."\n" : '')
            .'سيتواصل معك الموظف لتأكيد الفحص والتكلفة.';
        $api->sendText($conversation->phone, $summary, null, null, true);

        return true;
    }

    private function startSell(WhatsAppCloudApiService $api, WhatsAppConversation $conversation): bool
    {
        $this->start($conversation, 'sell', 'item_type');
        $this->addTag($conversation, 'بيع', '#10b981');
        $this->sendItemTypes($api, $conversation, 'sell', 'ما الشيء الذي تريد بيعه لد. بايك؟');

        return true;
    }

    private function continueSell(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        array $incoming,
        ?string $replyId
    ): bool {
        $step = $conversation->automation_step;
        if ($step === 'item_type') {
            $type = $this->selectedItemType($replyId, 'sell');
            if (! $type) {
                $this->sendItemTypes($api, $conversation, 'sell', 'اختر نوع الشيء الذي تريد بيعه.');

                return true;
            }
            $this->advance($conversation, 'description', ['item_type' => $type]);
            $api->sendText($conversation->phone, 'اكتب الاسم أو الموديل، حالته، وأي تفاصيل مهمة.', null, null, true);

            return true;
        }

        if ($step === 'description') {
            if (! $this->hasUsefulAnswer($incoming)) {
                $api->sendText($conversation->phone, 'أرسل وصف الغرض وحالته برسالة نصية.', null, null, true);

                return true;
            }
            $this->advance($conversation, 'media', ['description' => $this->answer($message, $incoming)]);
            $this->askForMedia($api, $conversation, 'sell');

            return true;
        }

        if ($step === 'media') {
            if ($replyId === 'flow:sell:media_done' || $replyId === 'flow:sell:media_skip') {
                $this->advance($conversation, 'price');
                $api->sendText($conversation->phone, 'اكتب السعر المطلوب، أو اكتب «تقييم» إذا أردت منا تقييمه.', null, null, true);

                return true;
            }
            if ($this->isMedia($incoming)) {
                $this->appendMedia($conversation, $message, $incoming);
                $this->askForMedia($api, $conversation, 'sell', true);

                return true;
            }
            $this->askForMedia($api, $conversation, 'sell');

            return true;
        }

        if ($step === 'price') {
            if (! $this->hasUsefulAnswer($incoming)) {
                $api->sendText($conversation->phone, 'اكتب السعر المطلوب، أو اكتب «تقييم».', null, null, true);

                return true;
            }
            $this->advance($conversation, 'location', ['price' => $this->answer($message, $incoming)]);
            $api->sendText($conversation->phone, 'أرسل موقعك من واتساب، أو اكتب اسم المنطقة والعنوان.', null, null, true);

            return true;
        }

        if ($step === 'location') {
            if (! $this->isLocationOrText($incoming)) {
                $api->sendText($conversation->phone, 'أرسل موقعك، أو اكتب اسم المنطقة والعنوان.', null, null, true);

                return true;
            }
            $data = array_merge($this->data($conversation), ['location' => $this->answer($message, $incoming)]);
            $this->finish($conversation, $data);
            $summary = "تم استلام عرض البيع ✅\n"
                .'النوع: '.($data['item_type'] ?? 'غير محدد')."\n"
                .'الوصف: '.($data['description'] ?? 'غير محدد')."\n"
                .'المرفقات: '.count($data['media'] ?? [])."\n"
                .'السعر المطلوب: '.($data['price'] ?? 'طلب تقييم')."\n"
                .'الموقع: '.($data['location'] ?? 'غير محدد')."\n"
                .'سيراجع الموظف التفاصيل ويتواصل معك.';
            $api->sendText($conversation->phone, $summary, null, null, true);

            return true;
        }

        return false;
    }

    private function startInquiry(WhatsAppCloudApiService $api, WhatsAppConversation $conversation): bool
    {
        $this->start($conversation, 'inquiries', 'topic');
        $this->addTag($conversation, 'استفسار', '#8b5cf6');
        $api->sendInteractiveList($conversation->phone, 'نوع الاستفسار', 'اختر موضوع استفسارك.', 'اختر الموضوع', [[
            'title' => 'الاستفسارات',
            'rows' => [
                ['id' => 'flow:inquiries:topic:price', 'title' => 'سعر منتج', 'description' => 'السؤال عن سعر منتج'],
                ['id' => 'flow:inquiries:topic:availability', 'title' => 'توفر منتج', 'description' => 'السؤال عن توفر منتج'],
                ['id' => 'flow:inquiries:topic:order', 'title' => 'متابعة طلب', 'description' => 'الاستفسار عن طلب سابق'],
                ['id' => 'flow:inquiries:topic:maintenance', 'title' => 'متابعة صيانة', 'description' => 'الاستفسار عن طلب صيانة'],
                ['id' => 'flow:inquiries:topic:other', 'title' => 'استفسار آخر', 'description' => 'أي سؤال آخر'],
                $this->homeRow(),
            ],
        ]], 'يمكنك كتابة «إلغاء» في أي وقت');

        return true;
    }

    private function continueInquiry(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        WhatsAppMessage $message,
        array $incoming,
        ?string $replyId
    ): bool {
        if ($conversation->automation_step === 'topic') {
            $topics = [
                'price' => 'سعر منتج',
                'availability' => 'توفر منتج',
                'order' => 'متابعة طلب',
                'maintenance' => 'متابعة صيانة',
                'other' => 'استفسار آخر',
            ];
            if (! preg_match('/^flow:inquiries:topic:([a-z_]+)$/', (string) $replyId, $matches) || ! isset($topics[$matches[1]])) {
                $api->sendText($conversation->phone, 'اختر نوع الاستفسار من القائمة.', null, null, true);

                return true;
            }
            $topic = $topics[$matches[1]];
            $this->advance($conversation, 'details', ['topic' => $topic]);
            $prompt = in_array($matches[1], ['order', 'maintenance'], true)
                ? 'أرسل رقم الطلب إن وجد، واكتب تفاصيل المتابعة المطلوبة.'
                : 'اكتب استفسارك بالتفصيل، ويمكنك إرفاق صورة للمنتج.';
            $api->sendText($conversation->phone, $prompt, null, null, true);

            return true;
        }

        if ($conversation->automation_step === 'details') {
            $data = array_merge($this->data($conversation), ['details' => $this->answer($message, $incoming)]);
            $this->finish($conversation, $data);
            $api->sendText(
                $conversation->phone,
                "تم استلام استفسارك ✅\nالموضوع: ".($data['topic'] ?? 'استفسار عام')."\nسيرد عليك الموظف قريبًا.",
                null,
                null,
                true
            );

            return true;
        }

        return false;
    }

    private function handoff(WhatsAppCloudApiService $api, WhatsAppConversation $conversation, string $tag): bool
    {
        $this->addTag($conversation, $tag, '#ef4444');
        $employee = $this->leastLoadedWhatsAppEmployee();
        $this->finish($conversation, [
            'handoff' => true,
            'assigned_employee_id' => $employee?->id,
            'assigned_user_id' => $employee?->user_id,
        ]);
        if ($employee) {
            $conversation->update(['assigned_admin_id' => $employee->user_id]);
            $conversation->refresh();
        }
        $api->sendText(
            $conversation->phone,
            'تم تحويل محادثتك إلى فريق خدمة الزبائن، وسيقوم أحد الموظفين بالرد عليك قريبًا.',
            null,
            null,
            true
        );

        return true;
    }

    private function leastLoadedWhatsAppEmployee(): ?EmployeeDetail
    {
        if (! Schema::hasTable('employee_details')
            || ! Schema::hasTable('employee_permissions')
            || ! Schema::hasTable('permissions')) {
            return null;
        }

        return EmployeeDetail::query()
            ->whereNotNull('user_id')
            ->where(function ($query) {
                $query->whereNull('is_suspended')->orWhere('is_suspended', false);
            })
            ->whereHas('user')
            ->whereHas('permissions.permission', fn ($query) => $query
                ->where('name_en', WhatsAppIncomingNotificationService::PERMISSION))
            ->select('employee_details.*')
            ->selectSub(
                WhatsAppConversation::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('assigned_admin_id', 'employee_details.user_id')
                    ->whereIn('status', ['open', 'pending']),
                'open_whatsapp_conversations_count'
            )
            ->orderBy('open_whatsapp_conversations_count')
            ->orderBy('id')
            ->first();
    }

    private function sendProductCategories(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        int $page
    ): void {
        $categories = $this->availableCategories($conversation);
        if ($categories->isEmpty()) {
            $this->finish($conversation, ['catalog_unavailable' => true]);
            $api->sendText($conversation->phone, 'الكتالوج غير متاح حاليًا. تم تحويل طلبك لموظف لمساعدتك بالمنتجات.', null, null, true);

            return;
        }

        $lastPage = max((int) ceil($categories->count() / self::CATEGORY_PAGE_SIZE), 1);
        $page = min(max($page, 1), $lastPage);
        $rows = $categories
            ->forPage($page, self::CATEGORY_PAGE_SIZE)
            ->map(fn (Category $category) => [
                'id' => 'flow:products:category:'.$category->id,
                'title' => mb_substr((string) $category->nameAr, 0, 24),
                'description' => 'عرض منتجات هذا التصنيف',
            ])
            ->values();

        if ($page > 1) {
            $rows->push([
                'id' => 'flow:products:page:'.($page - 1),
                'title' => 'التصنيفات السابقة',
                'description' => 'العودة إلى الصفحة السابقة',
            ]);
        }
        if ($page < $lastPage) {
            $rows->push([
                'id' => 'flow:products:page:'.($page + 1),
                'title' => 'المزيد من التصنيفات',
                'description' => 'عرض الصفحة التالية',
            ]);
        }
        $rows->push($this->homeRow());

        $api->sendInteractiveList(
            $conversation->phone,
            'تصنيفات المنتجات',
            'اختر التصنيف الذي تريد استعراض منتجاته.',
            'اختر التصنيف',
            [['title' => 'التصنيفات', 'rows' => $rows->all()]],
            'الصفحة '.$page.' من '.$lastPage
        );
    }

    private function availableCategories(WhatsAppConversation $conversation): Collection
    {
        $productIds = MetaCatalogProductSync::query()
            ->select('product_id')
            ->where('sync_status', 'synced')
            ->whereNotNull('meta_catalog_retailer_id');

        if ($conversation->whatsapp_account_id) {
            $productIds->where('whatsapp_account_id', $conversation->whatsapp_account_id);
        }

        $categoryIds = Product::query()
            ->select('category_id')
            ->whereIn('id', $productIds)
            ->whereNotNull('category_id')
            ->where('isShow', true);

        return Category::query()
            ->whereIn('id', $categoryIds)
            ->where('isShow', true)
            ->orderBy('sortOrder')
            ->orderBy('nameAr')
            ->get(['id', 'nameAr']);
    }

    private function catalogProductRetailerIds(WhatsAppConversation $conversation, int $categoryId): Collection
    {
        return MetaCatalogProductSync::query()
            ->where('sync_status', 'synced')
            ->whereNotNull('meta_catalog_retailer_id')
            ->when(
                $conversation->whatsapp_account_id,
                fn ($query, $accountId) => $query->where('whatsapp_account_id', $accountId)
            )
            ->whereHas('product', fn ($products) => $products
                ->where('category_id', $categoryId)
                ->where('isShow', true))
            ->orderBy('product_id')
            ->orderByRaw('variant_id is null desc')
            ->limit(30)
            ->pluck('meta_catalog_retailer_id')
            ->unique()
            ->values();
    }

    private function sendItemTypes(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        string $flow,
        string $body
    ): void {
        $api->sendInteractiveList($conversation->phone, $flow === 'sell' ? 'عرض للبيع' : 'طلب صيانة', $body, 'اختر النوع', [[
            'title' => 'الأنواع',
            'rows' => [
                ['id' => "flow:$flow:type:bicycle", 'title' => 'دراجة', 'description' => 'دراجة كاملة'],
                ['id' => "flow:$flow:type:part", 'title' => 'قطعة', 'description' => 'قطعة أو إكسسوار'],
                ['id' => "flow:$flow:type:battery", 'title' => 'بطارية', 'description' => 'بطارية دراجة أو جهاز'],
                ['id' => "flow:$flow:type:computer", 'title' => 'كمبيوتر أو شاشة', 'description' => 'كمبيوتر، شاشة أو وحدة تحكم'],
                ['id' => "flow:$flow:type:board", 'title' => 'لوحة إلكترونية', 'description' => 'لوحة أو دائرة إلكترونية'],
                ['id' => "flow:$flow:type:other", 'title' => 'شيء آخر', 'description' => 'نوع غير موجود في القائمة'],
                $this->homeRow(),
            ],
        ]], 'يمكنك كتابة «إلغاء» في أي وقت');
    }

    private function selectedItemType(?string $replyId, string $flow): ?string
    {
        $types = [
            'bicycle' => 'دراجة',
            'part' => 'قطعة',
            'battery' => 'بطارية',
            'computer' => 'كمبيوتر أو شاشة',
            'board' => 'لوحة إلكترونية',
            'other' => 'شيء آخر',
        ];
        if (! preg_match('/^flow:'.preg_quote($flow, '/').':type:([a-z_]+)$/', (string) $replyId, $matches)) {
            return null;
        }

        return $types[$matches[1]] ?? null;
    }

    private function askForMedia(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation,
        string $flow,
        bool $received = false
    ): void {
        $api->sendReplyButtons(
            $conversation->phone,
            $received
                ? 'تم استلام الملف. أرسل المزيد أو اضغط «تم الإرسال» للمتابعة.'
                : 'أرسل صورًا أو فيديو توضح الحالة، ثم اضغط «تم الإرسال».',
            [
                ['id' => "flow:$flow:media_done", 'title' => 'تم الإرسال'],
                ['id' => "flow:$flow:media_skip", 'title' => 'تخطي الصور'],
                ['id' => 'flow:home', 'title' => 'القائمة الرئيسية'],
            ]
        );
    }

    private function sendMaintenanceDeliveryOptions(
        WhatsAppCloudApiService $api,
        WhatsAppConversation $conversation
    ): void {
        $api->sendInteractiveList($conversation->phone, 'طريقة التسليم', 'كيف تريد تسليم الغرض للصيانة؟', 'اختر الطريقة', [[
            'title' => 'التسليم',
            'rows' => [
                ['id' => 'flow:maintenance:delivery:shop', 'title' => 'إحضاره إلى الفرع', 'description' => 'سأحضر الغرض بنفسي'],
                ['id' => 'flow:maintenance:delivery:pickup', 'title' => 'طلب استلام', 'description' => 'أريد استلامه من موقعي'],
                $this->homeRow(),
            ],
        ]]);
    }

    private function start(WhatsAppConversation $conversation, string $flow, string $step): void
    {
        $conversation->update([
            'status' => 'open',
            'automation_flow' => $flow,
            'automation_step' => $step,
            'automation_data' => [],
            'automation_started_at' => now(),
            'automation_completed_at' => null,
        ]);
    }

    private function advance(WhatsAppConversation $conversation, string $step, array $values = []): void
    {
        $conversation->update([
            'automation_step' => $step,
            'automation_data' => array_merge($this->data($conversation), $values),
        ]);
        $conversation->refresh();
    }

    private function appendMedia(WhatsAppConversation $conversation, WhatsAppMessage $message, array $incoming): void
    {
        $data = $this->data($conversation);
        $data['media'] = array_values(array_merge($data['media'] ?? [], [[
            'message_id' => $message->id,
            'type' => (string) data_get($incoming, 'type'),
        ]]));
        $conversation->update(['automation_data' => $data]);
        $conversation->refresh();
    }

    private function finish(WhatsAppConversation $conversation, array $data): void
    {
        $conversation->update([
            'status' => 'pending',
            'automation_flow' => null,
            'automation_step' => null,
            'automation_data' => $data,
            'automation_completed_at' => now(),
        ]);
        $conversation->refresh();
    }

    private function reset(WhatsAppConversation $conversation): void
    {
        $conversation->update([
            'status' => 'open',
            'automation_flow' => null,
            'automation_step' => null,
            'automation_data' => null,
            'automation_started_at' => null,
            'automation_completed_at' => null,
        ]);
        $conversation->refresh();
    }

    private function addTag(WhatsAppConversation $conversation, string $name, string $color): void
    {
        if (! Schema::hasTable('conversation_tags') || ! Schema::hasTable('conversation_taggables')) {
            return;
        }

        DB::table('conversation_tags')->insertOrIgnore([
            'name' => $name,
            'color' => $color,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tagId = DB::table('conversation_tags')->where('name', $name)->value('id');
        if ($tagId) {
            DB::table('conversation_taggables')->insertOrIgnore([
                'tag_id' => $tagId,
                'channel' => 'whatsapp',
                'conversation_id' => $conversation->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function replyId(array $incoming): ?string
    {
        return data_get($incoming, 'interactive.button_reply.id')
            ?: data_get($incoming, 'interactive.list_reply.id');
    }

    private function answer(WhatsAppMessage $message, array $incoming): string
    {
        if ((string) data_get($incoming, 'type') === 'location') {
            $latitude = data_get($incoming, 'location.latitude');
            $longitude = data_get($incoming, 'location.longitude');
            $name = trim((string) data_get($incoming, 'location.name'));
            $address = trim((string) data_get($incoming, 'location.address'));

            return trim(collect([$name, $address, trim($latitude.' '.$longitude)])->filter()->join(' - '));
        }

        return trim((string) ($message->body ?: data_get($incoming, 'text.body', '')));
    }

    private function hasUsefulAnswer(array $incoming): bool
    {
        return filled(data_get($incoming, 'text.body'));
    }

    private function isLocationOrText(array $incoming): bool
    {
        return in_array((string) data_get($incoming, 'type'), ['location', 'text'], true)
            && filled($this->incomingValue($incoming));
    }

    private function incomingValue(array $incoming): string
    {
        if ((string) data_get($incoming, 'type') === 'location') {
            return trim((string) data_get($incoming, 'location.latitude').' '.(string) data_get($incoming, 'location.longitude'));
        }

        return trim((string) data_get($incoming, 'text.body'));
    }

    private function isMedia(array $incoming): bool
    {
        return in_array((string) data_get($incoming, 'type'), ['image', 'video', 'document', 'audio'], true);
    }

    private function isSkip(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['تخطي', 'لا أعرف', 'لا اعرف', 'غير معروف'], true);
    }

    private function data(WhatsAppConversation $conversation): array
    {
        return is_array($conversation->automation_data) ? $conversation->automation_data : [];
    }

    private function homeRow(): array
    {
        return [
            'id' => 'flow:home',
            'title' => 'القائمة الرئيسية',
            'description' => 'العودة إلى الخدمات الرئيسية',
        ];
    }
}

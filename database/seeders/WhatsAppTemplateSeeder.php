<?php

namespace Database\Seeders;

use App\Models\WhatsAppTemplate;
use Illuminate\Database\Seeder;

/**
 * Local Dr Bike drafting templates only. Meta template messages must separately
 * be created and approved in WhatsApp Manager before Cloud API can send them.
 */
class WhatsAppTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'continue_with_doctor_bike',
                'category' => 're_engagement',
                'body' => 'مرحباً بك 👋 هل تريد الاستمرار مع دكتور بايك؟ اضغط على الزر أدناه للمتابعة.',
                'variables' => [],
            ],
            ['name' => 'task_assigned', 'category' => 'tasks', 'body' => 'مرحباً {{1}}، تم إسناد مهمة جديدة إليك: {{2}}.'],
            ['name' => 'cheque_due_reminder', 'category' => 'cheques', 'body' => 'تذكير: الشيك رقم {{1}} بقيمة {{2}} مستحق بتاريخ {{3}}.'],
            ['name' => 'invoice_created', 'category' => 'invoices', 'body' => 'تم إنشاء الفاتورة رقم {{1}} بقيمة {{2}}. شكراً لتعاملكم معنا.'],
            ['name' => 'debt_payment_reminder', 'category' => 'debts', 'body' => 'مرحباً {{1}}، نذكركم بالدفعة المستحقة بقيمة {{2}} بتاريخ {{3}}.'],
            ['name' => 'attendance_notice', 'category' => 'attendance', 'body' => 'إشعار دوام للموظف {{1}}: {{2}} بتاريخ {{3}}.'],
        ];
        foreach ($templates as $template) {
            WhatsAppTemplate::query()->updateOrCreate(
                ['name' => $template['name']],
                $template + ['language' => 'ar', 'variables' => ['1', '2', '3'], 'is_active' => true]
            );
        }
    }
}

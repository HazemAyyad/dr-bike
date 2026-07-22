<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web cron manager (local / staging only unless explicitly enabled)
    |--------------------------------------------------------------------------
    */
    'web_enabled' => env('CRON_MANAGER_WEB_ENABLED', env('APP_ENV') === 'local'),

    'commands' => [
        'checks:send-due-reminders' => [
            'label' => 'تذكير الشيكات المستحقة',
            'description' => 'إشعارات الأدمن + FCM للشيكات المستحقة بعد يومين (وارد / صادر).',
            'scheduled' => true,
            'schedule_human' => 'يومياً 00:00',
        ],
        'employees:send-daily-task-reminders' => [
            'label' => 'تذكير مهام الموظفين اليومية',
            'description' => 'إشعار FCM لكل موظف لديه مهام غير مكتملة لليوم. يُسجَّل من وصل FCM فعلاً. بدون «force» لا يُعاد الإرسال لنفس الموظف في نفس اليوم.',
            'scheduled' => true,
            'schedule_human' => 'يومياً 10:00 (Asia/Hebron)',
            'arguments' => [
                'force' => [
                    'label' => 'إرسال حتى لو أُرسل اليوم (إعادة اختبار — مهم!)',
                    'type' => 'checkbox',
                    'option' => '--force',
                ],
            ],
        ],
        'database:backup' => [
            'label' => 'نسخة احتياطية من قاعدة البيانات',
            'description' => 'إنشاء ملف SQL من قاعدة بيانات MySQL وحفظه داخل storage/app/backups/database مع حذف النسخ القديمة حسب DB_BACKUP_KEEP_DAYS.',
            'scheduled' => true,
            'schedule_human' => 'كل ساعة (Asia/Hebron)',
        ],
        'sync:store' => [
            'label' => 'مزامنة المتجر',
            'description' => 'مزامنة التصنيفات والمنتجات (حالياً معطّلة داخل الأمر).',
            'scheduled' => false,
            'schedule_human' => 'يدوي',
        ],
        'images:generate-legacy-thumbs' => [
            'label' => 'إنشاء صور مصغرة (أرشيف)',
            'description' => 'توليد thumbnails لصور Images/Items القديمة.',
            'scheduled' => false,
            'schedule_human' => 'يدوي',
        ],
        'admin:fcm-test' => [
            'label' => 'اختبار FCM للأدمن',
            'description' => 'إرسال إشعار تجريبي لآخر توكن أدمن أو لتوكن تدخله.',
            'scheduled' => false,
            'schedule_human' => 'يدوي',
            'arguments' => [
                'token' => [
                    'label' => 'FCM token (اختياري)',
                    'placeholder' => 'اتركه فارغاً لاستخدام آخر توكن مسجّل',
                    'required' => false,
                ],
            ],
        ],
    ],
];

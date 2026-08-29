<?php

namespace App\Support;

final class NotificationCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function types(): array
    {
        return [
            'employee_login' => self::item('تسجيل دخول موظف', 'attendance', 'high', 'admin_login'),
            'employee_logout' => self::item('تسجيل خروج موظف', 'attendance', 'high', 'urgent'),
            'employee_task_completed' => self::item('إتمام مهمة موظف', 'tasks', 'normal', 'success'),
            'employee_task_submitted' => self::item('تسليم مهمة للمراجعة', 'tasks', 'normal', 'success'),
            'employee_subtask_completed' => self::item('إتمام مهمة فرعية', 'tasks', 'normal', 'success'),
            'employee_logout_pending_tasks' => self::item('خروج مع مهام معلقة', 'tasks', 'high', 'urgent'),
            'check_due_reminder' => self::item('تذكير شيك مستحق', 'checks', 'high', 'urgent'),
            'check_cashed' => self::item('صرف شيك', 'checks', 'normal', 'default'),
            'check_returned' => self::item('إرجاع شيك', 'checks', 'critical', 'urgent'),
            'sales_daily_closing_request' => self::item('طلب إغلاق مبيعات', 'sales', 'high', 'default'),
            'sales_cancellation_request' => self::item('طلب إلغاء مبيعات', 'sales', 'high', 'default'),
            'sales_daily_reopen_request' => self::item('طلب إعادة فتح المبيعات', 'sales', 'high', 'default'),
            'sales_daily_external_sale' => self::item('مبيعة خارجية يومية', 'sales', 'normal', 'sales_order'),
            'sales_daily_previous_day_open' => self::item('يوم مبيعات سابق مفتوح', 'sales', 'high', 'urgent'),
            'maintenance_daily_closing_request' => self::item('طلب إغلاق الصيانة', 'maintenance', 'high', 'default'),
            'maintenance_daily_previous_day_open' => self::item('يوم صيانة سابق مفتوح', 'maintenance', 'high', 'urgent'),
            'suspended_instant_sale_created' => self::item('إنشاء مبيعة معلقة', 'sales', 'normal', 'default'),
            'suspended_instant_sale_completed' => self::item('إتمام مبيعة معلقة', 'sales', 'normal', 'success'),
            'attendance_auto_checkout' => self::item('خروج تلقائي', 'attendance', 'high', 'urgent'),
            'attendance_absent_reminder' => self::item('تذكير غياب', 'attendance', 'high', 'urgent'),
            'attendance_overtime_request' => self::item('طلب عمل إضافي', 'attendance', 'high', 'default'),
            'employee_loan_request' => self::item('طلب سلفة', 'employees', 'high', 'default'),
            'store_user_registered' => self::item('تسجيل مستخدم متجر', 'store', 'normal', 'default'),
            'store_order_created' => self::item('طلب متجر جديد', 'store', 'high', 'sales_order'),
            'store_order_canceled' => self::item('إلغاء طلب متجر', 'store', 'high', 'urgent'),
            'support_message' => self::item('رسالة دعم', 'messages', 'high', 'default'),
            'negative_instant_sale_stock' => self::item('مخزون سالب بمبيعة فورية', 'stock', 'critical', 'urgent'),
            'negative_sales_order_stock' => self::item('مخزون سالب بطلبية', 'stock', 'critical', 'urgent'),
            'app_development_task' => self::item('مهمة تطوير تطبيق', 'development', 'normal', 'default'),
            'password_reset_otp' => self::item('طلب استعادة كلمة مرور', 'security', 'critical', 'urgent', true),
            'note_shared' => self::item('ملاحظة مشتركة', 'notes', 'normal', 'default'),
            'note_reminder' => self::item('تذكير ملاحظة', 'notes', 'normal', 'default'),
            'stock_images_export_ready' => self::item('تصدير صور المخزون جاهز', 'stock', 'normal', 'success'),
            'employee_points_changed' => self::item('تغيير نقاط موظف', 'employees', 'normal', 'default'),
            'employee_reward_earned' => self::item('مكافأة موظف', 'employees', 'normal', 'success'),
            'goal_daily_summary' => self::item('ملخص هدف يومي', 'goals', 'normal', 'default'),
            'goal_no_progress' => self::item('هدف دون تقدم', 'goals', 'high', 'urgent'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function bundledSounds(): array
    {
        return [
            'default' => self::sound('الافتراضي', null, null, 'dr_bike_admin_notifications'),
            'silent' => self::sound('بدون صوت', 'silent', 'silent', 'dr_bike_admin_silent'),
            'urgent' => self::sound('تنبيه عاجل', 'task_sos_alert', 'task_sos_alert.mp3', 'dr_bike_task_notifications'),
            'success' => self::sound('نجاح مهمة', 'task_success', 'task_success.wav', 'dr_bike_task_success_notifications'),
            'admin_login' => self::sound('دخول موظف', 'admin_login_motivate', 'admin_login_motivate.wav', 'dr_bike_admin_login_alerts'),
            'shiply_motorcycle' => self::sound('شبلي - حركة', 'shiply_motorcycle', 'shiply_motorcycle.wav', 'dr_bike_shiply_motorcycle'),
            'shiply_stuck' => self::sound('شبلي - تعثر', 'shiply_stuck', 'shiply_stuck.wav', 'dr_bike_shiply_stuck_alert'),
            'shiply_returned' => self::sound('شبلي - إرجاع', 'shiply_returned', 'shiply_returned.wav', 'dr_bike_shiply_returned_ambulance'),
            'shiply_delivered' => self::sound('شبلي - توصيل', 'shiply_delivered', 'shiply_delivered.wav', 'dr_bike_shiply_delivered_finale'),
            'sales_order' => self::sound('تحديث الطلبية', 'sales_order_church_bell', 'sales_order_church_bell.wav', 'dr_bike_sales_order_status'),
        ];
    }

    private static function item(string $name, string $category, string $priority, string $sound, bool $sensitive = false): array
    {
        return compact('name', 'category', 'priority', 'sound', 'sensitive');
    }

    private static function sound(string $name, ?string $android, ?string $ios, string $channel): array
    {
        return compact('name', 'android', 'ios', 'channel');
    }
}

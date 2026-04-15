<?php
return [
    // general
    'unexpected_error' => 'حدث خطأ غير متوقع',
    'validation_failed' => 'فشل التحقق',
    'something_wrong' => 'حدث خطأ ما، الرجاء المحاولة في وقت لاحق',
    'duplicate_email' => 'البريد الإلكتروني مستخدم بالفعل',
    'retrieve_data_error' => 'حدث خطأ أثناء تحميل البيانات، يرجى المحاولة في وقت لاحق',
    'create_data_error' => 'حدث خطأ أثناء حفظ البيانات، يرجى المحاولة في وقت لاحق',
    'update_data_error' => 'دث خطأ أثناء تعديل البيانات، يرجى المحاولة في وقت لاحق',
    'restore_data_error' => 'حدث خطأ أثناء استعادة البيانات، يرجى المحاولة في وقت لاحق',


    // Authentication

    'registration_success' => 'تم، انتقل الى صفحة ارسال كود OTP',
    'otp_sent' => 'تم إرسال رمز التحقق إلى بريدك الإلكتروني',
    'otp_invalid' => 'رمز OTP أو البريد الإلكتروني غير صحيح',
    'otp_verified' => 'تم التحقق من رمز OTP بنجاح. تم تفعيل حسابك',
    'login_error' => 'حدث خطأ أثناء تسجيل الدخول',
    'invalid_credentials' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة',
    'logout_success' => 'تم تسجيل الخروج',
    'logout_failed' => 'فشل تسجيل الخروج',
    'expired_token' =>'تحتاج لتسجيل الدخول',

    'old_password_mismatch' => 'كلمة المرور القديمة غير صحيحة',
    'password_updated' => 'تم تحديث كلمة المرور بنجاح',
    'reset_link_sent' => 'تم إرسال رابط إعادة تعيين كلمة المرور',
    'reset_link_failed' => 'فشل في إرسال رابط إعادة التعيين',
    'reset_failed' => 'فشل إعادة تعيين كلمة المرور',

    'reset_code_sent' => 'تم إرسال رمز إعادة تعيين كلمة المرور إلى بريدك الإلكتروني',
    'reset_code_failed' => 'فشل في إرسال رمز إعادة التعيين',
    'validation_failed' => 'فشل في التحقق من البيانات',
    'invalid_token' => 'رمز إعادة التعيين غير صحيح',
    'password_reset_success' => 'تمت إعادة تعيين كلمة المرور بنجاح',
    'reset_failed' => 'فشل في إعادة تعيين كلمة المرور',

    // special tasks
    'load_completed_tasks_failed' => 'فشل في تحميل المهام المكتملة',
    'load_ongoing_tasks_failed' => 'فشل في تحميل المهام الجارية',
    'load_canceled_tasks_failed' => 'فشل في تحميل المهام الملغاة',
    'task_canceled' => 'تم إلغاء المهمة الخاصة',
    'task_cancel_failed' => 'فشل في إلغاء المهمة الخاصة',
    'task_created' => 'تم إنشاء المهمة الخاصة بنجاح',
    'task_create_failed' => 'فشل في إنشاء المهمة الخاصة',
    'task_not_found' => 'المهمة الخاصة غير موجودة',
    'task_restored' => 'تم استعادة المهمة الخاصة',
    'can_not_complete_special_task' => 'لا يمكن اتمام المهمة الا عند اتمام جميع مهامها الفرعية',
    'task_completed' => 'تم اكمال المهمة',
    'task_transfered'=>'تم نقل المهمة بنجاح',
    // update profile
    'profile_updated' => 'تم تحديث المعلومات الشخصية بنجاح',

    // employee tasks
    'failed_to_load_tasks' => 'فشل في تحميل المهام',
    'employee_task_not_found' => 'لم يتم العثور على مهمة الموظف',
    'employee_task_canceled' => 'تم إلغاء مهمة الموظف',
    'employee_task_created_successfully' => 'تم إنشاء مهمة الموظف بنجاح',
    'failed_to_cancel_task' => 'فشل في إلغاء المهمة',
    'failed_to_create_task' => 'فشل في إنشاء مهمة الموظف',
    'failed_to_fetch_task_details' => 'فشل في جلب تفاصيل المهمة',
    'employee_task_restored' => 'تم استعادة مهمة الموظف',
    'failed_to_restore_task' => 'فشل في استعادة مهمة الموظف',
    'employee_task_updated_successfully' =>'تم تعديل مهمة الموظف بنجاح',
    'can_not_complete_employee_task' => 'لا يمكن اتمام المهمة الا عند اتمام جميع مهامها الفرعية',
    'unauthorized'      => 'لست مصرحا لأداء هذه العملية',
    'task_not_found'    => ' لم يتم العثور على المهمة ',
    'invalid_task_type' => 'نوع المهمة غير صالح',
    'employee_images_updated'=>'تم تعديل صور الموظف لمهمة الموظفين بنجاح',
    'employee_image_required'=>'يجب تحميل صورة من الموظف قبل انهاء المهمة',
    'employee_sub_task_images_updated'=>'تم تعديل صور الموظف لمهمة فرعية بنجاح',


    //employees
    'employee_created_successfully' => 'تم انشاء موظف جديد بنجاح',
    'employee_updated_successfully' => 'تم تعديل بيانات الموظف بنجاح',
    'employee_not_found' => 'لم يتم العثور على الموظف',
    'failed_to_create_employee' => 'فشل في محاولة انشاء موظف',
    'failed_to_update_employee' => 'فشل في محاولة تعديل موظف',
    'arrival_time' => 'تم تسجيل وقت الوصول',
    'departure_time' => 'تم تسجيل وقت الرحيل',
    'already_scanned' => 'تم المسح بالفعل اليوم',
    'salary_paid' => 'تم دفع الراتب بنجاح',
    'points_updated' => 'تم تعديل نقاط الموظف بنجاح',


    'project_created_successfully' => 'تم إنشاء المشروع بنجاح',
    'project_details_loaded' => 'تم تحميل تفاصيل المشروع بنجاح',
    'project_not_found' => 'المشروع غير موجود',
    'failed_to_create_project' => 'فشل في إنشاء المشروع',
    'failed_to_load_project_details' => 'فشل في تحميل تفاصيل المشروع',
    'ongoing_projects_loaded' => 'تم جلب المشاريع الجارية بنجاح',
    'failed_to_load_ongoing_projects' => 'فشل في تحميل المشاريع الجارية',
    'completed_projects_loaded' => 'تم جلب المشاريع المكتملة بنجاح',
    'failed_to_load_completed_projects' => 'فشل في تحميل المشاريع المكتملة',
    'validation_failed' => 'فشلت عملية التحقق من البيانات',

    'something_wrong' => 'حدث خطأ ما، يرجى المحاولة مرة أخرى',
    'project_already_has_product'=>'المنتج موجود في المشروع بالفعل',
    'product_added_to_project'=>'تم اضافة المنتج للمشروع بنجاح',
    'cannot_add_share_or_percentage'=>'لا يمكن اضافة حصة الشريك او نسبة الربح بدون اختيار شريك',

    'project_updated'=>'تم تعديل المشروع بنجاح',
    'project_completed'=>'تم الانتهاء من المشروع بنجاح',

    'created_customer_successfully' => 'تم إنشاء العميل بنجاح',
    'created_seller_successfully' => 'تم إنشاء التاجر بنجاح',

    'customer_deleted_successfully' => 'تم حذف العميل بنجاح',
    'customer_not_found' => 'العميل غير موجود',
    'failed_to_create_customer' => 'فشل في إنشاء العميل',
    'failed_to_delete_customer' => 'فشل في حذف العميل',
    'failed_to_load_customer_details' => 'فشل في تحميل تفاصيل العميل',
    'failed_to_load_customers' => 'فشل في تحميل قائمة العملاء',
    'failed_to_restore_customer' => 'فشل استعادة العميل',
    'customer_restored_successfully' =>'تم استعادة العميل بنجاح',
    'person_updated'=>'تم تعديل بيانات الشخص بنجاح',

    'cannot_delete_person'=>'لا يمكن حذف الشخص لامتلاكه حسابات مالية',
    'person_deleted'=>'تم حذف الشخص بنجاح',
    'cannot_delete_person_for_check' => 'لا يمكن حذف الشخص لوجود شيك وارد منه غير متصرف فيه',

    // follow up
    'followup_created_successfully'    => 'تم إنشاء المتابعة بنجاح',
    'followup_canceled_successfully'   => 'تم إلغاء المتابعة بنجاح',
    'failed_to_create_followup'        => 'فشل في إنشاء المتابعة',
    'failed_to_cancel_followup'        => 'فشل في إلغاء المتابعة',
    'failed_to_load_followups'         => 'فشل في تحميل قائمة المتابعات',
    'followup_not_found'               => 'المتابعة غير موجودة',
    'followup_restored_successfully' => 'تم استعادة المتابعة بنجاح',
    'failed_to_restore_followup' => 'فشل في استعادة المتابعة',
    'followup_updated' => 'تم تعديل المتابعة بنجاح',

    //goals
    'goal_created_successfully'     => 'تم إنشاء الهدف بنجاح',
    'goal_cancelled_successfully'    => 'تم إلغاء الهدف بنجاح',
    'goal_transferred_successfully'    => 'تم نقل الهدف بنجاح',
    'goal_updated_successfully'=>'تم تعديل الهدف بنجاح',

    'failed_to_create_goal'         => 'فشل في إنشاء الهدف',
    'failed_to_cancel_goal'         => 'فشل في إلغاء الهدف',
    'failed_to_load_goal'           => 'فشل في تحميل تفاصيل الهدف',
    'failed_to_load_goals'          => 'فشل في تحميل قائمة الأهداف',
    'goal_not_found'                => 'الهدف غير موجود',
    'goal_restored_successfully'    => 'تم استعادة الهدف بنجاح',
    'failed_to_restore_goal'        => 'فشل في استعادة الهدف',
    'one_is_required' =>'product id او customer id او employee id مطلوب ادخال',
    'goal_updated'=>'تم تعديل الهدف بنجاح',
    'goal_deleted'=>'تم حذف الهدف بنجاح',

    //debts
    'debt_created_successfully'   => 'تم إنشاء الدين بنجاح',
    'debt_updated_successfully'   => 'تم تعديل الدين بنجاح',
    'failed_to_create_debt'       => 'فشل في إنشاء الدين',
    'failed_to_update_debt'       => 'فشل في تعديل الدين',
    'failed_to_load_debt'         => 'فشل في تحميل تفاصيل الدين',
    'failed_to_load_debts'        => 'فشل في تحميل قائمة الديون',
    'debt_not_found'              => 'لم يتم العثور على الدين',

   //deposit
    'deposit_created_successfully' => 'تم انشاء الايداع بنجاح',
    'failed_to_creat_deposit' => 'فشل في انشاء الايداع',

    //draw
    'draw_created_successfully' => 'تم انشاء السحب بنجاح',
    'failed_to_creat_draw' => 'فشل في انشاء السحب',

    //maintenance
    'failed_to_load_maintenances' => 'فشل في تحميل بيانات الصيانة',
    'maintenance_created_successfully' => 'تم انشاء الصيانة بنجاح',
    'maintenance_not_found'=>'لم يتم العثور على الصيانة',
    'maintenance_updated_successfully'=>'تم تعديل حالة الصيانة بنجاح',

    // Boxes
    'box_created_successfully'     => 'تم إنشاء الصندوق بنجاح.',
    'box_updated_successfully'     => 'تم تحديث بيانات الصندوق بنجاح.',
    'box_not_found'                => 'الصندوق غير موجود.',
    'validation_failed'            => 'فشل التحقق من البيانات.',
    'retrieve_data_error'          => 'فشل في جلب البيانات من قاعدة البيانات.',
    'failed_to_create_box'         => 'فشل في إنشاء الصندوق.',
    'failed_to_update_box'         => 'فشل في تحديث الصندوق.',
    'failed_to_load_box'           => 'فشل في تحميل تفاصيل الصندوق.',
    'failed_to_load_boxes'         => 'فشل في تحميل الصناديق.',
    'drawer_boxes_not_found'       => 'لم يتم العثور على صناديق الدرج.',
    'bank_account_boxes_not_found' => 'لم يتم العثور على صناديق الحسابات البنكية.',
    'safe_boxes_not_found'         => 'لم يتم العثور على صناديق الخزنة.',
    'balance_transfered' => 'تم تحويل الرصيد بنجاح',
    'not_enough' => 'الرصيد المحوّل أعلى من رصيد الصندوق',
    'cannot_transfer_same_box' => 'لا يمكن التحويل إلى نفس الصندوق',
    'box_balance_added_successfully' => 'تم إضافة رصيد للصندوق بنجاح',
     'box_balance_deduct_successfully' => 'تم الخصم من رصيد الصندوق',
    'box_deleted' => 'تم حذف الصندوق بنجاح',

    'box_out_of_money' => 'لا يوجد رصيد كافي في الصندوق',
    'must_be_same_currency' => 'يجب أن تحتوي الصناديق على نفس العملة',
    'must_be_same_currency_check' => 'يجب ان يحتوي الشيك والصندوق على نفس العملة',
    'box_must_be_shekel' => 'يجب ان تكون عملة الصندوق شيكل',

    //partnerships
    'partnership_created_successfully' => 'تم إنشاء الشراكة بنجاح.',
    'partnership_updated_successfully' => 'تم تحديث الشراكة بنجاح.',
    'partnership_not_found' => 'الشراكة غير موجودة.',
    'retrieve_data_error' => 'فشل في جلب البيانات.',
    'something_wrong' => 'حدث خطأ ما.',
    'validation_failed' => 'فشل التحقق من البيانات.',
    'one_type_required' => 'يجب تحديد نوع واحد فقط من: المنتج أو القسم أو القسم الفرعي أو المشروع.',


    //rewards
    'reward_created'=>'تم انشاء المكافأة بنجاح',

    //punishments
    'punishment_created'=>'تم انشاء العقوبة بنجاح',

    //notification
    'notificationSent'=>'تم ارسال الاشعار بنجاح',
    'notificationFailed' => 'فشل في إرسال الإشعار.',
    'firebaseInitError' => 'تعذر تهيئة خدمة Firebase.',
    'firebaseSendError' => 'فشلت خدمة Firebase في إرسال الإشعار.',
    'firebaseUnknownError' => 'حدث خطأ غير معروف أثناء إرسال الإشعار.',
    //employee_orders
    'employee_order_created'=>'تم انشاء طلب موظف بنجاح',
    'status_upated'=>'تم تعديل حالة الطلب بنجاح',
    'only_one_extra_hours'=>'بامكانك اضافة ساعات عمل عادية او اوفرتايم',
    'one_extra_hours_should_filled' => 'عليك اضافة اما ساعات عمل عادية او اوفرتايم',
       //profit sales
    'profit_sale_created_successfully' => 'تم انشاء ربح نقدي جديد',

    //instant sales
    'instant_sale_created_successfully' => 'تم انشاء بيع فوري جديد',
    'sale_attached' => 'تم ربط البيع الفوري بمشروع الشراكة بنجاح',
    'cant_be_project_type'=>'المنتج غير مرتبط في أي مشروع بعد',

    //outgoing_checks
    'check_created_successfully'=>'تم انشاء الشيك الصادر بنجاح',
    'outgoing_check_not_found' => 'لم يتم العثور على الشيك الصادر',
    'outgoing_check_cancelled' => 'تم اعدام الشيك الصادر بنجاح',
    'check_cashed' =>'تم صرف الشيك بنجاح',
    'one_person_required' => 'يجب إدخال الزبون أو البائع.',
    'only_one_person_allowed' => 'يرجى إدخال إما الزبون أو البائع، وليس كلاهما.',
    'outgoing_check_returned' => 'تم ارجاع الشيك الصادر بنجاح',
    'outgoing_check_cashed' => 'تم صرف الشيك الصادر بنجاح',

    // incoming checks
    'must_select_customer_or_seller' => 'يجب اختيار زبون أو بائع',
    'must_select_either_customer_or_seller' => 'اختر إما زبونًا أو بائعًا، وليس كليهما',
    'must_select_employee' => 'يجب اختيار موظف',
    'must_select_box' =>'يجب اختيار صندوق معين',
    'must_select_choice'=>'عليك اختيار احد الخيارات للهدف',
    'must_select_perosn' => 'يجب اختيار شخص على الاكثر',
    'must_select_one_perosn' =>'يجب اختيار شخص واحد فقط',
    'incoming_check_created_successfully' => 'تم إنشاء الشيك الوارد بنجاح',
    'check_cancelled' => 'تم إلغاء الشيك بنجاح',
    'check_returned' => 'تم ارجاع الشيك بنجاح',
    'check_updated' =>'تم تعديل الشيك بنجاح',
    'must_select_incoming_or_outgoing'=>'يجب اختيار شيك وارد او صادر',
    'must_select_either_incoming_or_outgoing' => 'يجب اختيار اما شيك وارد او صادر وليس كلاهما',
    'check_deleted'=>'تم حذف الشيك بنجاح',
    'cannot_delete_check' => 'يجب ان يكون الشيك غير متصرف به او في الارشيف ليتم حذفه',


    //logs
    'log_cancelled' => 'تم الغاء النشاط بنجاح',

    // project expenses
    'expense_created' => 'تم انشاء مصروف للمشروع بنجاح',

    //assets
    'asset_created' => 'تم انشاء الأصل بنجاح',
    'asset_depreciated' => 'تم اهلاك الأصول بنجاح',
    'asset_not_found'=>'لم يتم العثور على الأصل',
    'asset_updated'=>'تم تعديل الاصل بنجاح',
    'asset_deleted'=>'تم حذف الاصل بنجاح',
    'cannot_depreciate'=>'لا يمكن اهلاك الاصل لان سعر اهلاكه الحالي 0',


    //expenses
    'expense_created' => 'تم اضافة المصروف بنجاح',
    'expense_updated' =>'تم تعديل المصروف بنجاح',

    //destructions
    'destruction_created' => 'تم اتلاف البضاعة بنجاح',
    'stcok_failed'=>'مخزون المنتج أقل من عدد القطع ',

    //qr
    'invalid_qr'=>'رمز QR'.' '.'غير صالح',

    //treasuries
    'treasury_created'=>'تم انشاء الخزنة بنجاح',
    'treasury_cancelled' =>'تم حذف الخزنة بنجاح',

    //files
    'file_created' => 'تم انشاء الفايل بنجاح',
    'file_deleted' => 'تم حذف الفايل بنجاح',

    // file boxes
    'fileBox_created' => 'تم انشاء فايل بوكس بنجاح',
    'fileBox_cancelled'=>'تم حذف الفايل بوكس بنجاح',

       //pictures
    'picture_created'=>'تم انشاء الصورة/الفيديو بنجاح',
    'picture_updated'=>'تم تعديل الصورة/الفيديو بنجاح',
    'picture_deleted'=>'تم حذف الصورة/الفيديو بنجاح',


        //papers
    'paper_created'=>'تم انشاء الورقة بنجاح',
    'file_box_not_for_treasury_selected'=>'الفايل بوكس ليس ضمن الخزنة المختارة',
    'file_not_for_fil_box_selected'=>'الفايل ليس ضمن الفايل بوكس المختار',
    'paper_cancelled'=>'تم حذف الورقة بنجاح',
    'paper_updated' => 'تم تعديل الورقة بنجاح',
    //stocks
    'product_updated'=>'تم تعديل المنتج بنجاح',
    'product_created'=>'تم إنشاء المنتج بنجاح',

        // closeouts
    'closeout_added'=>'تم اضافة المنتج الى التصفيات بنجاح',
    'closeout_status_updated'=>'تم نقل  التصفية الى ارشيف التصفيات',
    'cant_create_closeout'=>'المنتج ضمن التصفيات بالفعل',

    'cant_sale'=>'الكيمة المراد بيعها اعلى من مخزون المنتج',

    // combinations
    'combination_created'=>'تم تركيب المنتجات بنجاح',

    // bills and returns
'bill_added' => 'تمت إضافة الفاتورة بنجاح',
'bill_quantity_added' => 'تمت إضافة كمية الفاتورة بنجاح',
'product_status_updated' => 'تم تحديث حالة المنتج بنجاح',
'product_extra_was_purchased' => 'تم شراء منتج إضافي',
'bill_cancelled' => 'تم إلغاء الفاتورة',
'bill_was_delivered' => 'تم تسليم الفاتورة بنجاح',
'entered_amount_bigger_than_quantity' => 'الكمية المدخلة أكبر من الكمية المتوفرة',
'must_be_status_extra' => 'يجب أن تكون حالة مرتجع زيادة ',
'must_be_status_securities' => 'يجب أن تكون الفاتورة في حالة الامانات',
'bill_was_delivered' => 'تم تسليم الفاتورة بنجاح',
'return_products_added' => 'تمت إضافة مردودات المشتريات بنجاح',
'return_delivered' => 'تم تسليم مردودات المشتريات بنجاح',
'can_only_change_status_once'=>'يتم تغيير حالة المنتج مرة واحدة فقط',
'product_extra_or_not_compatible'=>'يجب ان تكون حالة المنتج مرتجع زيادة او غير متوافق',
'product_was_delivered'=>'تم التسليم بنجاح',
'must_be_status_not_compatible'=>'حالة المنتج يجب ان تكون غير متوافق',

    //product dev
    'prodev_step_updated'=>'تم تعديل خطوة تطوير المنتج بنجاح',
    'prodev_created'=>'تم انشاء تطوير المنتج بنجاح',

        // payment and recieve
    'payment_success'=>'تم الدفع بنجاح',
    'receive_success'=>'تم الاستلام بنجاح',

    'must_enter_box_value' => 'يجب ادخال قيمة المبلغ النقدي',


        // updated goals
    'form_must_be_employee' => 'يجب أن تكون الصيغة هي موظف.',
    'form_must_be_people' => 'يجب أن تكون الصيغة هي شخص.',
    'form_must_be_box' => 'يجب أن تكون الصيغة هي صندوق.',
    'must_select_one_choice' => 'يجب اختيار خيار واحد فقط من الخيارات المتاحة.',
    'invalid_form_for_sell_type' => 'الصيغة غير صالحة لنوع الهدف الخاص بالمبيعات.',
    'invalid_form_for_purchase_type' => 'الصيغة غير صالحة لنوع الهدف الخاص بالمشتريات.',
    'invalid_form_for_general_type' => 'الصيغة غير صالحة لهذا النوع من الأهداف.',
    'form_does_not_match_selected_field' => 'الصيغة لا تتطابق مع الحقل المحدد.',
    'must_select_one_person'=>'يجب اختيار شخص واحد للدفع',

// updated tasks
'enter_recurrence_time' => 'يجب إدخال وقت التكرار.',
'enter_one_recurrence_time' => 'يجب إدخال قيمة واحدة فقط لوقت التكرار.',
'invalid_monthly_recurrence_day' => 'اليوم المحدد لتكرار المهمة شهريًا غير صالح.',
'end_date_before_start'=>'تاريخ الانتهاء يجب ان يكون بعد تاريخ البداية',
'cannot_update_recurrence_task' =>'لا يمكن التعديل على مهمة تكرارية',


    //updated checks
    'check_cashed_from_box'=>'تم صرف الشيك من الصندوق بنجاح',
    //updated debts
    'currency_shekel'=>'عملة الصندوق يجب ان تكون شيكل'
];

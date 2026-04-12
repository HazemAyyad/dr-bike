# ملخص API Endpoints حسب نوع المستخدم

## مقدمة

- **Base URL:** `{{baseURL}}` (مثال: `https://doctor-bike.test/api`)
- **التوثيق:** لأي route يتطلب تسجيل دخول أضف الهيدر: `Authorization: Bearer <token>`

**أنواع المستخدمين:**
- **عام (Public):** لا يحتاج token.
- **مستخدم مسجل (Authenticated):** أي user مسجل (admin أو employee أو customer).
- **أدمن فقط:** `user->type === 'admin'`.
- **موظف فقط:** `user->type === 'employee'`.
- **حسب الصلاحية:** أدمن يمر تلقائياً؛ موظف يحتاج الصلاحية المحددة (بـ name_en). عدة صلاحيات مفصولة بفاصلة = أي واحدة تكفي.

---

## 1. Endpoints عامة (Public)

لا تحتاج token.

### المصادقة والتسجيل
| Method | Path | الوصف |
|--------|------|--------|
| POST | /register | تسجيل مستخدم جديد (مع OTP) |
| POST | /send/code | إرسال كود التحقق إلى البريد |
| POST | /verify/code | التحقق من كود OTP |
| POST | /login | تسجيل الدخول |
| POST | /forgot-password | طلب إعادة تعيين كلمة المرور |
| POST | /reset-password | إعادة تعيين كلمة المرور |
| POST | /quick/register | تسجيل سريع |

### إشعارات
| Method | Path | الوصف |
|--------|------|--------|
| POST | /send/notification | إرسال إشعار (push) |

### بيانات عامة (بدون auth)
| Method | Path | الوصف |
|--------|------|--------|
| GET | /get/all/subcategories | كل الأقسام الفرعية |
| GET | /get/all/categories | كل الأقسام |
| GET | /get/all/projects | كل المشاريع |
| GET | /employees | قائمة الموظفين |
| GET | /all/sellers | كل البائعين |
| GET | /all/customers | كل العملاء |
| GET | /get/shown/boxes | الصناديق المعروضة |
| GET | /all/products | كل المنتجات |

---

## 2. أي مستخدم مسجل (Authenticated)

مطلوب تسجيل دخول فقط (`Authorization: Bearer <token>`).

### الجلسة والبروفايل
| Method | Path | الوصف |
|--------|------|--------|
| GET | /user | بيانات المستخدم الحالي |
| POST | /logout | تسجيل الخروج |
| POST | /change/password | تغيير كلمة المرور |
| POST | /update/profile | تحديث البيانات الشخصية |
| POST | /me | بياناتي |

### الطلبات (عملياً للعملاء)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/order | إضافة طلب |
| GET | /my/pending/orders | طلباتي المعلقة |
| GET | /my/completed/orders | طلباتي المكتملة |
| GET | /my/canceled/orders | طلباتي الملغاة |
| POST | /cancel/my/order | إلغاء طلبي |

### الشراء الفوري
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/instant/buying | إضافة شراء فوري |
| GET | /all/instant/buyings | كل الشراءات الفورية |

### مشتركة (مالك المهمة أو صلاحية Employee Tasks)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /show/employee/task | عرض تفاصيل مهمة موظف |
| POST | /change/employee/task/to/completed | تغيير مهمة الموظف إلى مكتملة |

### دفعات واستلام
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/transaction | إضافة معاملة (دفع/استلام) |

---

## 3. أدمن فقط (Admin only)

فقط `user->type === 'admin'`.

### تطوير المنتج
| Method | Path | الوصف |
|--------|------|--------|
| GET | /get/all/product/developments | كل تطويرات المنتجات |
| POST | /update/product/development/step | تحديث خطوة التطوير |
| POST | /create/product/development | إنشاء تطوير منتج |
| POST | /show/product/development | عرض تطوير منتج |

### التقارير
| Method | Path | الوصف |
|--------|------|--------|
| GET | /get/all/report/information | بيانات التقارير الرئيسية |
| POST | /get/reprot/by/type | تقرير حسب النوع |

### الشراكات
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/partnership | إضافة شراكة |
| GET | /ongoing/partnerships | الشراكات الجارية |
| GET | /completed/partnerships | الشراكات المكتملة |
| POST | /show/partnership | عرض شراكة |
| POST | /edit/partnership | تعديل شراكة |

### السحوبات
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/draw | إضافة سحب |

### السجلات ولوحة الأدمن
| Method | Path | الوصف |
|--------|------|--------|
| GET | /all/logs | كل السجلات |
| POST | /cancel/log | إلغاء سجل |
| POST | /show/log | عرض سجل |
| GET | /admin/home/page/data | بيانات الصفحة الرئيسية للأدمن |

---

## 4. موظف فقط (Employee only)

فقط `user->type === 'employee'`.

### طلبات الموظف
| Method | Path | الوصف |
|--------|------|--------|
| POST | /order/by/employee | إنشاء طلب بواسطة الموظف |

### الحضور (QR)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /qr-scan | مسح QR للحضور |

### الصفحة الرئيسية والحضور
| Method | Path | الوصف |
|--------|------|--------|
| GET | /employee/home/data | بيانات الصفحة الرئيسية للموظف |
| GET | /get/attendance/details | تفاصيل الحضور |

### مهام الموظف (تعديل الصور)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /employee/edit/employee/task/images | تعديل صور مهمة الموظف |
| POST | /employee/edit/employee/sub/task/images | تعديل صور المهمة الفرعية |

### طلبات overtime و loan
| Method | Path | الوصف |
|--------|------|--------|
| POST | /employee/add/overtime/order | طلب وقت إضافي |
| POST | /employee/add/loan/order | طلب سلفة |
| GET | /employee/orders | طلباتي (موظف) |

### إكمال مهمة فرعية
| Method | Path | الوصف |
|--------|------|--------|
| POST | /change/sub/employee/task/to/completed | إكمال مهمة فرعية للموظف |

---

## 5. حسب الصلاحية (By permission)

أدمن يمر دائماً؛ موظف يمر إذا كانت لديه **أي** من الصلاحيات المذكورة (بـ name_en).

### Special Tasks (المهام الخاصة)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /create/special/task | إنشاء مهمة خاصة |
| GET | /completed/special/tasks | المهام الخاصة المكتملة |
| GET | /ongoing/special/tasks | المهام الخاصة الجارية |
| GET | /canceled/special/tasks | المهام الخاصة الملغاة |
| POST | /cancel/special/task | إلغاء مهمة خاصة |
| POST | /restore/special/task | استعادة مهمة خاصة |
| POST | /show/special/task | عرض مهمة خاصة |
| POST | /cancel/special/task/with/repitition | إلغاء مهمة خاصة مع التكرار |
| GET | /no-date/special/tasks | مهام خاصة بدون تاريخ |
| POST | /change/special/task/to/completed | تغيير مهمة خاصة إلى مكتملة |
| POST | /change/sub/special/task/to/completed | إكمال مهمة فرعية خاصة |
| POST | /transfer/special/task | نقل مهمة خاصة |
| POST | /update/special/task | تحديث مهمة خاصة |

### Employees Section (قسم الموظفين)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /create/employee | إنشاء موظف |
| GET | /working/times | أوقات العمل |
| GET | /financial/dues | المستحقات المالية |
| POST | /edit/employee | تعديل موظف |
| GET | /all/permissions | كل صلاحيات النظام |
| POST | /employee/permissions | صلاحيات موظف معين |
| POST | /add/points/to/employee | إضافة نقاط لموظف |
| POST | /minus/points/from/employee | خصم نقاط من موظف |
| POST | /show/employee/financial/details | التفاصيل المالية للموظف |
| POST | /pay/employee/salary | دفع راتب موظف |
| POST | /get/employee/financial/data/report | تقرير البيانات المالية للموظف |
| GET | /employee/logs | سجلات الموظفين |
| GET | /employee/loan/orders | طلبات السلف |
| GET | /employee/overtime/orders | طلبات الوقت الإضافي |
| POST | /approve/employee/loan/order | الموافقة على طلب سلفة |
| POST | /reject/employee/order | رفض طلب موظف |
| POST | /show/employee/loan/order | عرض طلب سلفة |
| POST | /show/employee/overtime/order | عرض طلب وقت إضافي |
| POST | /approve/employee/overtime/order | الموافقة على طلب وقت إضافي |
| GET | /qr-generation | توليد QR (حضور) |

### Employee Tasks (مهام الموظفين)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /create/employee/task | إنشاء مهمة موظف |
| POST | /edit/employee/task | تعديل مهمة موظف |
| GET | /employee/completed/tasks | مهام الموظفين المكتملة |
| GET | /employee/ongoing/tasks | مهام الموظفين الجارية |
| GET | /employee/canceled/tasks | مهام الموظفين الملغاة |
| POST | /cancel/employee/task | إلغاء مهمة موظف |
| POST | /restore/employee/task | استعادة مهمة موظف |
| POST | /cancel/employee/task/with/repetition | إلغاء مهمة موظف مع التكرار |

### Projects and Purchases Management (إدارة مشاريع ومشتريات)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /create/project | إنشاء مشروع |
| POST | /show/project | عرض تفاصيل مشروع |
| GET | /ongoing/project | المشاريع الجارية |
| GET | /completed/project | المشاريع المكتملة |
| POST | /edit/project | تعديل مشروع |
| POST | /complete/a/project | إكمال مشروع |
| POST | /project/sales | مبيعات المشروع |
| POST | /add/product/to/project | إضافة منتج لمشروع |
| GET | /all/partners | كل الشركاء |
| POST | /add/project/expense | إضافة مصروف مشروع |
| POST | /get/project/expenses | مصروفات المشروع |

### General Data, Data Completion (البيانات العامة، اكمال البيانات)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /create/person | إنشاء شخص (عميل/بائع) |
| POST | /show/person | عرض شخص |
| POST | /cancel/customer | إلغاء عميل |
| POST | /restore/customer | استعادة عميل |
| POST | /edit/person | تعديل شخص |
| POST | /delete/person | حذف شخص |
| GET | /main/page/customers | عملاء الصفحة الرئيسية |
| GET | /main/page/sellers | بائعون الصفحة الرئيسية |
| GET | /main/page/incomplete/persons | أشخاص غير مكتملي البيانات |

### Sales (المبيعات)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/deposit | إضافة إيداع |
| GET | /all/instant/sales | كل المبيعات الفورية |
| GET | /show/instant/sale | عرض مبيع فوري |
| POST | /create/instant/sale | إنشاء مبيع فوري |
| POST | /edit/instant/sale | تعديل مبيع فوري |
| POST | /get/product/projects | مشاريع المنتج |
| POST | /get/subsales | المبيعات الفرعية |
| POST | /get/instant/sale/invoice | فاتورة المبيع الفوري |
| GET | /all/profit/sales | كل مبيعات الربح |
| GET | /show/profit/sale | عرض مبيع ربح |
| POST | /create/profit/sale | إنشاء مبيع ربح |
| POST | /edit/profit/sale | تعديل مبيع ربح |

### Follow-up Section (قسم المتابعة)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/followup | إضافة متابعة |
| GET | /get/initial/followups | متابعات المرحلة الأولى |
| GET | /get/inform/person/followups | متابعات إعلام الشخص |
| GET | /get/finish/and/agreement/followups | متابعات الإنهاء والاتفاق |
| GET | /get/archived/followups | متابعات أرشفة |
| GET | /canceled/followup | متابعات ملغاة |
| POST | /cancel/followup | إلغاء متابعة |
| POST | /update/followup | تحديث متابعة |
| POST | /show/followup | عرض متابعة |
| POST | /update/followup/step/three | تحديث خطوة المتابعة 3 |
| POST | /followup/store/customer | حفظ عميل من المتابعة |

### Maintenance (قسم الصيانة)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/maintenance | إضافة صيانة |
| GET | /get/new/maintenances | صيانات جديدة |
| GET | /get/ongoing/maintenances | صيانات جارية |
| GET | /get/ready/maintenances | صيانات جاهزة |
| GET | /get/delivered/maintenances | صيانات مسلّمة |
| POST | /change/maintenance/to/ongoing | تغيير صيانة إلى جارية |
| POST | /change/maintenance/to/ready | تغيير صيانة إلى جاهزة |
| POST | /change/maintenance/to/delivered | تغيير صيانة إلى مسلّمة |
| POST | /show/maintenance | عرض صيانة |
| POST | /change/maintenance/status | تغيير حالة صيانة |

### Boxes Section (قسم الصناديق)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/box | إضافة صندوق |
| POST | /edit/box | تعديل صندوق |
| POST | /show/box | عرض صندوق |
| GET | /get/hidden/boxes | الصناديق المخفية |
| POST | /add/box/balance | إضافة رصيد لصندوق |
| POST | /transfer/box/balance | تحويل رصيد بين صناديق |
| POST | /delete/box | حذف صندوق |
| GET | /all/box/logs | سجلات الصناديق |
| POST | /box/logs/report | تقرير سجلات الصناديق |

### Debts (الديون)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/debt | إضافة دين |
| POST | /show/debt | عرض دين |
| POST | /edit/debt | تعديل دين |
| GET | /total/debts/owed/to/us | إجمالي الديون المستحقة لنا |
| GET | /total/debts/we/owe | إجمالي الديون التي علينا |
| GET | /get/debts/we/owe | الديون التي علينا |
| GET | /get/debts/owed/to/us | الديون المستحقة لنا |
| POST | /person/debts | ديون شخص |
| POST | /get/debts/reports | تقارير الديون |

### Checks (قسم الشيكات)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/outgoing/check | إضافة شيك صادر |
| POST | /cancel/an/outgoing/check | إلغاء شيك صادر |
| POST | /return/an/outgoing/check | إرجاع شيك صادر |
| POST | /cash/an/outgoing/check | صرف شيك صادر |
| POST | /cash/an/outgoing/check/to/person | صرف شيك صادر لشخص |
| GET | /not-cashed/outgoing/checks | شيكات صادرة غير مصروفة |
| GET | /cashed/to/person/outgoing/checks | شيكات صادرة مصروفة لشخص |
| GET | /cancelled/outgoing/checks | شيكات صادرة ملغاة |
| GET | /returned/outgoing/checks | شيكات صادرة مرتجعة |
| GET | /general/outgoing/checks/data | بيانات الشيكات الصادرة العامة |
| GET | /general/checks/data/first/page | بيانات الشيكات الصفحة الأولى |
| GET | /cashed/outgoing/checks | شيكات صادرة مصروفة |
| GET | /archived/outgoing/checks | شيكات صادرة أرشفة |
| POST | /edit/outgoing/check | تعديل شيك صادر |
| POST | /delete/outgoing/check | حذف شيك صادر |
| POST | /cash/outgoing/check/from/box | صرف شيك صادر من صندوق |
| POST | /add/incoming/check | إضافة شيك وارد |
| POST | /cash/incoming/check/to/person | صرف شيك وارد لشخص |
| POST | /cash/incoming/check/to/box | صرف شيك وارد لصندوق |
| POST | /cancel/an/incoming/check | إلغاء شيك وارد |
| POST | /return/an/incoming/check | إرجاع شيك وارد |
| POST | /cash/an/incoming/check | صرف شيك وارد |
| POST | /show/check | عرض شيك |
| POST | /edit/incoming/check | تعديل شيك وارد |
| POST | /delete/incoming/check | حذف شيك وارد |
| GET | /not-cashed/incoming/checks | شيكات واردة غير مصروفة |
| GET | /cashed/to/person/incoming/checks | شيكات واردة مصروفة لشخص |
| GET | /cancelled/incoming/checks | شيكات واردة ملغاة |
| GET | /returned/incoming/checks | شيكات واردة مرتجعة |
| GET | /cashed/incoming/checks | شيكات واردة مصروفة |
| GET | /general/incoming/checks/data | بيانات الشيكات الواردة |
| GET | /cashed/to/box/incoming/checks | شيكات واردة مصروفة لصندوق |
| GET | /archived/incoming/checks | شيكات واردة أرشفة |

### Expenses and Financial Affairs (المصاريف والأمور المالية)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/asset | إضافة أصل |
| GET | /get/all/assets | كل الأصول |
| GET | /depreciate/all/assets | اهلاك كل الأصول |
| POST | /show/asset | عرض أصل |
| POST | /edit/asset | تعديل أصل |
| POST | /delete/asset | حذف أصل |
| POST | /depreciate/one/asset | اهلاك أصل واحد |
| GET | /get/all/asset/logs | سجلات الأصول |
| POST | /get/asset/logs | سجلات أصل |
| GET | /get/all/asset/logs/report | تقرير سجلات الأصول |
| POST | /store/expense | حفظ مصروف |
| GET | /get/all/expenses | كل المصروفات |
| POST | /show/expense | عرض مصروف |
| POST | /edit/expense | تعديل مصروف |
| POST | /store/destruction | حفظ إتلاف |
| GET | /get/all/destructions | كل الإتلافات |
| POST | /show/destruction | عرض إتلاف |
| POST | /store/treasury | إنشاء خزنة |
| GET | /get/all/treasuries | كل الخزن |
| POST | /cancel/treasury | إلغاء خزنة |
| POST | /store/file-box | إنشاء صندوق ملفات |
| GET | /all/file-boxes | كل صناديق الملفات |
| POST | /file-box/details | تفاصيل صندوق ملفات |
| POST | /cancel/file-box | إلغاء صندوق ملفات |
| POST | /store/file | حفظ ملف |
| POST | /delete/file | حذف ملف |
| GET | /get/all/files | كل الملفات |
| POST | /file/papers | أوراق الملف |
| POST | /store/picture | حفظ صورة |
| GET | /get/all/pictures | كل الصور |
| POST | /show/picture | عرض صورة |
| POST | /edit/picture | تعديل صورة |
| POST | /delete/picture | حذف صورة |
| POST | /store/paper | حفظ ورقة |
| GET | /get/all/papers | كل الأوراق |
| POST | /cancel/paper | إلغاء ورقة |
| POST | /get/paper/details | تفاصيل ورقة |
| POST | /edit/paper | تعديل ورقة |

### Goal Creation (صناعة الأهداف)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/goal | إضافة هدف |
| POST | /show/goal | عرض هدف |
| GET | /get/all/goals | كل الأهداف |
| POST | /cancel/goal | إلغاء هدف |
| POST | /transfer/goal | نقل هدف |
| POST | /edit/goal | تعديل هدف |
| POST | /delete/goal | حذف هدف |

### Mutual (أكثر من صلاحية واحدة)

**صلاحية واحدة من: Sales, Follow-up Section, Projects and Purchases Management, Purchasing Section**
| Method | Path | الوصف |
|--------|------|--------|
| GET | /all/products | كل المنتجات |

**صلاحية واحدة من: Boxes Section, Checks, Sales, Goal Creation**
| Method | Path | الوصف |
|--------|------|--------|
| GET | /get/shown/boxes | الصناديق المعروضة |

**صلاحية واحدة من: General Data, Debts, Checks, Maintenance, Follow-up Section, Goal Creation, Projects and Purchases Management**
| Method | Path | الوصف |
|--------|------|--------|
| GET | /all/customers | كل العملاء |

**صلاحية واحدة من: General Data, Checks, Debts, Maintenance, Follow-up Section, Goal Creation, Projects and Purchases Management, Purchasing Section**
| Method | Path | الوصف |
|--------|------|--------|
| GET | /all/sellers | كل البائعين |

**صلاحية واحدة من: Employees Section, Employee Tasks, Goal Creation**
| Method | Path | الوصف |
|--------|------|--------|
| GET | /employees | قائمة الموظفين |

**صلاحية واحدة من: Stock, Sales**
| Method | Path | الوصف |
|--------|------|--------|
| GET | /get/all/projects | كل المشاريع |

### Stock (قسم المخزون)
| Method | Path | الوصف |
|--------|------|--------|
| GET | /get/products/list | قائمة المنتجات |
| POST | /get/product/details | تفاصيل منتج |
| POST | /edit/product | تعديل منتج |
| POST | /add/product/to/closeouts | إضافة منتج للتصفيات |
| POST | /archive/closeout | أرشفة تصفية |
| GET | /get/unarchived/closeouts | تصفيات غير أرشفة |
| GET | /get/archived/closeouts | تصفيات أرشفة |
| POST | /add/combination | إضافة تركيب |
| GET | /get/all/combinations | كل التوليفات |
| POST | /search/products/by/name | بحث منتجات بالاسم |

### Purchasing Section (قسم الشراء)
| Method | Path | الوصف |
|--------|------|--------|
| POST | /add/bill | إضافة فاتورة |
| GET | /unfinished/bills | فواتير غير منتهية |
| POST | /change/product/status | تغيير حالة منتج |
| POST | /get/bill/details | تفاصيل فاتورة |
| GET | /unmatched/bills | فواتير غير مطابقة |
| GET | /securities/bills | فواتير أمانات |
| GET | /finished/bills | فواتير منتهية |
| GET | /archived/bills | فواتير أرشفة |
| POST | /deliver/one/product | تسليم منتج واحد |
| POST | /purchase/extra/products | شراء منتجات إضافية |
| POST | /purchase/new/price | شراء بسعر جديد |
| POST | /cancel/bill | إلغاء فاتورة |
| POST | /deliver/whole/bill | تسليم الفاتورة كاملة |
| POST | /bill/report | تقرير فاتورة |
| POST | /add/quantity/bill | إضافة كمية فاتورة |
| POST | /add/return/purchase | إضافة مرتجع شراء |
| GET | /get/pending/return/purchases | مرتجعات معلقة |
| GET | /get/delivered/return/purchases | مرتجعات مسلّمة |
| POST | /change/return/purchase/to/delivered | تغيير مرتجع إلى مسلّم |

---

## 6. ملاحظات ختامية

- **اليوزر العادي (العميل):** التعليقات في الكود تشير إلى "customers" لـ الطلبات والشراء الفوري. حالياً لا يوجد middleware يتحقق من `type === customer`، لذلك أي مستخدم مسجل يمكنه استدعاء هذه الـ endpoints (طلباتي، شراء فوري، إلخ).
- **الصلاحيات:** أسماء الصلاحيات في الـ middleware بالإنجليزي (name_en) كما في جدول permissions أو استجابة `GET /all/permissions`. أدمن يمر على كل الـ routes التي تعتمد على صلاحية؛ الموظف يحتاج أن تكون الصلاحية ممنوحة له في employee_permissions.

---

## جدول مرجعي سريع

| نوع المستخدم | القسم في الملف |
|--------------|-----------------|
| عام (بدون تسجيل دخول) | 1. Endpoints عامة (Public) |
| أي مستخدم مسجل | 2. أي مستخدم مسجل (Authenticated) |
| أدمن فقط | 3. أدمن فقط (Admin only) |
| موظف فقط | 4. موظف فقط (Employee only) |
| أدمن أو موظف بصلاحية محددة | 5. حسب الصلاحية (By permission) |

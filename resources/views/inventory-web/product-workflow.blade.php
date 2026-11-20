<section id="product-panel" hidden class="workflow-shell" aria-label="جرد المنتج المختار">
    <nav class="workflow {{ $portalUser->inventory_web_role === 'admin' ? '' : 'workflow-counter' }}" aria-label="خطوات جرد المنتج">
        <button data-workflow-step="0" class="active" aria-current="step"><span>1</span>البيانات الأصلية</button>
        <button data-workflow-step="1"><span>2</span>الجرد والتوزيع</button>
        @if($portalUser->inventory_web_role === 'admin')
        <button data-workflow-step="2"><span>3</span>الأسعار والألوان</button>
        <button data-workflow-step="3"><span>4</span>مراجعة واعتماد</button>
        <button data-workflow-step="4"><span>5</span>سند تحويل</button>
        @endif
        <button data-workflow-step="5"><span>{{ $portalUser->inventory_web_role === 'admin' ? 6 : 3 }}</span>النتيجة والسجل</button>
    </nav>
    <div class="intro-strip row between"><div><h2 id="workflow-title">البيانات الأصلية</h2><p id="workflow-description" class="muted small"></p></div><div class="row"><span id="workflow-status" class="pill">مسودة جرد</span><button id="close-product" class="quiet">إغلاق المنتج</button></div></div>
    <div class="workflow-content">
        <div class="product-hero"><img id="product-image" class="hero-photo" alt="صورة المنتج المختار"><div><h3 id="product-name"></h3><div id="product-meta" class="muted small"></div><div id="product-price-summary" class="price-line"></div></div></div>
        <div id="product-history" class="history-line"></div>
        <section data-product-stage="0"><div class="notice">هذه بيانات المنتج المسجلة. راجع الألوان والمقاسات، ثم انتقل إلى الجرد لتحديد كمية كل مكان.</div><div class="table-wrap"><table><thead id="source-head"></thead><tbody id="source-lines"></tbody></table></div></section>
        <section data-product-stage="1" hidden>
            <div class="row between stage-top"><p class="muted small">تعدّ كل لون ومقاس في المكانين. الخانة الفارغة لا تعتبر صفراً.</p><span class="pill" id="count-progress">بانتظار العدّ</span></div>
            <div class="table-wrap"><table><thead id="count-head"></thead><tbody id="count-lines"></tbody></table></div><div class="summary-bar" id="count-summary" aria-live="polite"></div>
            <div class="place-actions"><div><span class="place-label">المعرض</span><div class="row"><button data-save-place="showroom">حفظ مسودة المعرض</button><button data-submit-place="showroom" class="primary">إرسال جرد المعرض</button></div></div><div><span class="place-label">المستودع</span><div class="row"><button data-save-place="warehouse">حفظ مسودة المستودع</button><button data-submit-place="warehouse" class="primary">إرسال جرد المستودع</button></div></div></div>
            <p class="muted small stage-note">كل مكان يُحفظ باسمه وتاريخ عدّه. الأرصدة تتغيّر بعد اعتماد المسؤول.</p>
        </section>
        @if($portalUser->inventory_web_role === 'admin')
        <section data-product-stage="2" hidden>
            <h3>بيانات المنتج وأسعار المفرق والجملة</h3><p class="muted small">كل لون ومقاس يحتفظ بسعره. نقل البضاعة بين المكانين لا يغيّر أسعار البيع.</p>
            <div class="form-grid"><label>اسم المنتج<input id="edit-name"></label><label>المفرق ₪<input id="edit-retail" type="number" min="0" step="0.01"></label><label>الجملة ₪<input id="edit-wholesale" type="number" min="0" step="0.01"></label></div>
            <div class="table-wrap"><table><thead><tr><th>اللون / المقاس</th><th class="center">مفرق ₪</th><th class="center">جملة ₪</th><th>إجراء</th></tr></thead><tbody id="price-lines"></tbody></table></div><div class="actions"><button id="save-details" class="primary">حفظ البيانات والأسعار</button></div>
            <section class="subsection"><div class="row between"><h3>إضافة لون ومقاس</h3><span class="muted small">اللون الجديد يبدأ بصفر كمية</span></div><div class="form-grid"><label>المقاس<input id="new-size" placeholder="مثلاً: كبير أو موحّد"></label><label>اللون بالعربي<input id="new-color" placeholder="اسم اللون"></label><label>مفرق ₪<input id="variant-retail" type="number" min="0" step="0.01"></label><label>جملة ₪<input id="variant-wholesale" type="number" min="0" step="0.01"></label></div><button id="add-variant">إضافة لون ومقاس</button><p class="muted small stage-note">اللون المرتبط بحركات يُوقَف مع حفظ تاريخه بعد مراجعة رصيده، ولا تُحذف فواتيره.</p></section>
            <section class="subsection"><div class="row between"><h3>تعديل تكلفة المخزون</h3><span class="pill">صلاحية المسؤول</span></div><p class="muted small">تعديل مستقل بسبب واضح ومراجعة أثر القيمة. الرصيد غير الموجب يُسجّل كتكلفة مرجعية.</p><div class="form-grid"><label>الصنف<select id="cost-key"></select></label><label>تكلفة الوحدة ₪<input id="new-cost" type="number" min="0.000001" step="0.000001"></label><label>السبب<input id="cost-reason" maxlength="120" placeholder="سبب تعديل التكلفة"></label></div><div class="row"><button id="preview-cost">معاينة الأثر</button><button id="save-cost" class="primary" disabled>اعتماد تعديل التكلفة</button></div><div id="cost-preview" class="notice" hidden></div></section>
            <section class="photo-panel"><h3>صورة عامة للمنتج، وصورة خاصة لكل لون</h3><div class="photo-layout"><div><img id="image-preview" class="image-preview" alt="صورة المنتج أو اللون المختار"><span class="muted small">صورة اللون تظهر قبل الصورة العامة</span></div><div><label>الصورة المطلوبة<select id="image-key"></select></label><label>صورة جديدة<input id="image-file" type="file" accept="image/jpeg,image/png,image/webp"></label><button id="save-image">حفظ الصورة</button><p class="muted small stage-note">تغيير الصورة العامة يحافظ على صور الألوان الخاصة.</p></div></div></section>
        </section>
        <section data-product-stage="3" hidden><div id="approval-lines"></div><div class="notice">راجع مجموع المعرض والمستودع والفروقات. أي زيادة فعلية تحتاج تكلفة معتمدة؛ توزيع نفس الإجمالي لا ينشئ شراءً جديداً.</div><label>سبب الاعتماد<input id="approval-reason" value="جرد فعلي وتحديد رصيد المعرض والمستودع" maxlength="120"></label><div class="row actions"><button id="approve" class="primary">اعتماد جرد المكانين</button><span class="muted small" id="approval-status"></span></div></section>
        @endif
        <section data-product-stage="5" hidden><div class="summary-bar" id="result-summary"></div><div class="table-wrap"><table><thead><tr><th>اللون / المقاس</th><th class="center">المعرض الآن</th><th class="center">المستودع الآن</th><th class="center">الإجمالي</th><th>حالة التوزيع</th></tr></thead><tbody id="result-lines"></tbody></table></div><div id="result-status" class="notice"></div>@if($portalUser->inventory_web_role === 'admin')<button data-view="documents">فتح سجل الجرد والتحويل والتكلفة</button>@endif</section>
    </div>
    <footer class="workflow-footer row between"><span id="workflow-hint" class="muted small">الإرسال للمراجعة مستقل لكل مكان</span><div class="row"><button id="workflow-previous">السابق</button><button id="workflow-next" class="primary">ابدأ الجرد</button></div></footer>
</section>

# Insight BOS — ملف التسليم بين الجلسات

اقرأ هذا الملف أولاً في أي جلسة عمل جديدة. المواصفات الكاملة (مع القرارات والتعليقات) في المستند:
https://claude.ai/artifact/2VxciFCPhhQuCPQFdS8hZT

## الحالة الحالية (2026-11-28)

- **المرحلة 1 منتهية ومنشورة** على https://test.insightpromedia.com (Plesk، PHP 8.3، Laravel Toolkit يسحب فرع `deploy`).
- CI: كل دفعة إلى `main` ← GitHub Actions يشغّل الاختبارات ← ينشئ فرع `deploy` (vendor + public/build). **لا تعدّل `deploy` يدوياً.**
- بيئة Claude السحابية لا تصل إلى Packagist: لا يمكن `composer install` محلياً. افحص PHP بـ `php -l`، وابنِ الواجهة بـ `npm run build`، واعتمد على CI للاختبارات (`gh run watch`).
- ملفات Laravel الافتراضية غير المعدّلة غير متتبعة في المستودع؛ `scripts/restore-skeleton.sh` يعيدها (يُشغّل في CI).

## التقنية والأعراف

- Laravel 13 (PHP ^8.3، Composer platform مثبت على 8.3.0) + Inertia v3 + Vue 3 (JavaScript، `<script setup>`) + Tailwind 4. لا TypeScript ولا Wayfinder ولا Ziggy: الروابط نصوص ثابتة (`/admin/users`).
- عربي RTL. الهوية: `navy #00027C`، `brand #0082F6`، `sky #00BEF2`، `plum #481C4E`، `orchid #864690`، `sand #F1D87F`؛ خط Madani Arabic (`public/fonts`). الشعارات في `public/images` (`logo-white.svg` و`favicon.svg` تُولَّد بـ `scripts/brand-assets.mjs`).
- مكونات الواجهة في `resources/js/components`: Btn, Field, Toggle, Modal, PageHeader, Badge, Pagination, EmptyState, PermissionMatrix. فئات CSS: `.card`, `.field-input`, `.field-label`, `.field-error`, `.tbl`, `.brand-gradient`, `.num`.
- الصفحات: `resources/js/pages/<module>/<Page>.vue`، كل صفحة تلف نفسها بـ `AppLayout`. القائمة الجانبية في `resources/js/lib/navigation.js` (أزل `soon: true` وأضف `href` عند تفعيل موديول).
- **الصلاحيات:** `config/permissions.php` يعرّف `module.action`. كل شخص له منح مباشرة (`user_permissions`) بنطاق `own | assigned | all`. استخدم `can:module.action` على المسارات، و`$user->permissionScope('crm.view')` لتصفية السجلات حسب النطاق. المسمى الوظيفي لا يمنح صلاحية. `is_super_admin` يتجاوز الكل.
- سجل التدقيق: `App\Services\ActivityLogger::log($action, $subject, $description, $changes)` لكل إنشاء/تعديل/حذف.
- الأرقام: `App\Services\NumberSequencer::next('quote'|'invoice'|'receipt'|'expense'|'contract')` ← مثل `QT-2026-0001`. الرقم يُمنح عند الإصدار، والمستند يُلغى ولا يُحذف.
- العملات: USD أساسية، SYP وTRY بسعر يومي يدوي. `App\Services\ExchangeRates::rateFor($code, $date)` (احفظ `currency` و`fx_rate` مع كل مستند مالي).
- الضريبة: `service_categories.tax_rate` (0% حالياً) تُنسخ إلى البند لحظة الإصدار.
- التخزين: مزود واحد نشط في `storage_connections` (google_drive | onedrive | dropbox | wd_mycloud)، الواجهة `App\Services\Storage\StorageProvider`. الملفات لا تُخزَّن على الخادم.
- المبالغ `DECIMAL(14,2)`، علاقات بالمعرّف لا بالاسم، Soft deletes، القيم المحسوبة لا تُخزَّن.
- كل ميزة جديدة: اختبارات Feature في `tests/Feature`، وseeders idempotent.

## نطاق المرحلة 2 (العملاء والمشاريع والعقود + ربط التخزين)

**CRM**
- العملاء المحتملون (leads): مصدر، مرحلة، تقييم، قيمة متوقعة، مسؤول؛ لوحة Kanban بالمراحل؛ كشف التكرار ودمج السجلات.
- العملاء (clients) وجهات الاتصال (contacts)، ملف العميل 360°.
- الفرص (opportunities): مراحل، احتمالية، تاريخ إغلاق متوقع، سجل تنقّل المراحل، **سبب خسارة إلزامي**، عرض توقعات موزون.
- الأنشطة (crm_activities) مع تذكيرات.
- معالج تحويل المحتمل ← عميل + جهة اتصال + فرصة.
- كتالوج الخدمات (`services` تحت `service_categories`).
- عروض الأسعار (quotes + quote_items) ببنود وضريبة حسب التصنيف، PDF بهوية الشركة، قبول العرض ← مشروع.
- العقود (contracts + contract_schedules): مشروع أو شهري (retainer)، جدول دفعات/مراحل (الفوترة نفسها في المرحلة 4).

**المشاريع**
- projects (عميل، عرض/عقد، مدير، نوع، ميزانية، تواريخ، حالة)، project_members، milestones، project_risks، project_issues، change_requests (أثر تكلفة/وقت + اعتماد).
- قوالب مشاريع حسب النوع (هوية، حملة، إنتاج فيديو، موقع، معرض).
- مساحة المشروع بتبويبات (الملخص، المراحل، المخاطر والمشكلات، طلبات التغيير، الملفات). المهام نفسها في المرحلة 3أ (تسلسل: عميل ← مشروع ← قائمة مهام ← مهمة).

**التخزين**
- ربط فعلي للمزودين الأربعة (OAuth لـ Google Drive/OneDrive/Dropbox، WebDAV لـ WD My Cloud)، اختبار الاتصال، مجلدات تلقائية لكل عميل ومشروع، جدول `attachments` متعدد الأشكال، فتح الملف في الخدمة نفسها، إعادة المحاولة عند الفشل.
- يحتاج من المستخدم: إنشاء تطبيقات OAuth (Google Cloud / Microsoft Entra / Dropbox) ووضع المفاتيح في `.env` على الخادم.

**مؤجل (لا تبنِه الآن):** بوابة العميل (المرحلة 6)، الرواتب، المخزن والإهلاك المتعدد، الهيكل التنظيمي، SLA، ربط البريد/VoIP.

## اقتراح ترتيب العمل في المرحلة 2

1. الجداول والنماذج والاختبارات لـ CRM.
2. واجهات CRM (Kanban، العملاء، الفرص، الأنشطة، التحويل).
3. الخدمات وعروض الأسعار + PDF.
4. العقود.
5. المشاريع ومساحة المشروع.
6. ربط التخزين والمرفقات.

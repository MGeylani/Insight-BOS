# Insight BOS — ملف التسليم بين الجلسات

اقرأ هذا الملف أولاً في أي جلسة عمل جديدة. المواصفات الكاملة (مع القرارات والتعليقات) في المستند:
https://claude.ai/artifact/2VxciFCPhhQuCPQFdS8hZT

## الحالة الحالية (2026-11-28)

- **المرحلة 1 منتهية ومنشورة** على https://test.insightpromedia.com (Plesk، PHP 8.3، Laravel Toolkit يسحب فرع `deploy`).
- **المرحلة 2 منتهية ومدموجة في `main`** (CRM، عروض الأسعار، العقود، المشاريع، التخزين).
- **المرحلة 3أ (المهام) قيد البناء، فرع لكل خطوة، كل فرع مبني على الذي قبله:**
  1. `phase-3a-tasks` ← محرك المهام ✅
  2. `phase-3a-collab` ← المحرر الغني والمرفقات والتعليقات والإشعارات ✅
  3. `phase-3a-views` ← العروض (قائمة، لوحة، تقويم، جدول) والعروض المحفوظة والإجراءات الجماعية
  4. `phase-3a-mywork` ← «عملي» والوارد والتذكيرات وتتبع الوقت
  إن انقطع العمل: ابدأ من آخر فرع موجود على GitHub، واقرأ قسمه أدناه. المستخدم يدمجها بالترتيب عبر PR ثم يشغّل `migrate --force` و`db:seed --force` و`config:cache`.
- CI: كل دفعة إلى `main` أو إلى فرع `phase-*` ← GitHub Actions يشغّل الاختبارات. فقط `main` ينشئ فرع `deploy` (vendor + public/build). **لا تعدّل `deploy` يدوياً.**
- أسلوب العمل المعتمد: اعمل على فرع `phase-N-...`، ادفع، انتظر CI أخضر. **الدفع المباشر إلى `main` ممنوع من جلسة Claude**: المستخدم يدمج عبر Pull Request (أعطه رابط `https://github.com/MGeylani/Insight-BOS/compare/main...<branch>`)، ثم يشغّل `migrate --force` يدوياً من Laravel Toolkit إن لزم.
- بيئة Claude السحابية لا تصل إلى Packagist: لا يمكن `composer install` محلياً. افحص PHP بـ `php -l`، وابنِ الواجهة بـ `npm ci && npm run build`، واعتمد على CI للاختبارات.
- `gh` غير مسجّل الدخول وإنشاء PR عبر أدوات GitHub مرفوض (403). لمتابعة CI استخدم API العامة بـ curl:
  `curl -s "https://api.github.com/repos/MGeylani/Insight-BOS/actions/runs?branch=<branch>&per_page=1"` ثم السجلات من `.../actions/runs/<id>/logs` (ملف zip).
- ملفات Laravel الافتراضية غير المعدّلة غير متتبعة في المستودع؛ `scripts/restore-skeleton.sh` يعيدها (يُشغّل في CI).
- ملاحظة اختبارات: `assertInertia()->where()` يقارن بصرامة بعد JSON، فالقيمة `800.0` تصل `800` (int). قارن بأعداد صحيحة.

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
- التخزين: مزود واحد نشط في `storage_connections` (google_drive | onedrive | dropbox | wd_mycloud)، الواجهة `App\Services\Storage\StorageProvider`. الملفات لا تُخزَّن على الخادم. المرفقات عبر `App\Services\Storage\Attachments` (انظر الخطوة 6).
- المبالغ `DECIMAL(14,2)`، علاقات بالمعرّف لا بالاسم، Soft deletes، القيم المحسوبة لا تُخزَّن.
- كل ميزة جديدة: اختبارات Feature في `tests/Feature`، وseeders idempotent.

## ما بُني في CRM (المرحلة 2، الخطوتان 1–2)

- المفردات في `config/crm.php`: مصادر، مراحل المحتمل، تقييم (hot/warm/cold)، مراحل الفرص مع احتمالية افتراضية، أسباب الخسارة، أنواع الأنشطة، أنواع العملاء.
- الجداول (`2026_11_28_000100_create_crm_tables.php`): `clients`، `contacts`، `leads`، `opportunities`، `opportunity_stage_changes`، `crm_activities` (morph: lead|client|opportunity)، `notifications`.
- morph map في `AppServiceProvider`: `lead`، `client`، `opportunity`، `quote`، `service`، `contract`، `project`.
- `App\Models\Concerns\VisibleByScope`: `Model::visibleTo($user, 'crm.view')` و`$model->isVisibleTo($user, 'crm.edit')`؛ own/assigned = `owner_id` أو `created_by` (الأنشطة: `user_id` أو `created_by`). استخدمه لموديولات المشاريع أيضاً مع `scopeOwnerColumns()`.
- `NormalizesPhone`: يحفظ آخر 9 أرقام في `phone_normalized` لكشف التكرار.
- الخدمات في `app/Services/Crm`: `OpportunityStages::move()` (المصدر الوحيد لتغيير المرحلة؛ يسجل التاريخ ويُلزم سبب الخسارة)، `DuplicateFinder::find()`، `LeadMerger::merge()`، `LeadConverter::convert()`.
- التذكيرات: الأمر `crm:reminders` مجدول كل 5 دقائق ← إشعار database ← جرس التنبيهات في الترويسة (`/notifications`).
- الواجهات: `resources/js/pages/crm/*` ومكونات `resources/js/components/crm/*` (CrmTabs، ActivityFeed قابل لإعادة الاستخدام لأي سجل، StageModal، نماذج الإدخال). `lib/crm.js` فيه `money()` و`usd()` والألوان.
- الحذف = أرشفة (soft delete). سجل التدقيق لكل إنشاء/تعديل/نقل مرحلة/دمج/تحويل.

## ما بُني في الخدمات وعروض الأسعار (المرحلة 2، الخطوة 3)

- الجداول (`2026_11_28_000200_create_quotes_tables.php`): `services` (تحت `service_categories`، سعر ووحدة وعملة افتراضية)، `quotes`، `quote_items`. morph map: `quote`، `service`.
- الكتالوج `/crm/services`: العرض بـ `crm.view`، الإضافة/التعديل/الأرشفة بـ `settings.manage`. الوحدات المقترحة في `config/crm.php` (`service_units`)، والحالات في `quote_statuses`.
- `App\Services\Sales\Quotes` هو المصدر الوحيد لتغيير البنود والحالة: `saveDraft` (ضريبة البند = نسبة تصنيفه الحالية)، `issue` (رقم `QT-…` + تثبيت `fx_rate` ونسب الضريبة + تحديث قيمة الفرصة ونقلها من discovery/proposal إلى `sent`)، `accept` (الفرصة ← `won`)، `reject` (سبب إلزامي، لا يغيّر الفرصة)، `cancel` (للمُرسل/المرفوض)، `duplicate` (نسخة مسودة بـ `source_quote_id`). الصادر لا يُعدّل ولا يُحذف؛ المسودة فقط تُحذف (soft).
- `expired` حالة محسوبة (مُرسل وتجاوز `valid_until`)، لا تُخزَّن. المجاميع محسوبة في `QuoteItem`/`Quote::totals()` بتقريب لكل بند، ونفس المنطق في `quotes/Edit.vue`.
- الإعدادات الافتراضية (مدة الصلاحية، الشروط) في صفحة بيانات الشركة ← `PUT /settings/quote-defaults` (مفاتيح `quotes.validity_days`، `quotes.default_terms`).
- PDF: `App\Services\Pdf\PdfRenderer` (mPDF ^8.2، يدعم تشكيل العربية وRTL) قابل لإعادة الاستخدام للعقود والفواتير. الخط `resources/fonts/madani-400|700.ttf` (محوّل من woff2)، الشعار `resources/pdf/logo.png` (مولّد من `public/images/logo.svg` بـ `rsvg-convert -w 800`). القالب `resources/views/pdf/quote.php` (PHP عادي لا Blade). المسودة بعلامة مائية «مسودة» والملغى «ملغى». الرابط `/crm/quotes/{id}/pdf` (`?download=1` للتنزيل).
- قبول العرض ← مشروع: زر «تحويل إلى مشروع» في `quotes/Show.vue` (العرض المقبول فقط)، و`quotes.project_id` يشير إلى المشروع (بلا مفتاح أجنبي).
- ملاحظة بيئة: تشغيل كود مستنسخ من GitHub محلياً (مثل mPDF) محجوب في الجلسة، فاختبار PDF يتم في CI فقط.

## ما بُني في العقود (المرحلة 2، الخطوة 4)

- الجداول (`2026_11_28_000300_create_contracts_tables.php`): `contracts` و`contract_schedules`. morph map: `contract`. المفردات في `config/crm.php` (`contract_types`، `contract_statuses`، `contract_schedule_statuses`، `billing_timings`). الصلاحيات: `crm.*` مثل العروض.
- نوعان: **مشروع** (`value` + جدول يدوي من دفعات `payment` ومراحل `milestone`؛ عند الإصدار يجب أن يساوي مجموع الدفعات القيمة) و**شهري retainer** (`monthly_fee` × `term_months`، دفعاته تُولَّد تلقائياً شهراً بشهر بـ `period_start/end`، تستحق أول الفترة `advance` أو آخرها `arrears`؛ `end_date` محسوب؛ الأشهر بلا تجاوز: 31 يناير + شهر = آخر فبراير). المراحل اليدوية مسموحة للنوعين.
- `App\Services\Sales\Contracts` المصدر الوحيد: `saveDraft`، `issue` (رقم `CTR-…` + تثبيت `fx_rate`)، `sign` (تاريخ + اسم الموقّع ← `active`، والفرصة ← `won`)، `complete`، `terminate` (تاريخ وسبب إلزامي؛ تُلغى البنود المعلّقة التي تستحق بعد تاريخ الفسخ)، `cancel` (للمُرسل غير الموقّع)، `renew` (retainer: مدة إضافية بنفس الطول)، `duplicate`، `setMilestone` (المراحل فقط تُنجز يدوياً؛ الدفعات تُسوّى بالفواتير)، `fromQuote` (تعبئة من عرض: العميل، القيمة، نطاق العمل من البنود).
- حالات محسوبة لا تُخزَّن: العقد `expired` (ساري وتجاوز `end_date`)، والبند `overdue` (معلّق وتجاوز `due_date`). قيمة العقد: `value` للمشروع، ومجموع الدفعات غير الملغاة للشهري (تشمل التجديدات).
- المبالغ هي المبالغ المتفق عليها؛ الضريبة تُطبَّق عند الفوترة (المرحلة 4). **للمرحلة 4:** `contract_schedules.invoice_id` جاهز بلا مفتاح أجنبي؛ الفوترة تُنشئ فاتورة من بند دفعة معلّق ثم تضع `invoice_id` وتجعل الحالة `done` عند السداد. ملخص «دفعات مستحقة خلال 30 يوماً» في صفحة العقود يستثني البنود ذات `invoice_id`.
- «إنشاء مشروع» من العقد الصادر أو الساري (`contracts/Show.vue`)، و`contracts.project_id` يشير إلى المشروع (بلا مفتاح أجنبي).
- PDF: `ContractPdf` + `resources/views/pdf/contract.php` (طرفان، نطاق العمل، المدة والمقابل، جدول الدفعات، المراحل، الشروط، خانتا توقيع). علامة مائية: مسودة / ملغى / مفسوخ. الرابط `/crm/contracts/{id}/pdf`.
- الشروط الافتراضية لكل نوع في صفحة بيانات الشركة ← `PUT /settings/contract-defaults` (`contracts.terms_project`، `contracts.terms_retainer`).
- الواجهات: `pages/crm/contracts/{Index,Edit,Show}.vue`، `components/crm/ContractList.vue` (في صفحات العميل والفرصة والعرض)، زر «إنشاء عقد» في صفحة العرض المُرسل/المقبول، تبويب «العقود» في `CrmTabs`.
- النسخة الموقّعة من العقد تُرفع من بطاقة «النسخة الموقّعة والمرفقات» في صفحة العقد.

## ما بُني في المشاريع (المرحلة 2، الخطوة 5)

- الجداول (`2026_11_28_000400_create_projects_tables.php`): `projects`، `project_members` (pivot بدور نصي)، `project_milestones`، `project_risks`، `project_issues`، `change_requests`. الـ migration يضيف تسلسل الترقيم `project` (`PRJ-2026-0001`) إن لم يوجد، والـ seeder أيضاً.
- المفردات والقوالب في `config/projects.php`: الأنواع (هوية، حملة، فيديو، موقع، معرض، سوشال ميديا، أخرى)، الحالات (`planning | active | on_hold | completed | cancelled`)، أدوار الفريق المقترحة، تصنيفات وحالات المخاطر، خطورة وحالات المشكلات، مصادر وحالات طلبات التغيير، و**قوالب لكل نوع**: مراحل بـ `offset_days` من تاريخ البدء + مخاطر معتادة.
- الصلاحيات `projects.*` الموجودة في الكتالوج. النطاق في `Project::scopeVisibleTo()/isVisibleTo()` (لا يستخدم `VisibleByScope`): `own` = المدير أو المنشئ، `assigned` = ذلك + أعضاء الفريق، `all` = الكل. الميزانية تُعدَّل بـ `projects.manage_budget` فقط (بدونها تُنسخ من العرض/العقد ولا تُعدَّل)، وتُعرض لمن لديه `manage_budget` أو `approve_changes` أو `finance.view`. اعتماد/رفض طلبات التغيير بـ `projects.approve_changes`.
- `App\Services\Projects\Projects` المصدر الوحيد: `create` (رقم + قالب + فريق + ربط العرض/العقد)، `update` (يضبط `completed_at` عند تغيّر الحالة)، `syncMembers` (المدير لا يُضاف عضواً)، `applyTemplate`، `setMilestone`، `requestChange` (تسلسل `CR-n` داخل المشروع)، `approveChange` (الميزانية += أثر التكلفة، موعد التسليم += أثر الأيام، ولقطة `budget_before/due_before`)، `rejectChange` (سبب إلزامي)، `fromQuote`/`fromContract` (تعبئة النموذج).
- قواعد الربط: العرض يجب أن يكون **مقبولاً**، والعقد **صادراً أو سارياً**، وكلاهما للعميل نفسه وغير مرتبط بمشروع آخر. المشروع لا ينتقل لعميل آخر.
- قيم محسوبة لا تُخزَّن: المشروع `overdue` (مفتوح وتجاوز `due_date`)، المرحلة `overdue`، التقدم = المراحل المنجزة ÷ الكل، درجة الخطر = الاحتمالية × الأثر (1–9؛ 6+ مرتفع، 3–4 متوسط).
- المسارات `/projects` (`ProjectController` + `ProjectItemController` للمراحل والمخاطر والمشكلات وطلبات التغيير؛ العنصر الفرعي يُتحقق أنه يتبع المشروع وإلا 404).
- الواجهات: `pages/projects/{Index,Form,Show}.vue`؛ مساحة المشروع بتبويبات (الملخص، المراحل، المخاطر والمشكلات، طلبات التغيير، الملفات) والتبويب يُحفظ في `#hash`. المكونات في `components/projects/*` (`MilestonesPanel`، `RisksIssuesPanel`، `ChangesPanel`، `ProjectList` لصفحة العميل). الألوان في `lib/projects.js`. عناصر المساحة تُرسل بـ `preserveState` كي لا يضيع التبويب.
- `authorizeRecord()` انتقل إلى `App\Http\Controllers\Controller` ليستخدمه أي موديول.
- **للمرحلة 3أ (المهام):** المهام تتبع `project_id` و`project_milestone_id`؛ عندها يُحسب تقدم المرحلة من مهامها بدل الإنجاز اليدوي.

## ما بُني في التخزين والمرفقات (المرحلة 2، الخطوة 6)

- الجداول (`2026_11_28_000500_create_attachments_tables.php`): `attachments` (morph `attachable`، المزود، `external_id`، الرابط، الحالة `pending | uploaded | failed`، الخطأ، عدد المحاولات، `temp_path`) و`storage_folders` (معرّفات مجلدات المزود لكل اتصال ومفتاح: `root`، `client:5`، `client:5:contracts`، `client:5:quotes`، `project:3`).
- المشغّلات في `app/Services/Storage/Drivers`: `Driver` (أساس: مهلة، تحويل الأخطاء إلى `StorageException` مع `status`، `cleanName`) ← `OAuthDriver` (رابط الموافقة، تبادل الرمز، تجديد الرمز تلقائياً وحفظه مشفّراً) ← `GoogleDrive` (نطاق `drive.file`، رفع resumable، الحذف = سلة المحذوفات، يدعم Shared Drives)، `OneDrive` (Graph، جلسة رفع بأجزاء 10MiB، `conflictBehavior=rename`)، `Dropbox` (مسارات، رفع مباشر حتى 140MiB ثم upload session، ترويسة `Dropbox-API-Arg` بـ ASCII). و`WebDav` (Basic auth، MKCOL/PUT/DELETE، يتجنب الكتابة فوق ملف بنفس الاسم بإضافة «(2)»، والفتح بالبث عبر BOS لأن الجهاز بلا عارض ويب).
- الواجهة `StorageProvider`: `test()` (يعيد اسم الحساب)، `rootFolder()`، `ensureChild($parentId, $name)`، `upload($localPath, $name, $mime, $folderId)`، `delete($externalId)`. `Providers::driver($connection)` يبني المشغّل؛ `Providers::forUi()` لصفحة الإعدادات (هل المفاتيح موجودة، رابط إعادة التوجيه).
- `StorageManager::folderFor($connection, $record)` يبني الهيكل ويتذكره: `Insight BOS/العملاء/<اسم العميل #id>/{العقود، عروض الأسعار، المشاريع/<PRJ-… · الاسم>}`. المجلد يُتذكر لكل سجل، فتغيير الاسم لا ينشئ مجلداً جديداً. إن ردّ المزود بـ 404 تُنسى المجلدات وتُنشأ في المحاولة التالية.
- `Attachments` المصدر الوحيد: `store` (يتحقق من الربط والنوع والحجم، نسخة مؤقتة على القرص `local` في `attachment-uploads/`، صف `pending`، محاولة فورية)، `attempt` (نجاح ← تُحذف النسخة المؤقتة؛ فشل ← `failed` + الخطأ + عداد المحاولات)، `delete` (من المزود ثم soft delete)، `retryFailed` (الأمر `storage:retry` كل 10 دقائق، حتى `Attachment::MAX_AUTO_ATTEMPTS` = 5، وزر «إعادة المحاولة» بلا حد)، `payload($record, $user)` لكل صفحة.
- الأنواع التي تقبل مرفقات في `Attachments::TYPES`: `client`، `project`، `contract`، `quote` (أضف غيرها هنا + فرعاً في `StorageManager::folderFor`). الصلاحيات تتبع السجل نفسه: العرض/الفتح بـ `<module>.view`، الرفع والحذف وإعادة المحاولة بـ `<module>.edit` مع نطاق السجل.
- المسارات: `POST /attachments` (`type`، `id`، `files[]`)، `GET /attachments/{id}/open` (تحويل إلى رابط المزود، أو بث لـ WebDAV)، `POST /attachments/{id}/retry`، `DELETE /attachments/{id}`. الإعدادات: `/settings/storage/{connect,callback}/{provider}` (state في الجلسة)، `POST /settings/storage/{webdav,test,disconnect,retry}`. الفصل يمسح الرموز ومجلدات الذاكرة (الملفات تبقى في الخدمة، وإعادة الربط تجد المجلدات بالاسم).
- المفاتيح في `config/services.php` (`google_drive`، `onedrive` + `tenant`، `dropbox`) من `.env` (انظر `.env.example` و`docs/DEPLOY.md`).
- الواجهة: `components/AttachmentsPanel.vue` (سحب وإفلات، عدة ملفات، شريط تقدم، فتح، إعادة محاولة، حذف) في: تبويب «الملفات» بالمشروع، صفحة العميل، صفحة العقد («النسخة الموقّعة والمرفقات»)، صفحة العرض. `settings/Storage.vue` فيه الربط والاختبار والفصل ونموذج WebDAV وعدد الملفات المعلّقة.
- الاختبارات: `StorageDriversTest` (كل مشغّل مقابل API محاكاة بـ `Http::fake`) و`AttachmentTest` (المسار الكامل مقابل خادم WebDAV محاكى + ربط OAuth).

## ما بُني في المهام (المرحلة 3أ، الخطوة 1: `phase-3a-tasks`)

- الجداول (`2026_11_29_000100_create_tasks_tables.php`): أعمدة في `clients` (`task_prefix` فريد، `task_seq`، `is_internal`)، `status_schemes` + `task_statuses` (مجموعة: `not_started | active | done | closed`)، `task_lists` (عميل، مشروع اختياري، مرحلة اختيارية، مخطط حالات خاص بها)، `tasks` (القائمة الأساسية `task_list_id`، ومنسوخ منها `client_id`/`project_id`، `parent_id` + `depth` 0–2، `seq` لكل عميل، `description` JSON + `description_text`، `start_at`/`due_at` مع `*_has_time`، `time_estimate_min`، `position` كسري، `completed_at`)، `task_list_links` (المهمة في قوائم إضافية)، `task_assignees`، `task_watchers`، `tags` + `task_tag`، `checklists` + `checklist_items` (متداخلة بـ `parent_id`). morph map: `task`، `task_list`.
- المفردات في `config/tasks.php` (الأولويات، مجموعات الحالات، قالبا الحالات «قالب الوكالة» و«بسيط»، العميل الداخلي). `TaskSeeder` (ضمن `DatabaseSeeder`) يُنشئ قالبي الحالات والعميل الداخلي «Insight Media» ببادئة `INS`.
- الخدمات في `app/Services/Tasks`:
  - `TaskLists`: `internalClient()`، `prefixFor()` (تُولَّد من الحروف اللاتينية في الاسم أو من أوائل الكلمات العربية، وتُضمن فرادتها)، `nextSeq()` (قفل صف العميل)، `copyScheme()`، `create()`، `createForProject()` (قائمة لكل مرحلة من القالب، أو «المهام» بلا قالب؛ يُستدعى من `Projects::create`)، `defaultFor()` («مهام عامة» عند الإضافة على مستوى العميل)، `update()`، `delete()` (القائمة الفارغة فقط)، `saveStatuses()` (يستبدل الحالات وينقل مهام المحذوفة: `move_to[oldId] = id | "new:<index>"`).
  - `Tasks` المصدر الوحيد لتغيير المهام: `create`، `update` (حقول جزئية، يكتب سجل النشاط بوصف عربي)، `syncPeople` (المسؤول الجديد يصبح متابعاً)، `syncTags`، `moveToList` (العميل نفسه فقط، ومعها فرعياتها، والحالة تُطابق بالاسم ثم بالمجموعة)، `addToList`/`removeFromList`، `setParent`، `reorder`، `archive`/`restore` (مع الفرعيات)، `progress()` (فرعيات + بنود التحقق)، `syncMilestone()`.
  - `Checklists`، `TaskQuery` (الموقع + الفلاتر، يستخدمه كل عرض)، `TaskPresenter` (أشكال البيانات للواجهة + شجرة المواقع).
- **سجل النشاط** = `activity_log` حيث `subject_type = 'task'` (لا جدول منفصل).
- **الإنجاز**: المهمة مكتملة ما دامت حالتها في مجموعة done/closed (`completed_at`). المرحلة التي لها مهام تصبح `done` تلقائياً حين تكتمل كل مهامها الرئيسية وتعود `pending` إن أعيد فتح إحداها. `ProjectMilestone::progress()` من المهام (أو يدوي بلا مهام)، و`Project::progress()` = متوسط المراحل (أو نسبة المهام إن لم توجد مراحل).
- **الصلاحيات** (`Task::scopeVisibleTo/isVisibleTo`): `own` = أنشأها أو مسندة إليه؛ `assigned` = ذلك + يتابعها + مهام المشاريع التي يديرها أو عضو فيها؛ `all`. تغيير المسؤولين يحتاج `tasks.assign`. القوائم (`TaskList`): المرئية = قوائم العميل الداخلي للجميع + ما أنشأه + ما فيه مهمة يراها (+ قوائم مشاريعه في `assigned`). إدارة القائمة (`canManage`): `tasks.edit` بنطاق `all`، أو منشئها، أو مدير مشروعها.
- المسارات تحت `/tasks` (انظر `routes/web.php`): `GET /tasks?list=|project=|client=` + الفلاتر، `GET /tasks/{id}`، `POST /tasks` (`task_list_id` أو `client_id` أو `parent_id`، وبدونها ← قائمة Insight Media العامة)، `PATCH /tasks/{id}` (أي حقل: `status_id`، `priority`، `start_date/start_time`، `due_date/due_time`، `time_estimate_min`، `assignees[]`، `watchers[]`، `tags[]`، `project_milestone_id`، `description`)، `move`، `lists` (ربط/فك)، `parent`، `reorder`، `watch`، `restore`، قوائم التحقق، `/tasks/lists/*` و`/tasks/lists/{id}/statuses`، `POST /tasks/tags`. الإعدادات: `/settings/tasks` (الوسوم، قوالب الحالات، بادئة العمل الداخلي).
- الواجهة: `pages/tasks/{Index,Show}.vue`، `pages/settings/Tasks.vue`، المكونات في `components/tasks/*` (`TaskTree`، `TaskListView`، `TaskRow` متداخل، `QuickAdd`، `StatusPill`، `PeoplePicker`، `TagPicker`، `DueInput`، `PriorityPicker`، `StatusEditor`، `ListFormModal`، `NewTaskModal`، `TaskFilters`، `ChecklistPanel`)، و`components/Dropdown.vue` (قائمة منبثقة في `<body>`) و`components/Avatar.vue`. `lib/tasks.js` (تواريخ محلية `YYYY-MM-DD[THH:mm]`، `parseMinutes`، `buildTree`، `patchTask`).
- بادئة معرّف المهام قابلة للتعديل في نموذج العميل (CRM). مساحة المشروع تعرض قوائم المهام وتقدّم المراحل من المهام.
- الاختبارات: `tests/Feature/TaskTest.php`.

## ما بُني في التعاون على المهام (المرحلة 3أ، الخطوة 2: `phase-3a-collab`)

- **المحرر الغني**: Tiptap v3 (`@tiptap/vue-3`، `starter-kit`، `extension-mention`، `extension-image`، `extension-list` لقوائم المهام، `extensions` للـ Placeholder). المكوّن `components/RichEditor.vue` (شريط أدوات، إشارات @ بقائمة منبثقة خاصة بلا tippy، لصق/سحب صور، Ctrl+Enter = إرسال) و`components/RichView.vue` (عرض للقراءة فقط بـ `generateHTML`، بلا محرر). التنسيق في `resources/css/app.css` (`.rich-content`).
- الوثيقة تُحفظ JSON. `App\Support\RichText` ينظّفها على الخادم (عُقد وعلامات معروفة فقط، الروابط http/https/mailto، الصور فقط من `/attachments/{id}/inline`)، ويستخرج النص (`toPlain`) والإشارات (`mentions`) والصور (`images`).
- **الصور الملصقة** = مرفقات للمهمة تُرفع بـ `fetch` (`lib/upload.js` ← `POST /attachments` مع `Accept: application/json` يعيد `{attachments:[{id, src}]}`)، وتُعرض من `GET /attachments/{id}/inline` الذي يبث الملف من المزود (أضيفت `read()` لكل مشغّل في `StorageProvider`)، أو من النسخة المؤقتة إن لم يكتمل الرفع.
- **المرفقات**: `Attachments::TYPES['task']` (صلاحيات `tasks.view/edit`). المجلد: مجلد المشروع لمهام المشاريع، و`<العميل>/المهام` لغيرها (مفتاح `client:{id}:tasks`). `AttachmentsPanel` في صفحة المهمة.
- **التعليقات** (`2026_11_29_000200_create_comments_table.php`): جدول `comments` متعدد الأشكال (`commentable_type = 'task'`)، الرد على مستوى واحد (الرد على رد يُلحق بأول تعليق في السلسلة)، `assigned_to` + `resolved_at/by` للتعليق المُسند، `edited_at`، حذف ناعم (حذف التعليق يحذف ردوده). الخدمة `App\Services\Tasks\Comments` (`add`، `edit`، `delete`، `assign`، `resolve`، `payload`). المسارات: `POST /tasks/{task}/comments`، `PATCH|DELETE /tasks/comments/{id}`، `POST /tasks/comments/{id}/resolve|assign`. الواجهة `components/tasks/TaskComments.vue` (روابط `#comment-{id}`).
- **الإشعارات**: `App\Notifications\TaskNotification` (قناة database، بلا queue) بحقول `kind`، `title`، `body`، `url`، `task_id`، `task_code`، `actor`، `comment_id`. `App\Services\Tasks\TaskNotifier` يقرر من يُبلَّغ: الإسناد (`task.assigned`)، الإشارة في الوصف أو التعليق (`task.mentioned`، للمُشار إليهم الجدد فقط)، تعليق جديد للمتابعين والمسؤولين وأطراف السلسلة (`task.comment`)، التعليق المسند (`task.comment_assigned`)، تغيير الحالة (`task.status`) والموعد (`task.due`) للمتابعين والمسؤولين. لا يُبلَّغ صاحب التغيير ولا من لا يرى المهمة، وإشعار واحد لكل شخص في كل حدث.
- المتابعون: المنشئ والمسؤولون يصبحون متابعين تلقائياً، وكذلك كل من يعلّق.
- الاختبارات: `tests/Feature/TaskCollabTest.php`.

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

1. ~~الجداول والنماذج والاختبارات لـ CRM.~~ ✅
2. ~~واجهات CRM (Kanban، العملاء، الفرص، الأنشطة، التحويل).~~ ✅
3. ~~الخدمات وعروض الأسعار + PDF.~~ ✅
4. ~~العقود.~~ ✅
5. ~~المشاريع ومساحة المشروع.~~ ✅
6. ~~ربط التخزين والمرفقات.~~ ✅

## قرارات تحتاج مراجعة

قرارات اتُّخذت أثناء العمل دون الرجوع للمستخدم (الأبسط والأنفع للوكالة). راجعها وغيّر ما لا يناسب.

**الخطوة 5 (المشاريع):**
1. **الميزانية = قيمة المشروع المتفق عليها مع العميل** (ما يدفعه العميل)، لا ميزانية التكلفة الداخلية. تُنسخ من العرض (الصافي قبل الضريبة) أو من العقد (قيمته، أو مجموع دفعات الشهري). التكلفة الفعلية تأتي لاحقاً من الساعات والمصروفات.
2. **قوالب المشاريع في `config/projects.php`** لا في جدول قابل للتعديل من الواجهة. تعديلها يحتاج تعديل الملف؛ واجهة لإدارتها يمكن إضافتها لاحقاً (مع مهام القالب في المرحلة 3أ).
3. أُضيف نوع **«إدارة منصات التواصل»** إلى الأنواع الخمسة في المواصفات، لأنه أشيع نوع مع العقود الشهرية. المشروع المنشأ من عقد شهري يأخذ هذا النوع افتراضياً.
4. **رقم للمشروع** `PRJ-YYYY-NNNN` يُمنح عند الإنشاء (يُستخدم في أسماء مجلدات التخزين).
5. المراحل تُنجز **يدوياً** الآن؛ حساب نسبتها من المهام ينتظر المرحلة 3أ.
6. **عدّاد جولات التعديل** المذكور مع طلبات التغيير مؤجل إلى التسليمات وبوابة المراجعة (المرحلة 6)؛ طلب التغيير الآن يحمل أثر التكلفة والوقت فقط.
7. عرض الميزانية مقصور على من لديه `projects.manage_budget` أو `projects.approve_changes` أو `finance.view`؛ أعضاء الفريق العاديون لا يرونها.
8. أرشفة المشروع (soft delete) لا تفك ربطه بالعرض/العقد، فلا يمكن إنشاء مشروع جديد من نفس العرض بعد أرشفة مشروعه.

**المرحلة 3أ — الخطوة 1 (محرك المهام):**
1. **كل قائمة تملك نسخة من حالاتها** (تُنسخ من القالب عند الإنشاء). تعديل حالات قائمة لا يمس غيرها، وتعديل القالب يؤثر على القوائم الجديدة فقط. في العروض التي تجمع عدة قوائم تُوحَّد الحالات بالاسم، ونقل مهمة إلى حالة من قائمة أخرى يختار الحالة بنفس الاسم في قائمتها (أو أول حالة من نفس المجموعة).
2. **المهمة تُنقل بين قوائم العميل نفسه فقط** لأن معرّفها (ULF-142) مرتبط بالعميل. لإظهارها في قائمة عميل آخر (مثل «تصوير الأسبوع» الداخلية) تُضاف إليها كقائمة إضافية.
3. **قوائم المشروع من القالب = قائمة لكل مرحلة** ومرتبطة بها، فتقدّم المرحلة يُحسب من مهام قائمتها. المشروع بلا قالب يأخذ قائمة واحدة «المهام». لا مهام جاهزة في القوالب الآن (قوالب المهام في 3ب).
4. **المرحلة تُنجز تلقائياً** حين تكتمل كل مهامها الرئيسية، ويبقى الإنجاز اليدوي للمراحل التي بلا مهام.
5. **المجموعتان «مكتملة» و«مغلقة» كلتاهما تعني منتهية** وتُحسبان في التقدم.
6. **قوائم العميل الداخلي مرئية لكل من لديه صلاحية المهام** ويستطيع الإضافة إليها، لكن المهام نفسها تتبع قواعد النطاق (لا يرى الموظف مهام زملائه الداخلية إلا بنطاق `all` أو بالإسناد/المتابعة).
7. منشئ المهمة هو مسؤولها الافتراضي ومتابعها؛ من لا يملك `tasks.assign` لا يغيّر المسؤولين.
8. **البادئة تُولَّد تلقائياً** من الحروف اللاتينية في اسم العميل (ULFED ← ULF) أو من أوائل الكلمات العربية بالحروف اللاتينية (شركة النور ← SHN)، وتُعدَّل من نموذج العميل. تغييرها يغيّر عرض كل المعرّفات القديمة (الرقم ثابت والبادئة تُقرأ وقت العرض).
9. حذف القائمة مسموح فقط إن كانت فارغة؛ المهام تُؤرشف (soft delete) مع فرعياتها وتُستعاد معها.
10. «Insight Media» عميل عادي في جدول `clients` بعلامة `is_internal`، ويظهر في قائمة عملاء CRM أيضاً.

**المرحلة 3أ — الخطوة 2 (التعاون):**
1. **كل من يرى المهمة يستطيع التعليق عليها** (صلاحية `tasks.view` تكفي)، لأن التعليق جزء من التعاون. تعديل التعليق لصاحبه فقط، والحذف لصاحبه أو لمن يملك `tasks.delete`. حلّ التعليق المسند: المُسند إليه أو كاتبه أو من يعدّل المهمة.
2. **لصق الصور يحتاج خدمة تخزين مربوطة وصلاحية تعديل المهمة** (لأنها مرفقات). بدون تخزين مربوط تظهر رسالة ولا تُحفظ الصورة.
3. الصور داخل الوصف والتعليقات تُعرض عبر خادم BOS (بث من المزود مع تخزين مؤقت في المتصفح ليوم)، لأن روابط المزودين ليست روابط صور مباشرة.
4. **من يُشار إليه ولا يملك رؤية المهمة لا يُبلَّغ** (لا تُمنح رؤية تلقائية بالإشارة). أضِفه متابعاً أو مسؤولاً أولاً.
5. الردود على مستوى واحد (مثل ClickUp)؛ الرد على رد يذهب إلى السلسلة نفسها.
6. الإشعارات داخل النظام فقط (الجرس). البريد الإلكتروني للإشعارات غير مفعّل.

**الخطوة 6 (التخزين والمرفقات):**
1. **Google Drive بنطاق `drive.file`** (لا يرى إلا ما أنشأه النظام) بدل الوصول لكل الـ Drive. أأمن، لكن الملفات التي تُضاف يدوياً إلى مجلدات BOS من Drive لا تظهر في النظام (النظام لا يقرأ المجلدات أصلاً حالياً).
2. **الرفع متزامن داخل الطلب** (لا Queue worker على Plesk)، مع نسخة مؤقتة على الخادم حتى ينجح الرفع، وإعادة محاولة تلقائية كل 10 دقائق (5 مرات) ثم يدوياً. الملفات الكبيرة جداً تحتاج رفع حدود PHP (انظر DEPLOY).
3. **الحذف من BOS يحذف من الخدمة** (Google: سلة المحذوفات، OneDrive: سلة المحذوفات، Dropbox: قابل للاسترجاع، WebDAV: حذف نهائي). إن كان الاتصال مفصولاً يُحذف من BOS فقط.
4. **WD My Cloud عبر WebDAV**: الملف يُفتح ببثه عبر خادم BOS (لا رابط مباشر). أجهزة My Cloud الحديثة (OS 5) قد لا تدعم WebDAV أصلاً؛ إن كان جهازكم منها فالخيار العملي أحد المزودين السحابيين.
5. أسماء المجلدات عربية (`العملاء`، `المشاريع`، `العقود`، `عروض الأسعار`) ومجلد العميل `<الاسم> #<id>` لتفادي تكرار الأسماء.
6. المرفقات مفعّلة الآن على العملاء والمشاريع والعقود وعروض الأسعار فقط (الفرص والمحتملون بلا مجلد عميل ثابت).
7. تغيير المزود لا ينقل الملفات القديمة: تبقى مرتبطة بخدمتها وتُفتح منها، والجديدة تذهب للمزود الجديد.
8. عرض الملفات يتبع صلاحية «عرض» السجل، والرفع والحذف يتبعان «تعديل» السجل (لا صلاحية منفصلة للملفات).


# Insight BOS — ملف التسليم بين الجلسات

اقرأ هذا الملف أولاً في أي جلسة عمل جديدة. المواصفات الكاملة (مع القرارات والتعليقات) في المستند:
https://claude.ai/artifact/2VxciFCPhhQuCPQFdS8hZT

## الحالة الحالية (2026-11-28)

- **المرحلة 1 منتهية ومنشورة** على https://test.insightpromedia.com (Plesk، PHP 8.3، Laravel Toolkit يسحب فرع `deploy`).
- **المرحلة 2 منتهية ومدموجة في `main`** (CRM، عروض الأسعار، العقود، المشاريع، التخزين).
- **المرحلة 3أ (المهام) منتهية ومدموجة في `main`** وجرّبها المستخدم يدوياً.
- **المرحلة 3ب مبنية بالكامل وتنتظر الدمج بالترتيب، فرع لكل خطوة، كل فرع مبني على الذي قبله (الأول على `main`)، ولا تبدأ خطوة قبل أن يصبح CI للسابقة أخضر:**
  1. `phase-3b-fields` ← الحقول المخصصة وأنواع المهام ✅
  2. `phase-3b-deps` ← الاعتماديات والروابط، عرض Gantt، المهام المتكررة ✅
  3. `phase-3b-workload` ← ضغط العمل (سعة الموظف)، الخط الزمني للفريق، جداول الساعات الأسبوعية واعتمادها ✅
  4. `phase-3b-automation` ← القوالب (مهمة، قائمة، مشروع) والأتمتة (مُشغِّل ← شرط ← إجراء) + الأتمتة الجاهزة للوكالة ✅
  5. `phase-3b-intake` ← نماذج الاستقبال (Form View) والتدقيق على الصور والفيديو (Proofing) ✅
  6. `phase-3b-guests` ← صلاحيات على مستوى العميل/المشروع والضيوف ✅
  إن انقطع العمل: ابدأ من آخر فرع موجود على GitHub واقرأ قسمه أدناه. المستخدم يدمج بالترتيب عبر PR ثم يشغّل `migrate --force` و`db:seed --force` و`config:cache`.
- **ملاحظات المستخدم بعد تجربة 3ب** في الفرع `phase-3b-notes` (على `main`) ✅ — انظر قسمها أدناه.
- **المرحلة 4 (المالية)**، فرع لكل خطوة مبني على الذي قبله (الأول على `phase-3b-notes`)، بنفس القواعد:
  1. `phase-4-invoices` ← الفواتير (من دفعات العقود، العروض المقبولة، الساعات المعتمدة)، PDF، مسودات تلقائية للعقود الشهرية ✅
  2. `phase-4-payments` ← الحسابات النقدية والبنكية والتحويلات، سندات القبض وتسويتها على الفواتير، الاسترداد والعكس، كشف حساب العميل وأعمار الذمم، تذكير المتأخرة ✅
  3. `phase-4-expenses` ← المصروفات واعتمادها، الموردون وفواتيرهم والمستحقات لهم، السلف والنثريات ✅
  4. `phase-4-budgets` ← مراكز التكلفة والميزانيات وبنودها وتنبيه الانحراف، ربحية المشروع ✅
  5. `phase-4-reports` ← التدفق النقدي، الأرباح والخسائر، الربحية (مشروع/عميل/خدمة/موظف)، لوحة المالية، تصدير Excel ✅
  **المرحلة 4 مبنية بالكامل** وتنتظر الدمج بالترتيب (PR #16 ثم #17 … ثم #21). بعد الدمج: `migrate --force` ثم `db:seed --force` ثم `config:cache`، ومنح `finance.expense` للموظفين.

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

## ما بُني في عروض المهام (المرحلة 3أ، الخطوة 3: `phase-3a-views`)

- صفحة `/tasks` تعرض نفس المهام بأربعة عروض حسب `?view=`: `list` (تجميع بأي حقل، تعديل مباشر، توسيع الفرعية، تحديد)، `board` (Kanban بالسحب: `vue-draggable-plus`)، `calendar` (FullCalendar v6: `@fullcalendar/core|vue3|daygrid|timegrid|interaction`، عربي RTL، السبت أول الأسبوع)، `table` (أعمدة مختارة، تعديل الخلايا، فرز بالعناوين، تحديد).
- إعدادات العرض في الرابط ويُنظّفها `App\Services\Tasks\ViewConfig`: `group` (status | assignee | priority | due | list | none)، `sort` (manual | due | priority | created | updated | title) + `dir`، `columns[]`، `date` (due | start للتقويم)، `subtasks=0` لإخفاء الفرعية. الترتيب في `TaskQuery::sort()`.
- اللوحة: السحب إلى عمود آخر يرسل `PATCH /tasks/{id}` بقيمة العمود (`groupTasks()` في `lib/tasks.js` يعيد لكل مجموعة دالة `drop`)؛ أعمدة «التسليم» القابلة للإفلات: اليوم، غداً، بلا تاريخ. السحب داخل العمود في الترتيب اليدوي يرسل `POST /tasks/{id}/reorder` بموضعي الجارين. التقويم: السحب يغيّر التسليم (أو البدء والتسليم معاً للمهمة الممتدة)، والنقر على يوم داخل قائمة ينشئ مهمة بذلك التاريخ.
- **العروض المحفوظة** (`2026_11_29_000300_create_saved_views_table.php`، `SavedView`): `type`، `config` (الإعدادات + `filters`)، `location_type/id` (null = كل المهام)، `is_shared`. تظهر لصاحبها، والمشتركة للجميع. التعديل والحذف لصاحبها أو لمن يملك `tasks.edit` بنطاق `all` (للمشتركة). `SavedViewController`: `POST /tasks/views`، `PUT /tasks/views/{id}` (`name`، `is_shared`، `replace_config` + `config`)، `DELETE`. فتح العرض: `/tasks?…&saved={id}`، وأي إعداد في الرابط يتجاوز المحفوظ مؤقتاً.
- **الإجراءات الجماعية**: `POST /tasks/bulk` (`ids[]`، `action`، `value`) عبر `App\Services\Tasks\BulkActions`: `status`، `priority`، `due`/`start` (`{date, time}`)، `assignees_add`، `assignees_set`، `tags_add`، `tags_remove`، `move`، `link`، `archive`. كل مهمة لا يملك المستخدم صلاحيتها (أو لا ينطبق عليها الإجراء) تُتخطّى وتُذكر في الرسالة. الواجهة `components/tasks/BulkBar.vue` (شريط عائم عند التحديد).
- **التصدير**: `GET /tasks/export` بنفس الموقع والفلاتر والترتيب ← CSV بترميز UTF-8 مع BOM (يفتح في Excel بالعربية)، حتى 5000 مهمة.
- المكونات الجديدة: `TaskBoardView`، `TaskCalendarView`، `TaskTableView`، `BulkBar`، `SaveViewModal`؛ `TaskListView` أصبح يجمّع بأي حقل.
- الاختبارات: `tests/Feature/TaskViewsTest.php`.

## ما بُني في «عملي» والوقت (المرحلة 3أ، الخطوة 4: `phase-3a-mywork`)

- الجداول (`2026_11_29_000400_create_time_and_reminders_tables.php`): `users.hourly_cost` (USD، مخفي من التسلسل، يُعدَّل في نموذج المستخدم فقط لمن يملك `hr.view_salaries`)، `time_entries` (مؤقت وقيود يدوية في جدول واحد: `started_at`، `ended_at` = null للمؤقت الجاري، `minutes`، `billable`، `cost_rate` لقطة من تكلفة ساعة الشخص، `source` = timer | manual، و`project_id`/`client_id` منسوخان من المهمة)، `reminders` (`remind_at`، `note`، `sent_at`، `done_at`، مهمة اختيارية).
- **تتبع الوقت** `App\Services\Tasks\TimeTracking`: `start` (يوقف الجاري أولاً؛ مؤقت واحد لكل شخص على الخادم)، `stop` (أقل من دقيقة لا يُسجَّل)، `add` (تاريخ + مدة أو من/إلى؛ لا مستقبل؛ 1 دقيقة–24 ساعة)، `update`، `billableDefault` (عمل العملاء قابل للفوترة، والداخلي لا)، `totals` (دقائق، قابلة للفوترة، التكلفة = Σ دقائق × cost_rate ÷ 60، والدقائق بلا تكلفة)، `forProject`. المسارات (`can:tasks.track_time`): `POST /timer/start|stop`، `POST|PATCH|DELETE /time-entries`، `GET /my-work/time?week=&user=`. نطاق `all` يدير قيود الآخرين ويرى وقتهم.
- المؤقت الجاري مشترك في كل صفحة (`timer` في `HandleInertiaRequests`) ويظهر في الترويسة (`components/TimerPill.vue`). صفحة المهمة: `TaskTimePanel` (بدء/إيقاف، إدخال يدوي `TimeEntryForm`، المسجّل مقابل التقدير). صفحة المشروع: الساعات المسجلة والقابلة للفوترة، وتكلفتها (لمن يرى الميزانية) ونسبتها من الميزانية.
- **«عملي»** `GET /my-work` (`MyWorkController@index`): مهامي المسندة المفتوحة مقسّمة متأخرة / اليوم / 7 أيام / لاحقاً / بلا تاريخ، والتعليقات المسندة إليّ غير المحلولة، وتذكيراتي، ومواعيد CRM اليوم، وساعات الأسبوع. `GET /my-work/calendar`: تقويم مهامي (سحب لتغيير الموعد) + أنشطة CRM الخاصة بي كمواعيد. التبويبات `components/tasks/MyWorkTabs.vue`.
- **الوارد** `GET /inbox` (`InboxController`): كل الإشعارات بصفحات، فلتر غير المقروء والنوع، `POST /notifications/unread`، `DELETE /notifications` (`read_only` أو `ids[]`). الجرس فيه رابط «صندوق الوارد»، والقائمة الجانبية فيها «عملي» و«الوارد» بعدّاد.
- **التذكيرات**: `POST /reminders` (`ReminderButton.vue` في صفحة المهمة و«عملي»)، `PATCH /reminders/{id}` (`done` أو `remind_at`)، `DELETE`. الأمر `tasks:reminders` **كل دقيقة** يحوّل المستحق إلى إشعار `task.reminder`. الأمر `tasks:digest` **يومياً 08:30** يرسل لكل شخص ملخص «مهام اليوم والمتأخرة والتعليقات المسندة» مرة واحدة في اليوم وفقط إن وُجد شيء.
- **Cron مطلوب على الخادم** (موجود منذ المرحلة 2): `* * * * * php artisan schedule:run`.
- الاختبارات: `tests/Feature/MyWorkTest.php`.

## ما بُني في التقارير ولوحة المالية (المرحلة 4، الخطوة 5: `phase-4-reports`)

- `App\Services\Finance\FinanceReports` (كل المبالغ بالدولار بسعر كل مستند):
  - `pnl($from, $to)` الأرباح والخسائر شهرياً: الإيرادات (الفواتير الصادرة في الفترة بتاريخ إصدارها، صافية من الضريبة، موزعة على تصنيفات الخدمات من بنودها)، التكاليف المباشرة (المصروفات المعتمدة بتصنيف `direct`)، مجمل الربح، المصاريف العامة (`overhead`)، صافي الربح، و«للعلم» تكلفة الساعات المسجلة.
  - `cashflow($from, $to, $account)` التدفق النقدي شهرياً من `Accounts::movements` حسب النوع (تحصيل، مرتجع عهد، ردّ، مصروفات، موردون، تعويض، عهد)؛ التحويلات بين حسابات الشركة تُستبعد إلا لحساب واحد؛ رصيد أول وآخر الفترة (مجموع الحسابات بسعر صرف ذلك اليوم).
  - `profitability($group, …)`: `project` و`client` (الإيراد، المصروفات المعتمدة، تكلفة الساعات، الربح، الهامش)، `service` (إيراد تصنيف الخدمة من بنود الفواتير؛ تكاليف كل مشروع تُوزَّع على خدماته بنسبة إيرادها)، `employee` (الساعات ونسبة الفوترة وتكلفتها؛ الإيراد المنسوب = إيراد المشروع × حصة ساعاته).
  - `dashboard($user)`: النقد في الحسابات، الذمم والمتأخر، المستحق للموردين وللموظفين، العهد المفتوحة، المصروفات المعلّقة، هذا الشهر مقابل الماضي، اتجاه 12 شهراً، أعلى المتأخرات، الميزانيات فوق حد التنبيه.
- `FinanceReportController`: `GET /finance` (اللوحة؛ من نطاقه own يُحوَّل إلى الفواتير)، `GET /finance/reports?type=pnl|cashflow|profit&group=&from=&to=&account=`، `GET /finance/reports/xlsx` (بنفس المعاملات، بصلاحية `finance.export`، عبر `App\Support\Xlsx`). كلها بـ `finance.view` نطاق «الكل».
- الواجهات: `pages/finance/Dashboard.vue` (أعمدة CSS بلا مكتبة رسوم)، `pages/finance/reports/Index.vue` (تبديل التقرير، فترات جاهزة، جدول أشهر بعمود أول ثابت). «المالية» في القائمة الجانبية تفتح اللوحة؛ تبويبا «اللوحة» و«التقارير».
- الاختبارات: `tests/Feature/FinanceReportTest.php`.

## ما بُني في الميزانيات والربحية (المرحلة 4، الخطوة 4: `phase-4-budgets`)

- الجداول (`2026_12_01_000400_create_budgets_tables.php`): `cost_centers`، `expenses.cost_center_id`، `budgets` (اسم، مشروع **أو** مركز تكلفة **أو** لا شيء = الشركة، فترة، `alert_percent` افتراضي 90، `owner_id`، حذف ناعم؛ للمشروع أكثر من ميزانية)، `budget_lines` (`kind` category | labor، التصنيف، `planned` بالدولار، `alert_level` آخر تنبيه أُرسل).
- `App\Services\Finance\Budgets`: `save` (بند واحد لكل تصنيف، وبند «ساعات العمل» اختياري؛ يحفظ حالة التنبيه إلا إن زاد المخطط)، `expensesQuery` (المعتمدة في النطاق والفترة)، `report` (لكل بند: المخطط، الفعلي بالدولار بسعر كل مصروف، المتبقي، النسبة؛ والمصروف على تصنيفات بلا بند «خارج الميزانية»؛ ونسبة ما مضى من الفترة)، `checkExpense` (يُستدعى بعد اعتماد أي مصروف)، `checkAll` (الأمر `finance:budgets` **يومياً 07:40**، يلتقط بنود الساعات)، `alert` (إشعار `finance.budget_alert` عند بلوغ `alert_percent` ثم عند تجاوز 100%، مرة لكل مستوى، للمسؤول والمنشئ ومدير المشروع)، `canManage` (المالية «تعديل» بنطاق الكل، أو من يدير ميزانية المشروع `projects.manage_budget`)، `canView`.
- **الفعلي لا يُخزَّن**: بند التصنيف = المصروفات المعتمدة من ذلك التصنيف داخل النطاق والفترة؛ بند الساعات = تكلفة الساعات المسجلة (المشروع، أو كل الشركة؛ مراكز التكلفة بلا ساعات).
- `App\Services\Finance\Profitability`: `project` (الإيراد = الفواتير الصادرة صافية من الضريبة، المفوتر، المحصّل، المتبقي، المصروفات المعتمدة، تكلفة الساعات، الربح والهامش، وتوزيع المصروفات على التصنيفات، وقيمة المشروع المتفق عليها)، `summarize` و`netUsd` لتقارير الخطوة 5.
- المسارات `/finance/budgets` (index بفلتر `scope` و`current`، create `?project_id`، store، show، edit/update، destroy) و`/finance/cost-centers` (store/update/destroy لمن يملك المالية «تعديل» الكل أو `settings.manage`).
- الواجهات: `pages/finance/budgets/{Index,Form,Show}.vue` (مدير مراكز التكلفة في Index)، `components/finance/BudgetBar.vue` (شريط أخضر/أصفر/أحمر + علامة ما مضى من الفترة)، `components/finance/ProjectProfit.vue` في صفحة المشروع (لمن يرى ميزانيته) مع ميزانيات المشروع. حقل «مركز التكلفة» في نموذج المصروف. تبويب «الميزانيات».
- الاختبارات: `tests/Feature/BudgetTest.php`.

## ما بُني في المصروفات والموردين والسلف (المرحلة 4، الخطوة 3: `phase-4-expenses`)

- الجداول (`2026_12_01_000300_create_expenses_tables.php`): `expense_categories` (`kind` = direct «تكلفة مباشرة» | overhead «مصاريف عامة»؛ `ExpenseCategorySeeder` يضيف تصنيفات الوكالة)، `vendors`، `advances` (رقم `ADV-…`، `petty_cash` | `salary_advance`، من حساب)، `advance_returns` (إلى حساب أو خصم بلا حساب)، `reimbursements`، `expenses` (رقم `EXP-…` عند التقديم، `paid_by` = account | employee | advance | credit، `fx_rate` بتاريخه، `status` pending | approved | rejected | cancelled، `decided_by/at`، `decision_note`، `reimbursement_id`، `vendor_ref`، `due_date` للآجل، مشروع ← العميل منسوخ)، `vendor_payments` (رقم `VP-…`) و`vendor_payment_allocations`. الـ migration يضيف تسلسلي `advance` و`vendor_payment`. morph map: `expense`، `vendor`، `advance`، `vendor_payment`، `reimbursement`.
- **صلاحية جديدة `finance.expense`** («تقديم المصروفات»): يقدّم الموظف مصروفاته ويرى مصروفاته وعهده فقط، دون بقية المالية (عنصر «مصروفاتي» في القائمة الجانبية لمن لا يملك `finance.view`). أضيفت لقوالب «موظف» و«مدير مشاريع» (+ `finance.approve` assigned) و«مالية» (+ `finance.delete`). القوالب الموجودة في القاعدة لا تتغير: امنحها يدوياً.
- `App\Services\Finance\Expenses` المصدر الوحيد: `save` (الحساب/العهدة يحددان العملة؛ الآجل يحتاج مورداً واستحقاقاً افتراضياً بعد `vendor_payment_days`=30؛ إدخال لموظف آخر لمن يملك المالية «الكل»؛ إشعار `finance.expense_submitted` للمعتمِدين)، `approve` (**إيصال مرفق إلزامي** `expense_receipt_required`، والعهدة يكفي متبقيها)، `reject` (سبب)، `cancel` (المعلّق يسحبه صاحبه؛ المعتمد تلغيه المالية بسبب ما لم يُعوَّض أو يُسدَّد)، `canDecide` (`finance.approve` all، أو assigned لمدير مشروع المصروف؛ **لا أحد يعتمد مصروفه**)، `canEdit`، `canAttach`، `canCancel`، `approvers`. إشعار `finance.expense_decided` لصاحبه.
- `App\Services\Finance\Payables`: دفعات الموردين `payVendor`/`allocateBill`/`reverseVendorPayment` (مثل سندات القبض: دفعة لعدة فواتير، والزائد دفعة مقدّمة)، `openBills`، `owedByVendor` (بالدولار)؛ التعويض `reimburse` (مصروفات «دفعها الموظف» المعتمدة، بعملة الحساب) و`reverseReimbursement` و`toReimburse`؛ العهد `giveAdvance`، `returnAdvance`، `reverseAdvance` (فقط بلا مصروفات ولا مرتجعات)، `openAdvances`.
- `Expense::settlement()` محسوبة: للآجل unpaid | partial | paid | overdue، ولما دفعه الموظف to_reimburse | reimbursed. `Advance::balance()` = المبلغ − المصروفات المعتمدة − المرتجع.
- **حركات الحسابات** (`Accounts::movements`) صارت تشمل: المصروف المعتمد المدفوع من حساب (بتاريخه)، دفعات الموردين، التعويضات، تسليم العهد، ومرتجعاتها النقدية.
- المرفقات: `Attachments::TYPES['expense']` بصلاحيات خاصة (`Expenses::canAttach`)، والمجلد: مجلد المشروع، أو `Insight BOS/المصروفات/<YYYY-MM>`.
- المسارات تحت `/finance`: `expenses` (index بفلاتر الحالة و`to_reimburse` و`unpaid_bills` و`decide=1` «بانتظار اعتمادي»، create `?project_id|vendor_id`، store، show، edit/update، `approve`، `reject`، `cancel`)، `expense-categories` (settings.manage أو finance.edit all)، `vendors` (index/show بـ finance.view؛ store لمن يقدّم المصروفات أيضاً ويعيد JSON للنموذج؛ update/destroy بـ finance.edit؛ `pay` بـ finance.create all)، `vendor-payments/{id}/allocate|reverse`، `advances` (+ `return`، `reverse`)، `reimbursements` (+ `reverse`).
- الواجهات: `pages/finance/expenses/{Index,Form,Show}.vue` (مدير التصنيفات في Index)، `vendors/{Index,Show}.vue` (دفعة بتوزيع على الفواتير)، `advances/{Index,Show}.vue`، `reimbursements/Index.vue`. تبويبات المالية تظهر حسب الصلاحية.
- الاختبارات: `tests/Feature/ExpenseTest.php`.

## ما بُني في المقبوضات والحسابات (المرحلة 4، الخطوة 2: `phase-4-payments`)

- الجداول (`2026_12_01_000200_create_accounts_and_payments_tables.php`): `accounts` (صندوق/بنك/محفظة، عملة، رصيد افتتاحي بتاريخ، `is_active`، حذف ناعم = أرشفة)، `account_transfers` (`amount` بعملة المصدر و`to_amount` بعملة الوجهة، `reversed_at`)، `payments` (`kind` receipt | refund، رقم `RCPT-…` عند الإنشاء، العميل، الحساب، `refund_of_id`، المبلغ بعملة الحساب، `fx_rate` يوم الدفع، الطريقة، `status` posted | reversed + السبب)، `payment_allocations` (سند ← فاتورة: `amount` بعملة الفاتورة و`payment_amount` بعملة السند)، و`invoices.reminded_on`. morph map: `payment`، `account`. المفردات في `config/finance.php` (`account_types`، `payment_methods`، `overdue_reminder_days`، `aging_buckets`).
- **الأرصدة لا تُخزَّن**: `Accounts::balance/movements/statement` = الافتتاحي + المقبوضات − المردودات ± التحويلات غير المعكوسة (الخطوات التالية تضيف المصروفات ودفعات الموردين في `movements()`).
- `App\Services\Finance\Payments` المصدر الوحيد: `receive` (لا تاريخ مستقبلي، تسويات فورية اختيارية، كله في معاملة واحدة)، `allocate` (فاتورة صادرة لنفس العميل، لا يتجاوز المتبقي منها ولا غير المسوّى من السند؛ بين عملتين يُحسب `payment_amount` بسعر يوم السند أو يُدخل يدوياً)، `deallocate`، `refund` (من غير المسوّى فقط وبنفس العملة)، `reverse` (سبب إلزامي؛ المردودات تُعكس أولاً؛ تُحذف التسويات فتعود الفواتير مفتوحة)، `syncInvoice` (دفعة العقد المفوترة ← `done` عند السداد الكامل، وتعود `pending` إن انفكّ)، `credits` (رصيد العميل)، `openInvoices`، `convert`.
- `Invoice`: `paidAmount()` من التسويات، `withPaid()` (`paid_sum`)، `unpaid()`/`paid()` بشرط SQL. فلاتر الفواتير: `paid`، `partial`، `overdue`، وأي قيمة أخرى = غير مدفوعة. إلغاء فاتورة عليها دفعات مرفوض.
- `Receivables`: `statement` (كشف العميل: الفواتير مدين، المقبوضات دائن، المردود مدين، رصيد جارٍ بالدولار بسعر كل مستند)، `aging` (لكل عميل حسب أيام التأخر)، `remindOverdue` (الأمر `finance:overdue` **يومياً 09:10**: إشعار `finance.invoice_overdue` لمسؤول الفاتورة ومنشئها في الأيام 1، 7، 14، 30، 60 من التأخر، مرة في اليوم).
- PDF سند القبض/الرد: `ReceiptPdf` + `resources/views/pdf/receipt.php` (علامة «معكوس»).
- المسارات تحت `/finance`: `payments` (index، create `?client_id|invoice_id`، store، show، `pdf`، `allocate`، `refund`، `reverse`)، `DELETE allocations/{id}`، JSON `client-open/{client}` و`convert`؛ `receivables`، `statements/{client}` (+ `/xlsx` بصلاحية `finance.export`)؛ `accounts` (index، show = كشف الحساب بفترة، store/update/destroy/restore)، `transfers` (+ `reverse`). **الحسابات وكشف العميل بنطاق `all` فقط**؛ السندات بنطاق own (منشئها) أو all.
- الواجهات: `pages/finance/payments/{Index,Create,Show}.vue`، `pages/finance/accounts/{Index,Show}.vue`، `pages/finance/receivables/{Index,Statement}.vue`. صفحة الفاتورة فيها بطاقة «الدفعات» (تسجيل دفعة، والتسوية من رصيد العميل). قائمة الفواتير في صفحة العميل فيها رابط «كشف الحساب». تبويبات المالية: الفواتير، المقبوضات، الذمم المدينة، الصناديق والحسابات.
- الاختبارات: `tests/Feature/PaymentTest.php`.

## ما بُني في الفواتير (المرحلة 4، الخطوة 1: `phase-4-invoices`)

- الجداول (`2026_12_01_000100_create_invoices_tables.php`): `invoices` (عميل، جهة اتصال، مشروع/عقد/عرض اختيارية بنفس العميل، `status` draft | issued | cancelled، `issue_date`، `due_date`، `currency`، `fx_rate`، **`total` مثبّت عند الإصدار**، `payment_details`، `cancel_reason`، `source_invoice_id`)، `invoice_items` (مثل بنود العرض + `contract_schedule_id`)، و`time_entries.invoice_item_id`. morph map: `invoice`. المفردات في `config/finance.php`.
- الحالات المعروضة: `paid` و`partial` و`overdue` **محسوبة** من المدفوع (`Invoice::paidAmount()` = 0 حتى الخطوة 2) وتاريخ الاستحقاق.
- `App\Models\Concerns\HasLineAmounts`: حساب البند (gross/discount/net/tax) لبنود الفاتورة.
- `App\Services\Finance\Invoices` المصدر الوحيد: `saveDraft` (يستبدل البنود، يحجز دفعات العقود `contract_schedules.invoice_id` وقيود الوقت `time_entries.invoice_item_id`، ويرفض ما هو محجوز لفاتورة أخرى أو لعميل آخر)، `issue` (رقم `INV-…`، تثبيت الضريبة وسعر الصرف والإجمالي، تاريخ الإصدار لا يتجاوز اليوم، الاستحقاق = الإصدار + `paymentDays()` إن لم يُحدَّد)، `cancel` (سبب إلزامي، مرفوض إن وُجدت دفعات، يحرّر الدفعات والوقت)، `deleteDraft`، `duplicate`، `blank`/`fromQuote` (المقبول فقط)/`fromSchedules`، `billableTime($project)` (وقت قابل للفوترة غير مفوتر **في أسابيع معتمدة** من جداول الساعات، مجمّعاً لكل مهمة)، `draftRetainers()` (الأمر `finance:retainer-invoices` **يومياً 07:00**: مسودة لكل دفعة عقد شهري ساري تستحق خلال `invoices.retainer_lead_days` يوماً، وإشعار `finance.invoice_draft` لصاحب العقد).
- PDF: `InvoicePdf` + `resources/views/pdf/invoice.php` (نفس تصميم العرض: فاتورة إلى، الاستحقاق، المرجع، المدفوع والمتبقي، طريقة الدفع). علامة مائية: مسودة / ملغاة / مدفوعة. `QuotePdf::company()` صار مشتركاً.
- الإعدادات في «بيانات الشركة» ← `PUT /settings/invoice-defaults` (`invoices.payment_days`، `invoices.default_terms`، `invoices.payment_details`، `invoices.retainer_lead_days`).
- المسارات `/finance/invoices` (`InvoiceController`): index (ملخص المستحق والمتأخر)، create (`?client_id|project_id|quote_id|contract_id&schedule[]=`)، store، show، edit/update (المسودة فقط)، `issue`، `cancel`، `duplicate`، destroy (المسودة)، `pdf`. JSON للمحرر: `GET /finance/pending-schedules/{client}` و`GET /finance/billable-time/{project}` (`?invoice=` لإظهار ما تحجزه المسودة نفسها). الصلاحيات `finance.view|create|edit|delete` بنطاق own (المسؤول/المنشئ) أو all.
- المرفقات: `Attachments::TYPES['invoice']` (صلاحيات finance)، مجلد `<العميل>/الفواتير`.
- الواجهات: `pages/finance/invoices/{Index,Edit,Show}.vue`، `components/finance/{FinanceTabs,InvoiceList}.vue`، `lib/finance.js` (ألوان الحالات، حساب البنود). «المالية» في القائمة الجانبية. قوائم الفواتير في صفحات العميل والمشروع والعقد (`App\Services\Finance\InvoiceLists`)، عمود «الفاتورة» وزر «فوترة» لكل دفعة في صفحة العقد، وزر «فاتورة» في العرض المقبول.
- الاختبارات: `tests/Feature/InvoiceTest.php`.

## ملاحظات المستخدم بعد تجربة 3ب (`phase-3b-notes`)

- **القائمة الجانبية تُطوى** (سطح المكتب فقط): زر في الترويسة يحوّلها إلى شريط أيقونات بعرض `4.5rem`، واسم العنصر يظهر في تلميح عند مرور الفأرة (عنصر `fixed` كي لا يقصّه تمرير القائمة)، والعدّادات تصبح نقطة على الأيقونة. الاختيار في `localStorage` (`bos.sidebar`). الجوال كما هو (درج).
- `AppLayout` يقبل `wide` لإلغاء `max-w-7xl` (تستخدمه صفحة المهام).
- **صفحة المهام**: شجرة العملاء/المشاريع/القوائم صارت قائمة منسدلة من العنوان («كل المهام ▾» أو اسم المكان)، بحث يأخذ التركيز وتُغلق عند الاختيار، وزر «تثبيت» يعيدها لوحة جانبية (`localStorage` `bos.tasks.tree`). `TaskTree` فيه `bare` و`autofocus` ويُطلق `pick`. `Dropdown` يقبل `panelClass`.
- **الفلاتر في سطر واحد**: البحث + زر «فلاتر (n)» يفتح المسؤول والتاريخ والأولوية والوسم والنوع والحقول + «إظهار المكتملة» + عدد المهام.
- **إصلاح**: كانت صفحة المهام تمرّر أزرار العروض (قائمة/لوحة…) بدل أنواع المهام إلى الفلاتر والعروض وGantt (متغير `types` محلي يحجب الـ prop)، فكان فلتر «كل الأنواع» وأيقونات الأنواع وتلوين المعالم لا تعمل. صار اسمه `viewTypes`.
- **تصدير Gantt إلى Excel**: `GET /tasks/export/gantt?scale=day|week|month` + الموقع والفلاتر والترتيب و`subtasks` (زر «Excel» في عرض Gantt بالمقياس المعروض). `App\Services\Tasks\GanttExport`: ورقة «المهام» (الرمز، العنوان، القائمة، المشروع، المسؤولون، البدء، التسليم، المدة، الحالة، بانتظار) وورقة «Gantt» (شهر/سنة، ثم أيام أو أسابيع تبدأ السبت أو أشهر؛ خلايا ملوّنة على امتداد كل مهمة: أزرق، برتقالي للمسار الحرج، بنفسجي للمعلم، أحمر للمتأخرة، أخضر للمكتملة؛ أيام العطلة رمادية فاتحة؛ عمود اليوم مظلّل؛ مفتاح ألوان أسفلها). أكثر من 370 عموداً ← ينتقل تلقائياً لمقياس أكبر. حتى 5000 مهمة. الضيوف لا يصدّرون.
- **`App\Support\Xlsx`** (+ `XlsxSheet`): كاتب xlsx صغير على `ZipArchive` بلا مكتبة (نصوص، أرقام، تواريخ حقيقية، تعبئة، خط عريض/لون، محاذاة، حدود، عرض الأعمدة، تجميد، دمج، RTL). يُعاد استخدامه لتقارير المالية.
- الاختبارات: `tests/Feature/GanttExportTest.php`.

## ما بُني في المشاركة والضيوف (المرحلة 3ب، الخطوة 6: `phase-3b-guests`)

- الجداول (`2026_11_30_000600_create_access_grants_and_guests.php`): `users.is_guest`، `access_grants` (`user_id`، `scope_type` client | project | list، `scope_id`، `level` view | edit، `granted_by`؛ فريد لكل شخص ومكان)، `tasks.is_internal`، `comments.is_internal`.
- **`App\Services\Tasks\Access`**: `levels($permission)` (`tasks.view` ← view أو edit؛ `tasks.edit` و`tasks.create` ← edit؛ غيرها لا شيء)، `orTasks`/`orLists` (شروط SQL تُضاف إلى نطاق الرؤية)، `coversTask`، `hasGuests` (هل يرى المهمة ضيف)، `canShare` (`users.manage` أو من يدير المكان `CustomFields::canManage`)، `grant`/`revoke`/`invite` (ضيف جديد أو موجود بالبريد؛ صلاحياته العامة `GUEST_PERMISSIONS` = tasks.view/create/edit بنطاق own)، `passwordLink` (رابط «تعيين كلمة المرور» من نظام استعادة كلمة المرور).
- **الرؤية** في `Task::scopeVisibleTo/isVisibleTo` و`TaskList::scopeVisibleTo/isVisibleTo`: الموظف = نطاق صلاحيته **أو** ما شورك معه (view للعرض والتعليق، edit للتعديل والإنشاء). الضيف = ما أنشأه أو أُسند إليه + ما شورك معه فقط، **دون المهام الداخلية**، ولا يرى قوائم Insight Media الداخلية. `canManage` و`canCreateFor` ترفض الضيوف دائماً.
- **المهمة الداخلية** (`is_internal`، زر القفل في صفحة المهمة لمن يعدّلها؛ تنتقل لفرعياتها) لا يراها الضيوف. **التعليق الداخلي**: إن كان الضيوف يرون المهمة فالتعليق الجديد من الفريق داخلي افتراضياً إلا بتأشير «مرئي للضيوف» (`for_guests`)؛ الردود تتبع سلسلتها؛ تعليقات الضيف مرئية دائماً. الضيوف لا يُبلَّغون بالتعليقات الداخلية (`TaskNotifier::comment`).
- صفحة المهمة للضيف: بلا الوقت والسجل والقوالب والنقل بين القوائم، والفرعيات الداخلية والاعتماديات غير المرئية مخفية. التدقيق والمرفقات متاحة له حسب مستواه.
- **`RestrictGuests`** (middleware في مجموعة web): الضيف يصل فقط إلى صفحات المهام المسموحة (القائمة في `ALLOWED`)، «عملي»، الوارد، المرفقات، التدقيق، ملفه الشخصي، والنماذج العامة؛ لوحة القيادة تحوّله إلى «عملي»، وغيرها 403 أياً كانت صلاحياته. القائمة الجانبية للضيف: العناصر `guest: true` فقط.
- المسارات (JSON لنافذة المشاركة `ShareModal.vue`، زر «مشاركة» في صفحة المهام لعميل/مشروع/قائمة): `GET /tasks/access?type=&id=`، `POST /tasks/access` (`user_id`، `level`)، `POST /tasks/access/invite` (`name`، `email`، `level` ← يعيد `link`)، `POST /tasks/access/link/{user}` (رابط جديد)، `PUT/DELETE /tasks/access/{grant}`. في «المستخدمون» شارة «ضيف».
- الاختبارات: `tests/Feature/TaskGuestsTest.php`.

## ما بُني في نماذج الاستقبال والتدقيق (المرحلة 3ب، الخطوة 5: `phase-3b-intake`)

- الجداول (`2026_11_30_000500_create_intake_forms_and_proofing_tables.php`): `intake_forms` (`task_list_id`، `token` عشوائي 32 حرفاً للرابط، `is_public`، `is_active`، `fields` JSON، `defaults` JSON، `confirmation`، `submissions_count`)، و`attachments.previous_id` + `attachments.version` (نسخ الملف)، و`proof_comments` (`attachment_id`، `task_id`، `parent_id` للردود، `user_id` أو `author_name` لتعليقات العميل لاحقاً، `x`/`y` بالنسبة المئوية، `at_seconds`، `resolved_at/by`).
- **نماذج الاستقبال** `App\Services\Tasks\IntakeForms`: الأسئلة مفاتيح ثابتة `title` (دائماً) `description` `due` `priority` `files` `name` `email`، و`cf_<id>` لحقول القائمة المخصصة (الأنواع في `FIELD_TYPES`، بلا people وformula). `clean` يحذف المفاتيح غير الصالحة والمكررة. القيم التلقائية: المسؤولون، الوسوم، النوع، الأولوية، `due_in_days`. `submit` يتحقق من المطلوب، ينشئ المهمة (المنشئ = المستخدم، أو صاحب النموذج في الرابط العام) ويكتب «مقدّم الطلب» في الوصف، ثم يرفع الملفات بعد إنشاء المهمة. بلا مسؤول تلقائي يصل لصاحب النموذج إشعار `task.form` لتوزيعها. المهمة تُطلق أتمتة `task_created` كالعادة.
- المسارات: إدارة `GET /tasks/forms` (`tasks/Forms.vue` + `FormModal.vue`)، `GET /tasks/forms/fields?list=`، `POST/PUT/DELETE /tasks/forms` (لمن يدير القائمة: `CustomFields::canManage('list')`). التعبئة خارج مجموعة auth: `GET /forms/{token}` (الداخلي يحوّل الزائر لتسجيل الدخول)، `POST /forms/{token}` (حد 10 بالدقيقة، وحقل `website` مخفي كفخ للبوتات). الصفحة `pages/forms/Fill.vue` بهوية الشركة دون قائمة جانبية. في القائمة الجانبية «نماذج الاستقبال».
- **التدقيق** `App\Services\Tasks\Proofing`: لمرفقات المهمة من الصور والفيديو (`Attachment::isProofable`). `addComment` (نقطة x/y، أو لحظة، أو عام؛ الردود بلا موضع؛ يُبلغ المتابعين وصاحب التعليق الأصلي `task.proof` ويُطلق أتمتة `comment_added`)، `resolve`، `newVersion` (على آخر نسخة فقط؛ يرفع عبر `Attachments::store` ويربط `previous_id`)، `versions`، `payload`. الصفحة `GET /tasks/{task}/proof/{attachment}` (`tasks/Proof.vue`: نقر على الصورة لتثبيت دبوس مرقّم، شريط زمني بعلامات للفيديو و«تعليق عند اللحظة الحالية»، تبديل النسخ مع عدد المفتوح، إخفاء/إظهار المحلولة، الردود). المسارات: `POST /tasks/proof/{attachment}/comments`، `PATCH /tasks/proof-comments/{id}` (`resolved` لأي من يرى المهمة، `body` لصاحبه)، `DELETE` (صاحبه أو من يعدّل المهمة)، `POST /tasks/proof/{attachment}/version` (من يعدّل المهمة). الملف يُعرض من `/attachments/{id}/inline` (بث عبر BOS). في لوحة المرفقات زر «تدقيق» وشارة النسخة.
- الاختبارات: `tests/Feature/TaskIntakeTest.php`.

## ما بُني في القوالب والأتمتة (المرحلة 3ب، الخطوة 4: `phase-3b-automation`)

- الجداول (`2026_11_30_000400_create_templates_and_automations_tables.php`): `task_templates` (`type` task | list | project، `payload` JSON)، `automations` (`scope_type` all | client | project | list + `scope_id`، `trigger`، `trigger_config`، `conditions`، `actions`، `is_active`، `runs_count`، `last_run_at`)، `automation_runs` (سجل التنفيذ: ok | error، الرسالة، `dedupe_key` لمنع التكرار في المُشغِّلات الزمنية).
- **القوالب** `App\Services\Tasks\Templates`: `fromTask/fromList/fromProject` تحفظ الهيكل (الفرعيات، قوائم التحقق بتداخلها، الحقول، الوسوم، المسؤولين، النوع، الأولوية، التقدير، اسم الحالة، وحالات القائمة)، والتواريخ **أياماً نسبية** من يوم إنشاء المهمة/القائمة (`start_in_days`، `due_in_days`). `applyTask` (في قائمة أو كفرعية)، `applyList` (قائمة جديدة تحت عميل/مشروع)، `applyProject` (كل قوائم القالب داخل مشروع). المسارات: `POST /tasks/templates` (`from`، `id`، `name`)، `POST /tasks/templates/{id}/apply` (`list_id` | `parent_id` | `client_id` | `project_id`، `name`)، `DELETE /tasks/templates/{id}` (صاحبه أو `settings.manage`). الواجهة `TemplatesModal.vue`: في صفحة المهمة (حفظ كقالب / فرعية من قالب)، وفي صفحة المهام زر القوالب (إنشاء من قالب، حفظ القائمة أو قوائم المشروع كقالب، رابط الأتمتة).
- **الأتمتة** `App\Services\Tasks\Automations`: المُشغِّلات `task_created`، `status_changed` (to / from / to_group)، `assignee_added`، `field_changed`، `comment_added` تُطلق من `Tasks` و`CustomFields` و`Comments`؛ والزمنية `due_soon` (ساعات)، `overdue`، `status_stale` (حالة + أيام)، `monthly` (اليوم الأول، بلا مهمة) من الأمر `tasks:automations` **كل 15 دقيقة**. الشروط: الحالة، مجموعة الحالة، الأولوية، النوع، الوسم، المسؤول، `field:<id>` بعمليات is / is_not / empty / not_empty / gt / lt. الإجراءات: assign، set_status، set_priority، set_field، increment_field، add_tag، set_due، create_subtask، create_task، apply_template، move_to_list، notify. الأشخاص: معرّفات أو `assignees` / `watchers` / `creator` / `project_manager`؛ النصوص تقبل `{code} {title} {status} {list}` و`{month}` في اسم القائمة.
- تُنفَّذ **متزامنة** داخل الطلب، والإجراء الفاشل يُسجَّل في `automation_runs` ولا يوقف حفظ المستخدم. الأتمتة التي تُطلق أتمتة أخرى تتوقف عند **3 مستويات**.
- من يدير: من يدير الحقول المخصصة لذلك المكان (`CustomFields::canManage`). الصفحة `GET /tasks/automations?list|project|client=` (`tasks/Automations.vue`: القائمة مع آخر 5 تنفيذات، تفعيل/إيقاف، محرر «عندما ← إذا ← إذن» في `AutomationModal.vue`، ووصفات جاهزة للوكالة: مراجعة داخلية ← مهمة مراجعة للمدير الإبداعي، بانتظار العميل يومين ← تذكير، تعديلات ← عدّاد الجولات، تجاوز الجولات ← تنبيه، تجاوز الموعد ← إشعار، بداية الشهر ← قائمة محتوى من قالب).
- الاختبارات: `tests/Feature/TaskAutomationTest.php`.

## ما بُني في ضغط العمل وجداول الساعات (المرحلة 3ب، الخطوة 3: `phase-3b-workload`)

- الجداول (`2026_11_30_000300_create_workload_and_timesheets_tables.php`): `users.capacity_hours` (افتراضي 8) و`users.work_days` (JSON، 0=الأحد…6=السبت؛ null ← `config('tasks.work_days')` = الاثنين–الجمعة)، `time_off` (إجازات بسيطة إلى أن يأتي موديول HR)، `timesheets` (`user_id`، `week_start` = السبت، `status` draft | submitted | approved | rejected، `decided_by/at`، `note`).
- صلاحية جديدة: `tasks.approve_time` («اعتماد جداول الساعات»): `all` يعتمد للجميع، `assigned` لأعضاء المشاريع التي يديرها (أو من سجّل وقتاً عليها)، ولا أحد يعتمد أسبوعه. أضيفت لقالب «مدير مشاريع» في `RoleTemplateSeeder` (القوالب الموجودة لا تتغير؛ تُمنح يدوياً للمستخدمين الحاليين).
- **ضغط العمل** `App\Services\Tasks\Workload::build`: لكل شخص ويوم: الساعات المقدّرة (تقدير المهمة ÷ عدد المسؤولين ÷ أيام عمل الشخص بين البدء والتسليم، أو يوم التسليم وحده) مقابل السعة (0 في العطلة والإجازة)، مع قائمة المهام في كل خلية، والمجموع، وعلامة `over` لمن تجاوز سعته في الفترة.
- `WorkloadController`: `GET /tasks/workload?mode=workload|timeline&week=&weeks=1|2&project=` (صفحة `tasks/Workload.vue`: شبكة ساعات ملوّنة أخضر/أصفر ≥80%/أحمر >100%، و«الخط الزمني» أشرطة المهام لكل شخص)، `PUT /tasks/workload/capacity/{user}`، `POST /tasks/time-off`، `DELETE /tasks/time-off/{id}`. من يرى: `tasks.assign` بنطاق `all` (أو `tasks.approve_time` all) = الجميع؛ `assigned` = فرق مشاريعه؛ غيرهم = نفسه. تعديل السعة وإجازات الآخرين: `users.manage` أو `tasks.assign` all؛ كل شخص يسجّل إجازته.
- **جداول الساعات** `App\Services\Tasks\Timesheets`: `weekStart`، `for`، `assertOpen` (يرفض إضافة/تعديل/حذف قيود أسبوع مُرسل أو معتمد، ويُستدعى من `TimeTracking` و`TimeController`)، `submit` (يرفض إن كان المؤقت يعمل؛ يُبلغ المعتمِدين)، `decide` (approve | reject | reopen؛ يُبلغ صاحب الأسبوع)، `canApprove`. المسارات: `POST /timesheets/submit` (`week`، `note`)، `GET /tasks/timesheets?status=` (`tasks/Timesheets.vue`)، `POST /timesheets/{id}/decide`. صفحة «وقتي» فيها شريط حالة الأسبوع وزر الإرسال (وأزرار القرار للمعتمِد).
- القائمة الجانبية: «ضغط العمل» و«جداول الساعات».
- الاختبارات: `tests/Feature/WorkloadTest.php`.

## ما بُني في الاعتماديات وGantt والتكرار (المرحلة 3ب، الخطوة 2: `phase-3b-deps`)

- الجداول (`2026_11_30_000200_create_task_dependencies_and_recurrence.php`): `task_dependencies` (`task_id` ينتظر `depends_on_id` بنوع `waiting`، أو `link` لربط عادي يُقرأ من الطرفين)، و`tasks.recurrence` (JSON) و`tasks.recurred_from_id`.
- `App\Services\Tasks\Dependencies`: `add` (waiting | blocking | link، يمنع الربط بالنفس والحلقات)، `remove`، `openBlockers`، `shiftDependents` (تحريك تسليم مهمة يحرّك بدء وتسليم كل ما ينتظرها وما بعده بالمقدار نفسه، والمكتملة لا تتحرك)، `forTasks` (للصفوف: `waiting_on` و`blocked`)، `payload` (صفحة المهمة: بانتظار / تعطّل / مرتبطة).
- **إغلاق مهمة بانتظار مهام مفتوحة** يرفضه `Tasks::update` بخطأ `blocked` ما لم يُرسل `force: true`؛ `patchTask` في `lib/tasks.js` يعرض تأكيداً ويعيد الإرسال. الإجراء الجماعي يتخطى المهام المعطّلة.
- المسارات: `POST /tasks/{task}/dependencies` (`other_id`، `type`)، `DELETE /tasks/dependencies/{id}`، `GET /tasks/lookup?q=&exclude=` (JSON لمنتقي المهام `TaskPicker`).
- **Gantt**: عرض `view=gantt` (`TaskGanttView.vue` بمكتبة `frappe-gantt` v1، الحاوية `dir=ltr`): شريط من البدء إلى التسليم (أو يوم التسليم وحده)، أسهم الاعتماديات، تقدّم من الفرعيات، ألوان للمكتمل والمتأخر والمعلم، **المسار الحرج** (من المهمة المفتوحة التي تنتهي آخراً رجوعاً عبر أحدث مُعطِّل)، سحب للتحريك والتمديد (يحفظ الوقت إن كان للتاريخ وقت)، ونقرتان لفتح المهمة. `SavedView::TYPES` فيه `gantt`.
- **التكرار** `App\Services\Tasks\Recurrence`: `clean` (freq daily|weekly|monthly|yearly، interval، weekdays 0=الأحد، on complete|schedule، until)، `next`، `label`، `spawn` (نسخة جديدة: القائمة، الأشخاص، الوسوم، النوع، الحقول، قوائم التحقق غير مؤشَّرة، الفرعيات؛ تواريخها مزاحة إلى الموعد التالي؛ القاعدة تنتقل إليها)، `runScheduled`. عند الإغلاق: من `Tasks::update`. بالجدول: الأمر `tasks:recurring` **يومياً 00:10**. الواجهة `RecurrencePicker.vue` في صفحة المهمة، وأيقونتا ⏳ (معطّلة) و🔁 (متكررة) في الصفوف.
- الاختبارات: `tests/Feature/TaskDepsTest.php`.

## ما بُني في الحقول المخصصة وأنواع المهام (المرحلة 3ب، الخطوة 1: `phase-3b-fields`)

- الجداول (`2026_11_30_000100_create_custom_fields_and_task_types.php`): `task_types` (اسم، أيقونة lucide، لون، `is_milestone`، `is_default`)، `custom_fields` (`type`، `options` JSON، `scope_type` = all | client | project | list + `scope_id`، حذف ناعم)، `task_type_fields` (حقول النوع)، `custom_field_values` (`value` JSON + `sort_value` نصي للفلترة والفرز)، و`tasks.task_type_id`.
- أنواع الحقول: نص، رقم، مبلغ (بعملة)، قائمة منسدلة، تسميات (متعددة)، تاريخ، مربع اختيار، أشخاص، رابط، تقييم، نسبة، ملف (من مرفقات المهمة)، صيغة (`App\Support\Formula`: أرقام و`+ - * /` وأقواس و`{id}` لحقل رقمي و`{estimate}` للتقدير بالساعات؛ بلا eval، تُحسب وقت العرض ولا تُخزن).
- **متى يظهر الحقل على المهمة** (`CustomFields::forTask`): نطاقه يغطي موقعها (الكل، أو عميلها، أو مشروعها، أو قائمتها)، وإن كان مربوطاً بأنواع فالمهمة من أحدها.
- الخدمة `App\Services\Tasks\CustomFields`: `forTask`، `forLocation`، `save` (تعريف + خيارات + أنواع)، `setValue` (تحقق حسب النوع، سجل نشاط)، `valuesFor` (قيم صفوف كثيرة + حساب الصيغ)، `display` (نص للسجل وCSV)، `canManage` (`settings.manage` أو `tasks.edit` بنطاق `all` لأي نطاق؛ مدير المشروع لحقول مشروعه؛ من يدير القائمة لحقولها).
- المسارات: `POST /tasks/fields`، `PUT|DELETE /tasks/fields/{id}`، `PUT /tasks/{task}/fields/{field}` (`value`)؛ الأنواع في الإعدادات: `POST|PUT|DELETE /settings/task-types` (حذف نوع ينقل مهامه للافتراضي).
- في العروض: صفوف المهام تحمل `type_id` و`fields` (`{fieldId: value}`). الجدول يقبل أعمدة `cf:<id>` وعمود `type`، والترتيب `sort=cf:<id>`، والتجميع `group=type` أو `group=cf:<id>` (قائمة منسدلة؛ السحب في اللوحة يغيّر القيمة)، والفلاتر `types[]` و`fields[<id>]=<قيمة>` (منسدلة ومربع اختيار في الواجهة). التصدير يضيف أعمدة الحقول المختارة.
- الواجهة: `FieldValue` (عرض وتعديل حسب النوع)، `FieldDefModal`، `FieldsManager` (من قائمة إعدادات القائمة أو زر الحقول في المشروع/العميل)، `TaskTypeModal`، `TypeIcon`؛ صفحة المهمة فيها منتقي النوع وبطاقة «الحقول»؛ إعدادات المهام فيها الأنواع وكل الحقول. `lib/tasks.js`: `typeIcons`، `fieldText`، `groupTasksExtra`.
- `TaskSeeder` يضيف الأنواع: مهمة (الافتراضي)، معلم، مخرَج للعميل، جلسة تصوير، منشور، اجتماع.
- الاختبارات: `tests/Feature/TaskFieldsTest.php`.

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

**المرحلة 4 — الخطوة 5 (التقارير):**
1. **الأرباح والخسائر على أساس الاستحقاق**: الإيراد عند إصدار الفاتورة (لا عند التحصيل)، والمصروف عند تاريخه إذا اعتُمد (لا عند الدفع). التحصيل والدفع يظهران في التدفق النقدي.
2. **تكلفة الساعات لا تدخل صافي الربح** في الأرباح والخسائر لأن الرواتب ستُسجَّل مصروفاً (تصنيف «رواتب وأجور») فتُحسب مرتين؛ تظهر سطراً «للعلم». أما ربحية المشاريع والعملاء فتطرح تكلفة الساعات لأنها لا تحمل الرواتب.
3. الربحية حسب الخدمة والموظف **تقديرية بالتوزيع النسبي** (الموضح أسفل الجدول)، لأن المصروف والساعة لا يرتبطان بخدمة بعينها.
4. التقارير بالدولار فقط وبسعر كل مستند؛ لا إعادة تقييم بأسعار اليوم (عدا أرصدة الحسابات في اللوحة وبداية/نهاية التدفق).
5. التقارير للمالية بنطاق «الكل» فقط؛ موديول «التقارير» العام (`reports.*`) يبقى لمرحلة لاحقة. تصدير Excel فقط (PDF والإرسال الأسبوعي بالبريد مؤجلان مع ضبط البريد).

**المرحلة 4 — الخطوة 4 (الميزانيات والربحية):**
1. **الميزانيات بالدولار فقط**، والفعلي يُحوَّل بسعر صرف كل مصروف يوم تاريخه.
2. **ميزانية الشركة** (بلا مشروع ولا مركز) تشمل كل المصروفات المعتمدة في الفترة، بما فيها مصروفات المشاريع والمراكز.
3. الفعلي = **المصروفات المعتمدة فقط** (المعلّقة لا تُحسب، ولا الآجلة غير المسددة؛ الاعتماد هو لحظة الالتزام).
4. **الإيراد في الربحية صافٍ من الضريبة** ومن الفواتير الصادرة فقط (لا المسودات ولا قيمة العقد). قيمة المشروع المتفق عليها تُعرض للمقارنة.
5. الربحية في صفحة المشروع لمن يرى ميزانيته (`manage_budget` أو `approve_changes` أو `finance.view`).
6. التنبيه داخل النظام مرتين لكل بند كحد أقصى (عند النسبة ثم عند التجاوز)، ويعود ممكناً إن رُفع المخطط.
7. مدير المشروع يُنشئ ميزانيات مشاريعه فقط؛ ميزانيات الشركة ومراكز التكلفة للمالية.

**المرحلة 4 — الخطوة 3 (المصروفات والموردون والسلف):**
1. **فاتورة المورد = مصروف «آجل»** في جدول المصروفات نفسه (لا جدول منفصل)، بمبلغ إجمالي واحد لا بنود. تُعتمد كأي مصروف ثم تُسدَّد بدفعات المورد.
2. **كل مصروف يمر بالاعتماد**، حتى ما تدخله المالية؛ ولا يعتمد أحد مصروفه بنفسه (عدا مدير النظام). المدفوع من حساب لا يُنقص الرصيد إلا بعد الاعتماد.
3. **الإيصال إلزامي قبل الاعتماد** (أي مرفق غير فاشل). يمكن إيقافه من `config/finance.php` (`expense_receipt_required`).
4. مدير المشروع الذي يملك `finance.approve` بنطاق «المعيّن» يعتمد مصروفات مشاريعه فقط؛ غير المرتبطة بمشروع تعتمدها المالية.
5. **العهدة النثرية والسلفة في جدول واحد** بنوعين؛ السلفة على الراتب تُسوّى بـ«إرجاع/خصم» بلا حساب حتى يأتي موديول الرواتب. المصروف من عهدة لا يمس رصيد الحساب (المال خرج يوم التسليم).
6. التعويض بعملة واحدة لكل صرفة (عملة الحساب). مصروفات بعملات مختلفة تُعوَّض كلٌّ من حساب بعملتها.
7. الموظف يضيف مورداً جديداً من نموذج المصروف (اسم ونوع وهاتف)؛ تعديله وأرشفته للمالية.
8. لا ضريبة مدخلات منفصلة على المصروفات الآن (المبلغ إجمالي كما في الإيصال).

**المرحلة 4 — الخطوة 2 (المقبوضات والحسابات):**
1. **السند بعملة الحساب الذي دخله المال**، والتسوية على فاتورة بعملة أخرى تُحسب بسعر صرف يوم السند (أو يُكتب المخصوم يدوياً). فرق سعر الصرف لا يُسجَّل كربح/خسارة منفصلة الآن.
2. **السند لا يُحذف ولا يُعدَّل**: الخطأ يُعكس (سبب إلزامي) ويُسجَّل سند جديد. الرد يُعكس أولاً ثم السند الأصلي.
3. **الدفعة الزائدة أو المقدّمة تبقى رصيداً للعميل** على السند نفسه، ويُسوّى لاحقاً من صفحة الفاتورة أو السند، أو يُرد. لا تسوية تلقائية لأقدم فاتورة (التوزيع يقترحه نموذج السند ويُعدَّل).
4. **الحسابات وكشوف العملاء لمن يملك المالية بنطاق «الكل»** فقط، لأنها تكشف كل الأرصدة.
5. كشف العميل وأعمار الذمم **بالدولار بسعر كل مستند** مع المبلغ الأصلي في كل سطر.
6. التذكير بالمتأخرة **لمسؤول الفاتورة داخل النظام** (لا بريد للعميل، فالبريد الصادر غير مضبوط).
7. الرصيد الافتتاحي للحساب رقم يُدخل يدوياً بتاريخ؛ لا قيود محاسبية مزدوجة (النظام إداري لا دفتر أستاذ).

**المرحلة 4 — الخطوة 1 (الفواتير):**
1. **إجمالي الفاتورة يُخزَّن عند الإصدار** (استثناء من «القيم المحسوبة لا تُخزَّن») لأن الصادرة مقفلة كالرقم وسعر الصرف، وتقارير الذمم تجمعها كثيراً. المسودة تُحسب من بنودها.
2. **لا تعديل على الصادرة**: الخطأ يُصحَّح بالإلغاء (سبب إلزامي) ثم «نسخة جديدة». لا إشعارات دائنة (credit notes) الآن.
3. الخصم لكل بند بالنسبة (كالعروض)، لا خصم على مستوى الفاتورة.
4. **الساعات تُفوتر من الأسابيع المعتمدة فقط**، بسعر ساعة يُدخَل عند الإضافة (لا سعر بيع للساعة محفوظ لكل موظف أو خدمة).
5. **مسودات العقود الشهرية تلقائية** قبل الاستحقاق بـ 7 أيام (قابلة للتعديل)، وتبقى مسودة حتى يراجعها صاحب العقد ويصدرها. الإرسال بالبريد غير مفعّل (البريد الصادر غير مضبوط)؛ يُنزَّل PDF ويُرسل يدوياً.
6. الفاتورة الصادرة ملغاة تحرّر دفعات العقد وساعاتها فتُفوتر من جديد.

**ملاحظات ما بعد 3ب:**
1. **Excel بلا مكتبة** (كاتب xlsx داخلي) بدل PhpSpreadsheet، لأن الحاجة جداول ملوّنة فقط؛ أخف على الاستضافة. إن احتجنا رسوماً بيانية أو صيغاً في Excel لاحقاً نضيف المكتبة.
2. ورقة Gantt في Excel **من اليمين لليسار** (الزمن يتقدم نحو اليسار) لأنها ورقة عربية، بخلاف المخطط في الصفحة (frappe-gantt من اليسار لليمين). يمكن قلبها بخيار «ورقة من اليمين لليسار» في Excel.
3. الملف يحوي ما يظهر في العرض فقط (نفس الفلاتر، وحتى 5000 مهمة)، والمهام بلا تواريخ في ورقة «المهام» فقط.
4. لوحة الشجرة المثبتة واختيار طيّ القائمة الجانبية يُحفظان في المتصفح (لا في حساب المستخدم)، فيختلفان بين الأجهزة.

**المرحلة 3ب — الخطوة 6 (المشاركة والضيوف):**
1. **مستويان فقط** للمشاركة: «عرض وتعليق» و«تعديل وإنشاء مهام». إدارة القائمة (الحالات، الحقول، الأتمتة) والإسناد والأرشفة تبقى بالصلاحيات العادية لا بالمشاركة.
2. **الضيف يُدعى برابط تعيين كلمة المرور** يُنسخ ويُرسل يدوياً (واتساب أو بريد)، لا ببريد تلقائي، لأن البريد الصادر غير مضبوط على الخادم بعد. الرابط بصلاحية رابط «نسيت كلمة المرور» (60 دقيقة افتراضياً)، ويمكن توليد رابط جديد من نافذة المشاركة.
3. **التعليقات داخلية افتراضياً** في المهام التي يراها ضيف، حتى لا يصل نقاش الفريق للعميل بالخطأ؛ تُكتب للعميل بتأشير «مرئي للضيوف».
4. الضيف المُسند إلى مهمة يراها ولو لم تُشارَك قائمتها، إلا إن كانت داخلية.
5. الضيوف يظهرون في قوائم اختيار الأشخاص (للإسناد أو الإشارة)، ولا يظهرون في ضغط العمل.
6. بوابة العميل الكاملة (الفواتير، العقود، اعتماد المخرجات) تبقى للمرحلة 6؛ هذا الضيف يرى المهام فقط.

**المرحلة 3ب — الخطوة 5 (نماذج الاستقبال والتدقيق):**
1. **النموذج العام يُنشئ المهمة باسم صاحب النموذج** (لا حساب للعميل بعد)، ويُكتب اسم مقدّم الطلب وبريده في الوصف. مع بوابة العميل (المرحلة 6) يصبح العميل صاحب الطلب.
2. الحماية من الإزعاج في النموذج العام: حد 10 إرسالات بالدقيقة لكل IP وحقل مخفي للبوتات، بلا CAPTCHA (تُضاف إن ظهر سبام).
3. النموذج الداخلي يملؤه **أي موظف مسجّل** حتى لو لم يكن يرى القائمة (طلب تصميم من المبيعات مثلاً)؛ لا يرى المهمة بعد الإرسال إلا إن كانت صلاحياته تسمح.
4. بلا مسؤول تلقائي في النموذج: تبقى المهمة دون مسؤول ويصل إشعار لصاحب النموذج لتوزيعها.
5. **التدقيق على مرفقات المهام** (صور وفيديو)، وجدول التسليمات (deliverables) المذكور في المخطط مؤجل إلى بوابة العميل؛ `proof_comments.author_name` جاهز لتعليقات العميل.
6. الفيديو يُبث عبر خادم BOS من خدمة التخزين دون دعم Range، فالقفز إلى لحظة قد يحتاج تحميل الملف أولاً في بعض المتصفحات. الأفضل أن تكون فيديوهات المراجعة بحجم معقول (نسخة معاينة مضغوطة).
7. النسخة الجديدة تُرفع على آخر نسخة فقط، وكل نسخة لها تعليقاتها؛ النسخ القديمة تبقى في المرفقات بشارة «ن1، ن2…».

**المرحلة 3ب — الخطوة 4 (القوالب والأتمتة):**
1. **الأتمتة متزامنة** (لا Queue): تُنفَّذ أثناء الحفظ، والزمنية كل 15 دقيقة عبر Scheduler. إن صارت ثقيلة لاحقاً ننقلها لطابور.
2. حد التسلسل 3 مستويات لمنع الحلقات (أتمتة تغيّر حالة تُطلق أتمتة تعيدها).
3. إجراء «النشر في قناة» غير موجود لأن موديول التواصل لم يُبنَ؛ يُضاف معه.
4. وصفتا «اعتماد العميل لمخرج ← مسودة فاتورة» و«إرسال رابط المراجعة للعميل» مؤجلتان إلى المالية (المرحلة 4) وبوابة العميل.
5. القوالب عامة للشركة (يراها كل من ينشئ مهام)، ويحذفها صاحبها أو مدير الإعدادات. المسؤولون في القالب يُحفظون كما هم؛ إن لم يُحدَّد أحد يُسند لمن طبّق القالب.
6. حالات «بانتظار العميل يومين» تُحسب من آخر تعديل على المهمة (`updated_at`)، لا من لحظة دخول الحالة تحديداً.
7. وصفة «بداية الشهر» تحتاج أن يكون نطاق الأتمتة مشروعاً (أو أن يُحدَّد المشروع في الإجراء)، وتعمل في أول تشغيل بعد منتصف ليل اليوم الأول.

**المرحلة 3ب — الخطوة 3 (ضغط العمل وجداول الساعات):**
1. **أيام العمل الافتراضية الاثنين–الجمعة** (مكتب إسطنبول) و8 ساعات يومياً، وتُعدّل لكل شخص. أما «الأسبوع» في جداول الساعات و«وقتي» فيبدأ السبت كما في 3أ. راجع إن كان الأسبوع عندكم غير ذلك.
2. **الإجازات جدول بسيط مؤقت** (`time_off`) يسجّلها الشخص لنفسه أو المدير؛ حين يُبنى موديول HR (المرحلة 5) تصبح طلبات الإجازة المعتمدة مصدرها.
3. توزيع التقدير بالتساوي على أيام العمل وعلى المسؤولين (لا تقدير لكل مسؤول على حدة الآن). المهام المتأخرة غير المغلقة لا تظهر في الأسابيع اللاحقة (تُحسب في أيام تواريخها فقط).
4. **الأسبوع المرفوض يُفتح للتعديل تلقائياً**، والمعتمد يُقفل حتى «إعادة فتح» من المعتمِد. الفوترة (المرحلة 4) ستعتمد على الأسابيع المعتمدة.
5. لا تذكير تلقائياً بإرسال جدول الأسبوع الآن (يمكن إضافته كأتمتة في الخطوة التالية).

**المرحلة 3ب — الخطوة 2 (الاعتماديات وGantt والتكرار):**
1. **الاعتمادية الوحيدة «إنهاء ← بدء»** (لا تنتظر المهمة إلا إنهاء ما قبلها). إغلاقها قبل ذلك مسموح بعد تأكيد، لا ممنوع.
2. **تحريك التوابع تلقائي ودائم**: أي تغيير في تسليم مهمة (للأمام أو للخلف) يحرّك كل ما ينتظرها بالمقدار نفسه ويحفظ الفجوات. لا خيار لإيقافه الآن.
3. **Gantt بمكتبة frappe-gantt** (مجانية، بلا دعم RTL، فالمخطط من اليسار لليمين كالمعتاد). المسار الحرج تقريبي (سلسلة أحدث المُعطِّلات) لا حساب CPM كامل بالمرونة (float).
4. **التكرار ينشئ مهمة جديدة** (بمعرّف جديد) بدل إعادة فتح المهمة نفسها، فيبقى سجل كل نسخة منفصلاً. القاعدة تنتقل إلى النسخة الجديدة.
5. المهمة المتكررة المغلقة متأخرة تقفز نسختها التالية إلى أول موعد بعد اليوم (لا تُنشأ نسخ في الماضي). التكرار يحتاج تاريخ تسليم ليُحسب.
6. «بالجدول» تُنشأ النسخة التالية يوم تسليم الحالية ولو لم تُغلق (مناسب للمنشورات والتقارير الدورية).

**المرحلة 3ب — الخطوة 1 (الحقول والأنواع):**
1. **ربط الحقل بأنواع يقصره عليها**: حقل «المنصة» المربوط بنوع «منشور» لا يظهر إلا على المنشورات (ضمن نطاقه). بلا ربط يظهر على كل الأنواع.
2. **الحقول تُدار بنطاقات** (الكل/عميل/مشروع/قائمة) بدل «مجلد» ClickUp؛ لا وراثة أعمق من ذلك.
3. **الصيغة تحسب على الحقول الرقمية فقط** (رقم، مبلغ، نسبة، تقييم، مربع اختيار = 1/0) والتقدير بالساعات، والقيمة الناقصة = 0. لا دوال (مثل IF/SUM) الآن. الصيغة لا تُفرز ولا تُفلتر لأنها لا تُخزن.
4. حقل «ملف» يختار من مرفقات المهمة نفسها (لا رفع مستقل).
5. حذف الحقل ناعم: قيمه تبقى في القاعدة ولا تظهر. حذف النوع ينقل مهامه إلى النوع الافتراضي.
6. فلترة الواجهة متاحة للحقول المنسدلة ومربعات الاختيار؛ بقية الأنواع تُفرز ولا تُفلتر من الواجهة.
7. «معلم» نوع مهمة (علامة `is_milestone`) وليس جدولاً جديداً؛ مراحل المشروع (`project_milestones`) تبقى كما هي.

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

**المرحلة 3أ — الخطوة 3 (العروض):**
1. **التصدير CSV لا XLSX**: يفتح في Excel بالعربية بلا مكتبة إضافية على الخادم. تصدير Excel أصلي يمكن إضافته مع موديول التقارير.
2. **الجدول مبني يدوياً** (بلا TanStack/AG Grid) لأن الحاجة الحالية أعمدة وتعديل وتحديد وفرز؛ أخف وأسهل صيانة.
3. **في اللوحة المجمّعة بالمسؤول** تظهر المهمة متعددة المسؤولين في عمود كل منهم، وسحبها من عمود إلى آخر يستبدل ذلك المسؤول بالجديد ويُبقي الآخرين.
4. أعمدة «التسليم» في اللوحة: الإفلات مسموح في «اليوم» و«غداً» و«بلا تاريخ» فقط (البقية نطاقات لا تاريخ محدد لها). التجميع بالقائمة غير متاح في اللوحة لأن النقل بين القوائم له قواعد (العميل نفسه).
5. العروض تعرض حتى 1000 مهمة؛ أكثر من ذلك يحتاج تضييق الفلاتر (لا ترقيم صفحات في العروض).
6. **العرض المحفوظ يتبع موقعه** (قائمة أو مشروع أو عميل) أو يكون لكل المهام؛ «مشترك» يعني لكل الفريق (لا مشاركة مع أشخاص محددين).
7. التحديث اللحظي للوحات (حين يحرّك زميل مهمة) غير مبني: يحتاج WebSockets (مؤجل حسب المخطط). الصفحة تتحدث عند أي تعديل منك أو بإعادة التحميل.

**المرحلة 3أ — الخطوة 4 (عملي والوقت):**
1. **تكلفة الساعة على المستخدم** (`users.hourly_cost` بالدولار) لأن موديول الموارد البشرية لم يُبنَ بعد؛ تنتقل إلى ملف الموظف في المرحلة 5 مع بقاء العمود. تُعدَّل من «المستخدمون» لمن يملك `hr.view_salaries` فقط.
2. **كل قيد وقت يحفظ تكلفة الساعة لحظة تسجيله** (`cost_rate`) مثل حفظ سعر الصرف مع المستندات المالية، فتغيير تكلفة الشخص لاحقاً لا يغيّر تكاليف المشاريع السابقة. القيد بلا تكلفة (الشخص بلا تكلفة ساعة) يُعرض كـ«دقائق بلا تكلفة».
3. **تكلفة المشروع الفعلية = تكلفة الساعات فقط الآن** (بالدولار)؛ المصروفات تُضاف مع المالية (المرحلة 4). تظهر لمن يرى الميزانية.
4. الوقت القابل للفوترة افتراضياً: مهام العملاء نعم، ومهام Insight Media الداخلية والوقت بلا مهمة لا؛ ويُغيَّر لكل قيد.
5. المؤقت المنسي لا يتوقف تلقائياً؛ يظهر في الترويسة دائماً ويمكن تعديل مدته بعد إيقافه.
6. القيد اليدوي بالمدة فقط يُسجَّل بدءاً من 09:00 في ذلك اليوم (للترتيب)؛ لتسجيل الوقت الفعلي استخدم «من/إلى».
7. **ملخص الصباح** (08:30 بتوقيت إسطنبول) داخل النظام فقط، ولا يُرسل إن لم يكن هناك ما يُذكر.
8. «مواعيد» تقويمي = أنشطة CRM المسندة إليّ (اجتماعات ومكالمات ومتابعات). لا تُسحب من التقويم (تُعدَّل من CRM).
9. «عملي» يعرض المهام **المسندة إليّ** فقط (لا المتابَعة)؛ المتابَعة تصل عبر الإشعارات.

**الخطوة 6 (التخزين والمرفقات):**
1. **Google Drive بنطاق `drive.file`** (لا يرى إلا ما أنشأه النظام) بدل الوصول لكل الـ Drive. أأمن، لكن الملفات التي تُضاف يدوياً إلى مجلدات BOS من Drive لا تظهر في النظام (النظام لا يقرأ المجلدات أصلاً حالياً).
2. **الرفع متزامن داخل الطلب** (لا Queue worker على Plesk)، مع نسخة مؤقتة على الخادم حتى ينجح الرفع، وإعادة محاولة تلقائية كل 10 دقائق (5 مرات) ثم يدوياً. الملفات الكبيرة جداً تحتاج رفع حدود PHP (انظر DEPLOY).
3. **الحذف من BOS يحذف من الخدمة** (Google: سلة المحذوفات، OneDrive: سلة المحذوفات، Dropbox: قابل للاسترجاع، WebDAV: حذف نهائي). إن كان الاتصال مفصولاً يُحذف من BOS فقط.
4. **WD My Cloud عبر WebDAV**: الملف يُفتح ببثه عبر خادم BOS (لا رابط مباشر). أجهزة My Cloud الحديثة (OS 5) قد لا تدعم WebDAV أصلاً؛ إن كان جهازكم منها فالخيار العملي أحد المزودين السحابيين.
5. أسماء المجلدات عربية (`العملاء`، `المشاريع`، `العقود`، `عروض الأسعار`) ومجلد العميل `<الاسم> #<id>` لتفادي تكرار الأسماء.
6. المرفقات مفعّلة الآن على العملاء والمشاريع والعقود وعروض الأسعار فقط (الفرص والمحتملون بلا مجلد عميل ثابت).
7. تغيير المزود لا ينقل الملفات القديمة: تبقى مرتبطة بخدمتها وتُفتح منها، والجديدة تذهب للمزود الجديد.
8. عرض الملفات يتبع صلاحية «عرض» السجل، والرفع والحذف يتبعان «تعديل» السجل (لا صلاحية منفصلة للملفات).


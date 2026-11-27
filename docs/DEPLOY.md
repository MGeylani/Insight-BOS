# نشر Insight BOS على Plesk

## كيف يعمل النشر

1. كل دفعة إلى فرع `main` تشغّل GitHub Actions: تثبيت الحزم، بناء الواجهة، تشغيل الاختبارات.
2. إذا نجحت الاختبارات، يُنشأ فرع `deploy` فيه نسخة جاهزة للتشغيل (مع `vendor` والواجهة المبنية).
3. Plesk يسحب فرع `deploy` فقط، فلا يحتاج الخادم إلى Composer أو Node.

> قبل أول تشغيل: في GitHub افتح Settings ← Actions ← General ← Workflow permissions، واختر **Read and write permissions**.

## الإعداد لأول مرة (test.insightpromedia.com)

1. **Plesk ← الدومين ← Laravel** (من صفحة Get Started).
2. اختر التثبيت من مستودع Git:
   - الرابط: `https://github.com/MGeylani/Insight-BOS.git`
   - الفرع: `deploy`
   - المستودع خاص: سيعرض Plesk **مفتاح SSH عام**. أضفه في GitHub: Settings ← Deploy keys ← Add deploy key (قراءة فقط).
3. جذر الموقع (Document root): `httpdocs/public` (يضبطه Laravel Toolkit تلقائياً).
4. إصدار PHP: 8.4.
5. ملف `.env` (من Laravel Toolkit ← Environment): انسخ `.env.example` ثم عدّل:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://test.insightpromedia.com
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_DATABASE=...        # من Plesk ← Databases
DB_USERNAME=...
DB_PASSWORD=...
MAIL_MAILER=smtp       # بيانات بريد الإرسال (لإعادة تعيين كلمات المرور)
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
ADMIN_NAME="محمد نور"
ADMIN_EMAIL=...
ADMIN_PASSWORD=...     # احذفه من الملف بعد أول دخول
```

6. من Laravel Toolkit ← Artisan، نفّذ بالترتيب:

```
key:generate --force
migrate --force
db:seed --force
storage:link
config:cache
route:cache
view:cache
```

7. **النشر التلقائي:** في Plesk ← Git للدومين، فعّل «Automatic deployment» وانسخ رابط Webhook، ثم أضفه في GitHub: Settings ← Webhooks ← Add webhook (Content type: `application/json`، الحدث: push).
8. **أوامر بعد كل سحب** (Plesk ← Git ← Additional deployment actions):

```
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

9. **المهام المجدولة:** فعّل Scheduler من Laravel Toolkit (يشغّل `php artisan schedule:run` كل دقيقة).

## بعد أول دخول

- غيّر كلمة مرور المدير من «حسابي»، واحذف `ADMIN_PASSWORD` من `.env`.
- أدخل أسعار صرف اليوم من «العملات وأسعار الصرف».
- راجع بيانات الشركة وتصنيفات الخدمات.
- أنشئ حسابات الفريق من «المستخدمون».

## ربط التخزين السحابي (المرحلة 2، الخطوة 6)

اختر المزود من «الإعدادات ← التخزين السحابي» واحفظ، ثم اربط الحساب. مزودو OAuth يحتاجون تطبيقاً تنشئه مرة واحدة ومفاتيحه في `.env` على الخادم. رابط إعادة التوجيه يظهر في صفحة الإعدادات، وصيغته:
`https://test.insightpromedia.com/settings/storage/callback/<google_drive|onedrive|dropbox>`

**Google Drive** — [Google Cloud Console](https://console.cloud.google.com/):
1. أنشئ مشروعاً، وفعّل **Google Drive API** (APIs & Services ← Library).
2. OAuth consent screen: النوع Internal إن كان حساب Google Workspace، وأضف النطاق `.../auth/drive.file`.
3. Credentials ← Create credentials ← OAuth client ID ← Web application، وأضف رابط إعادة التوجيه أعلاه.
4. في `.env`: `GOOGLE_DRIVE_CLIENT_ID=` و`GOOGLE_DRIVE_CLIENT_SECRET=`.

**OneDrive** — [Microsoft Entra](https://entra.microsoft.com/) ← App registrations ← New registration:
1. الحسابات المدعومة: حسب حسابك (للحساب المؤسسي فقط اختر «Single tenant» وضع معرّف المستأجر في `ONEDRIVE_TENANT`).
2. Redirect URI من نوع Web = الرابط أعلاه.
3. Certificates & secrets ← New client secret. API permissions ← Microsoft Graph (Delegated): `Files.ReadWrite`، `User.Read`، `offline_access`.
4. في `.env`: `ONEDRIVE_CLIENT_ID=` (Application ID) و`ONEDRIVE_CLIENT_SECRET=` (قيمة السر لا معرّفه) و`ONEDRIVE_TENANT=common`.

**Dropbox** — [App Console](https://www.dropbox.com/developers/apps) ← Create app:
1. Scoped access، والنوع App folder (الأسلم) أو Full Dropbox.
2. Permissions: `account_info.read`، `files.metadata.read`، `files.metadata.write`، `files.content.read`، `files.content.write` ثم Submit.
3. Settings ← Redirect URIs: أضف الرابط أعلاه.
4. في `.env`: `DROPBOX_APP_KEY=` و`DROPBOX_APP_SECRET=`.

**WD My Cloud** — لا يحتاج مفاتيح: فعّل الوصول عن بُعد/WebDAV على الجهاز، ثم أدخل العنوان واسم المستخدم وكلمة المرور في صفحة الإعدادات.

بعد تعديل `.env` نفّذ `config:cache` من Laravel Toolkit. ارفع حدود PHP في Plesk (PHP Settings) لتناسب «الحد الأقصى لحجم الملف»: `upload_max_filesize` و`post_max_size` (مثلاً 512M)، و`max_execution_time` (مثلاً 600) لأن الرفع إلى الخدمة يتم أثناء الطلب. الملفات التي يفشل رفعها يُعاد رفعها كل 10 دقائق عبر Scheduler.

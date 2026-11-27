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

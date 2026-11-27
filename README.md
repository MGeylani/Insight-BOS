# Insight BOS

منظومة تشغيل Insight Media: العملاء والمشاريع والمهام والموارد البشرية والأصول والمالية والتواصل الداخلي.

- الخادم: Laravel 13 (PHP 8.4) + MariaDB
- الواجهة: Vue 3 + Inertia + Tailwind CSS 4، عربية RTL بهوية Insight Media (خط Madani Arabic، ألوان دليل الهوية)
- الصلاحيات: صلاحية لكل شخص بصيغة `module.action` ونطاق (`own` / `assigned` / `all`)، منفصلة عن المسمى الوظيفي، مع قوالب اختيارية.

## التشغيل محلياً

```
bash scripts/restore-skeleton.sh   # ملفات Laravel الافتراضية غير المتتبعة
composer install
cp .env.example .env && php artisan key:generate
# اضبط قاعدة البيانات في .env، أو استخدم SQLite: DB_CONNECTION=sqlite
php artisan migrate --seed
npm install && npm run dev
php artisan serve
```

## الاختبارات

```
php artisan test
```

## للجلسات الجديدة

ابدأ بقراءة [docs/HANDOFF.md](docs/HANDOFF.md): الحالة، الأعراف، ونطاق المرحلة التالية.

## النشر

انظر [docs/DEPLOY.md](docs/DEPLOY.md).

## المراحل

| المرحلة | المحتوى | الحالة |
| --- | --- | --- |
| 1 | الأساس: الدخول، المستخدمون والصلاحيات، سجل التدقيق، الإعدادات (الشركة، العملات وأسعار الصرف اليومية، تصنيفات الخدمات والضريبة، الترقيم، مزود التخزين) | ✅ |
| 2 | العملاء والمشاريع والعقود + ربط التخزين السحابي | ⏳ |
| 3أ | المهام بمستوى ClickUp (الجزء الأول) | ⏳ |
| 4 | المالية + الإطلاق الأول | ⏳ |
| 5 | المهام (ب)، الموارد البشرية، الأصول | ⏳ |
| 6 | التواصل والتقارير وبوابة العميل | ⏳ |

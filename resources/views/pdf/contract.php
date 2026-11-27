<?php
/**
 * Contract PDF (rendered by mPDF). Plain PHP view, same visual language as pdf/quote.php.
 *
 * @var array $d  prepared by App\Services\Sales\ContractPdf::data()
 */
$m = fn ($v) => number_format((float) $v, 2);
$k = $d['contract'];
$c = $d['client'];
$co = $d['company'];
$retainer = $k['type'] === 'retainer';
$n = 0; // section counter
?>
<html dir="rtl" lang="ar">
<head>
<style>
    body { font-family: madani; font-size: 10pt; color: #1e293b; direction: rtl; }
    .navy { color: #00027C; }
    .muted { color: #64748b; }
    .small { font-size: 8.5pt; }
    .num { font-family: madani; direction: ltr; }
    table { border-collapse: collapse; }
    .head td { vertical-align: middle; }
    .doc-title { font-size: 22pt; font-weight: bold; color: #00027C; }
    .doc-type { font-size: 10pt; color: #0082F6; font-weight: bold; }
    .meta td { padding: 1.5pt 0 1.5pt 10pt; font-size: 9.5pt; }
    .meta .k { color: #64748b; }
    .band { height: 4pt; background: linear-gradient(to left, #00027C, #0082F6, #00BEF2); margin: 10pt 0 14pt; }
    .party { width: 100%; }
    .party td.box { width: 49%; vertical-align: top; background: #f4f6fb; border-radius: 6pt; padding: 9pt 11pt; }
    .party .lbl { font-size: 8.5pt; color: #0082F6; font-weight: bold; }
    .party .name { font-size: 12pt; font-weight: bold; color: #00027C; }
    .subject { font-size: 13pt; font-weight: bold; color: #00027C; margin: 16pt 0 2pt; }
    .lead { margin: 0 0 6pt; line-height: 1.6; }
    .section { margin-top: 14pt; }
    .section h3 { font-size: 10.5pt; color: #00027C; margin: 0 0 5pt; border-bottom: 1pt solid #00BEF2; padding-bottom: 3pt; }
    .section .body { line-height: 1.7; font-size: 9.5pt; }
    .facts { width: 100%; }
    .facts td { padding: 4pt 6pt; font-size: 9.5pt; border-bottom: 0.6pt solid #e2e8f0; }
    .facts td.k { color: #64748b; width: 30%; }
    .grid { width: 100%; margin-top: 2pt; }
    .grid th { background: #00027C; color: #ffffff; font-weight: bold; font-size: 9pt; padding: 6pt; text-align: right; }
    .grid td { padding: 6pt; border-bottom: 0.6pt solid #e2e8f0; vertical-align: top; font-size: 9.5pt; }
    .grid tr.alt td { background: #f8fafc; }
    .grid .c { text-align: center; }
    .grid .e { text-align: left; }
    .grid .det { font-size: 8.5pt; color: #64748b; }
    .grid tr.total td { background: #00027C; color: #fff; font-weight: bold; }
    .sign { width: 100%; margin-top: 26pt; }
    .sign td.box { width: 48%; vertical-align: top; border: 0.8pt solid #cbd5e1; border-radius: 6pt; padding: 9pt 11pt; }
    .sign .lbl { font-size: 9pt; color: #0082F6; font-weight: bold; margin-bottom: 4pt; }
    .sign .row { font-size: 9pt; color: #64748b; padding-top: 14pt; border-bottom: 0.8pt solid #94a3b8; }
</style>
</head>
<body>

<table class="head" width="100%">
    <tr>
        <td width="50%"><img src="<?= e($d['logo']) ?>" style="height: 46pt;" /></td>
        <td width="50%" style="text-align: left;">
            <div class="doc-title">عقد تقديم خدمات</div>
            <div class="doc-type"><?= e($k['type_label']) ?></div>
            <table class="meta" align="left">
                <tr><td class="k">رقم العقد</td><td class="num navy" style="font-weight: bold;"><?= e($k['number'] ?? 'مسودة') ?></td></tr>
                <tr><td class="k">تاريخ الإصدار</td><td class="num"><?= e($k['issue_date'] ?? '—') ?></td></tr>
                <tr><td class="k">العملة</td><td class="num"><?= e($k['currency']) ?></td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="band"></div>

<table class="party">
    <tr>
        <td class="box">
            <div class="lbl">الطرف الأول (مقدّم الخدمة)</div>
            <div class="name"><?= e($co['name']) ?></div>
            <?php if ($co['legal_name'] && $co['legal_name'] !== $co['name']) { ?><div class="small muted"><?= e($co['legal_name']) ?></div><?php } ?>
            <?php if ($co['phone'] || $co['email']) { ?><div class="small num" style="text-align: right;"><?= e(trim($co['phone'].'   '.$co['email'])) ?></div><?php } ?>
            <?php if ($co['address']) { ?><div class="small muted"><?= e($co['address']) ?></div><?php } ?>
            <?php if ($co['tax_number']) { ?><div class="small muted">الرقم الضريبي: <span class="num"><?= e($co['tax_number']) ?></span></div><?php } ?>
        </td>
        <td width="2%"></td>
        <td class="box">
            <div class="lbl">الطرف الثاني (العميل)</div>
            <div class="name"><?= e($c['name']) ?></div>
            <?php if ($c['legal_name'] && $c['legal_name'] !== $c['name']) { ?><div class="small muted"><?= e($c['legal_name']) ?></div><?php } ?>
            <?php if ($c['contact']) { ?><div>يمثله: <?= e($c['contact']) ?></div><?php } ?>
            <?php if ($c['phone'] || $c['email']) { ?><div class="small num" style="text-align: right;"><?= e(trim($c['phone'].'   '.$c['email'])) ?></div><?php } ?>
            <?php if ($c['address']) { ?><div class="small muted"><?= e($c['address']) ?></div><?php } ?>
            <?php if ($c['tax_number']) { ?><div class="small muted">الرقم الضريبي: <span class="num"><?= e($c['tax_number']) ?></span></div><?php } ?>
        </td>
    </tr>
</table>

<div class="subject"><?= e($k['title']) ?></div>
<div class="lead">
    اتفق الطرفان المذكوران أعلاه على أن يقدّم الطرف الأول للطرف الثاني الخدمات المبيّنة في هذا العقد، وفق المدة والمقابل والشروط التالية<?php if ($k['quote_number']) { ?>، استناداً إلى عرض السعر رقم <span class="num"><?= e($k['quote_number']) ?></span><?php } ?>.
</div>

<?php if ($k['scope']) { ?>
<div class="section"><h3><?= ++$n ?>. موضوع العقد ونطاق العمل</h3><div class="body"><?= nl2br(e($k['scope'])) ?></div></div>
<?php } ?>

<div class="section">
    <h3><?= ++$n ?>. المدة والمقابل المالي</h3>
    <table class="facts">
        <tr><td class="k">تاريخ البدء</td><td class="num" style="text-align: right;"><?= e($k['start_date'] ?? '—') ?></td></tr>
        <?php if ($retainer) { ?>
            <tr><td class="k">مدة العقد</td><td><span class="num"><?= e((string) $k['term_months']) ?></span> شهراً<?php if ($k['end_date']) { ?>، حتى <span class="num"><?= e($k['end_date']) ?></span><?php } ?></td></tr>
            <tr><td class="k">الرسوم الشهرية</td><td><span class="num" style="font-weight: bold;"><?= $m($k['monthly_fee']) ?></span> <?= e($k['currency']) ?></td></tr>
            <tr><td class="k">موعد الدفع</td><td><?= e($k['billing_timing'] ?? '—') ?></td></tr>
            <tr><td class="k">التجديد</td><td><?= $k['auto_renew'] ? 'يتجدد تلقائياً لمدة مماثلة ما لم يُخطر أحد الطرفين الآخر بعدم الرغبة في التجديد' : 'لا يتجدد تلقائياً، ويجوز تجديده باتفاق الطرفين' ?><?php if ($k['notice_days']) { ?> قبل <span class="num"><?= e((string) $k['notice_days']) ?></span> يوماً من انتهائه<?php } ?>.</td></tr>
        <?php } else { ?>
            <?php if ($k['end_date']) { ?><tr><td class="k">تاريخ الانتهاء المتوقع</td><td class="num" style="text-align: right;"><?= e($k['end_date']) ?></td></tr><?php } ?>
            <tr><td class="k">قيمة العقد الإجمالية</td><td><span class="num" style="font-weight: bold;"><?= $m($k['value']) ?></span> <?= e($k['currency']) ?></td></tr>
            <?php if ($k['notice_days']) { ?><tr><td class="k">مهلة الإخطار بالفسخ</td><td><span class="num"><?= e((string) $k['notice_days']) ?></span> يوماً</td></tr><?php } ?>
        <?php } ?>
    </table>
</div>

<?php if ($d['payments']) { ?>
<div class="section">
    <h3><?= ++$n ?>. جدول الدفعات</h3>
    <table class="grid">
        <thead>
            <tr>
                <th class="c" width="6%">#</th>
                <th>البيان</th>
                <th class="c" width="<?= $retainer ? '30' : '18' ?>%"><?= $retainer ? 'الفترة' : 'تاريخ الاستحقاق' ?></th>
                <?php if (! $retainer) { ?><th class="c" width="10%">النسبة</th><?php } ?>
                <th class="e" width="18%">المبلغ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($d['payments'] as $i => $p) { ?>
            <tr class="<?= $i % 2 ? 'alt' : '' ?>">
                <td class="c num"><?= $i + 1 ?></td>
                <td><?= e($p['title']) ?><?php if ($p['details']) { ?><div class="det"><?= nl2br(e($p['details'])) ?></div><?php } ?></td>
                <?php if ($retainer) { ?>
                    <td class="c small"><span class="num"><?= e($p['period_start'] ?? '') ?></span> إلى <span class="num"><?= e($p['period_end'] ?? '') ?></span></td>
                <?php } else { ?>
                    <td class="c num"><?= e($p['due_date'] ?? '—') ?></td>
                    <td class="c num"><?= $p['percent'] !== null ? e($p['percent']).'%' : '' ?></td>
                <?php } ?>
                <td class="e num"><?= $m($p['amount']) ?></td>
            </tr>
            <?php } ?>
            <tr class="total">
                <td colspan="<?= $retainer ? 3 : 4 ?>">المجموع</td>
                <td class="e num"><?= $m($d['payments_total']) ?> <?= e($k['currency']) ?></td>
            </tr>
        </tbody>
    </table>
</div>
<?php } ?>

<?php if ($d['milestones']) { ?>
<div class="section">
    <h3><?= ++$n ?>. مراحل التنفيذ والتسليم</h3>
    <table class="grid">
        <thead><tr><th class="c" width="6%">#</th><th>المرحلة</th><th class="c" width="20%">الموعد المتوقع</th></tr></thead>
        <tbody>
            <?php foreach ($d['milestones'] as $i => $ms) { ?>
            <tr class="<?= $i % 2 ? 'alt' : '' ?>">
                <td class="c num"><?= $i + 1 ?></td>
                <td><?= e($ms['title']) ?><?php if ($ms['details']) { ?><div class="det"><?= nl2br(e($ms['details'])) ?></div><?php } ?></td>
                <td class="c num"><?= e($ms['due_date'] ?? '—') ?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
<?php } ?>

<?php if ($k['terms']) { ?>
<div class="section"><h3><?= ++$n ?>. الشروط والأحكام</h3><div class="body"><?= nl2br(e($k['terms'])) ?></div></div>
<?php } ?>
<?php if ($k['notes']) { ?>
<div class="section"><h3><?= ++$n ?>. ملاحظات</h3><div class="body"><?= nl2br(e($k['notes'])) ?></div></div>
<?php } ?>

<div class="lead" style="margin-top: 14pt;">حُرِّر هذا العقد من نسختين متطابقتين، بيد كل طرف نسخة للعمل بموجبها.</div>

<table class="sign" autosize="1">
    <tr>
        <td class="box">
            <div class="lbl">عن الطرف الأول — <?= e($co['name']) ?></div>
            <div class="row">الاسم: <?= e($d['owner'] ?? '') ?></div>
            <div class="row">التوقيع والختم:</div>
            <div class="row">التاريخ:</div>
        </td>
        <td width="4%"></td>
        <td class="box">
            <div class="lbl">عن الطرف الثاني — <?= e($c['name']) ?></div>
            <div class="row">الاسم: <?= e($k['client_signatory'] ?? $c['contact'] ?? '') ?></div>
            <div class="row">التوقيع والختم:</div>
            <div class="row">التاريخ: <?php if ($k['signed_on']) { ?><span class="num"><?= e($k['signed_on']) ?></span><?php } ?></div>
        </td>
    </tr>
</table>

</body>
</html>

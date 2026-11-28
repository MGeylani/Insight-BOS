<?php
/**
 * Invoice PDF (rendered by mPDF). Same layout as the quote PDF.
 *
 * @var array $d  prepared by App\Services\Finance\InvoicePdf::data()
 */
$m = fn ($v) => number_format((float) $v, 2);
$q = $d['invoice'];
$c = $d['client'];
$co = $d['company'];
$t = $d['totals'];
$hasDiscount = $t['discount'] > 0;
$hasTax = $t['tax'] > 0;
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
    .doc-title { font-size: 24pt; font-weight: bold; color: #00027C; }
    .meta td { padding: 1.5pt 0 1.5pt 10pt; font-size: 9.5pt; }
    .meta .k { color: #64748b; }
    .band { height: 4pt; background: linear-gradient(to left, #00027C, #0082F6, #00BEF2); margin: 10pt 0 14pt; }
    .party { width: 100%; }
    .party td.box { width: 49%; vertical-align: top; background: #f4f6fb; border-radius: 6pt; padding: 9pt 11pt; }
    .party .lbl { font-size: 8.5pt; color: #0082F6; font-weight: bold; }
    .party .name { font-size: 12pt; font-weight: bold; color: #00027C; }
    .subject { font-size: 13pt; font-weight: bold; color: #00027C; margin: 16pt 0 4pt; }
    .intro { margin: 0 0 10pt; line-height: 1.6; }
    .items { width: 100%; margin-top: 6pt; }
    .items th { background: #00027C; color: #ffffff; font-weight: bold; font-size: 9pt; padding: 6pt 6pt; text-align: right; }
    .items td { padding: 7pt 6pt; border-bottom: 0.6pt solid #e2e8f0; vertical-align: top; }
    .items tr.alt td { background: #f8fafc; }
    .items .c { text-align: center; }
    .items .e { text-align: left; }
    .items .svc { font-weight: bold; color: #0f172a; }
    .items .det { font-size: 8.5pt; color: #64748b; line-height: 1.5; }
    .totals { width: 44%; margin-top: 8pt; }
    .totals td { padding: 4pt 8pt; }
    .totals .e { text-align: left; }
    .totals .grand td { background: #00027C; color: #fff; font-weight: bold; font-size: 11.5pt; padding: 7pt 8pt; }
    .section { margin-top: 16pt; }
    .section h3 { font-size: 10.5pt; color: #00027C; margin: 0 0 4pt; border-bottom: 1pt solid #00BEF2; padding-bottom: 3pt; }
    .section div { line-height: 1.65; font-size: 9.5pt; }
    .accept { width: 100%; margin-top: 22pt; }
    .accept td { width: 33%; padding: 0 0 0 12pt; vertical-align: bottom; font-size: 9pt; color: #64748b; }
    .accept .line { border-bottom: 0.8pt solid #94a3b8; height: 22pt; }
</style>
</head>
<body>

<table class="head" width="100%">
    <tr>
        <td width="50%"><img src="<?= e($d['logo']) ?>" style="height: 46pt;" /></td>
        <td width="50%" style="text-align: left;">
            <div class="doc-title">فاتورة</div>
            <table class="meta" align="left">
                <tr><td class="k">رقم الفاتورة</td><td class="num navy" style="font-weight: bold;"><?= e($q['number'] ?? 'مسودة') ?></td></tr>
                <tr><td class="k">تاريخ الإصدار</td><td class="num"><?= e($q['issue_date'] ?? '—') ?></td></tr>
                <tr><td class="k">تاريخ الاستحقاق</td><td class="num"><?= e($q['due_date'] ?? '—') ?></td></tr>
                <?php if ($q['reference']) { ?><tr><td class="k">المرجع</td><td class="num"><?= e($q['reference']) ?></td></tr><?php } ?>
                <tr><td class="k">العملة</td><td class="num"><?= e($q['currency']) ?></td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="band"></div>

<table class="party">
    <tr>
        <td class="box">
            <div class="lbl">فاتورة إلى</div>
            <div class="name"><?= e($c['name']) ?></div>
            <?php if ($c['legal_name'] && $c['legal_name'] !== $c['name']) { ?><div class="small muted"><?= e($c['legal_name']) ?></div><?php } ?>
            <?php if ($c['contact']) { ?><div>عناية: <?= e($c['contact']) ?></div><?php } ?>
            <?php if ($c['phone'] || $c['email']) { ?><div class="small num" style="text-align: right;"><?= e(trim($c['phone'].'   '.$c['email'])) ?></div><?php } ?>
            <?php if ($c['address']) { ?><div class="small muted"><?= e($c['address']) ?></div><?php } ?>
            <?php if ($c['tax_number']) { ?><div class="small muted">الرقم الضريبي: <span class="num"><?= e($c['tax_number']) ?></span></div><?php } ?>
        </td>
        <td width="2%"></td>
        <td class="box">
            <div class="lbl">من</div>
            <div class="name"><?= e($co['name']) ?></div>
            <?php if ($co['legal_name'] && $co['legal_name'] !== $co['name']) { ?><div class="small muted"><?= e($co['legal_name']) ?></div><?php } ?>
            
            <?php if ($co['phone'] || $co['email']) { ?><div class="small num" style="text-align: right;"><?= e(trim($co['phone'].'   '.$co['email'])) ?></div><?php } ?>
            <?php if ($co['address']) { ?><div class="small muted"><?= e($co['address']) ?></div><?php } ?>
            <?php if ($co['tax_number']) { ?><div class="small muted">الرقم الضريبي: <span class="num"><?= e($co['tax_number']) ?></span></div><?php } ?>
        </td>
    </tr>
</table>

<div class="subject"><?= e($q['title']) ?></div>
<?php if ($q['intro']) { ?><div class="intro"><?= nl2br(e($q['intro'])) ?></div><?php } ?>

<table class="items">
    <thead>
        <tr>
            <th class="c" width="5%">#</th>
            <th>الخدمة</th>
            <th class="c" width="11%">الكمية</th>
            <th class="e" width="15%">سعر الوحدة</th>
            <?php if ($hasDiscount) { ?><th class="c" width="9%">الخصم</th><?php } ?>
            <?php if ($hasTax) { ?><th class="c" width="9%">الضريبة</th><?php } ?>
            <th class="e" width="16%">المجموع</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($d['items'] as $i => $it) { ?>
        <tr class="<?= $i % 2 ? 'alt' : '' ?>">
            <td class="c num"><?= $i + 1 ?></td>
            <td>
                <div class="svc"><?= e($it['name']) ?></div>
                <?php if ($it['details']) { ?><div class="det"><?= nl2br(e($it['details'])) ?></div><?php } ?>
            </td>
            <td class="c"><span class="num"><?= e($it['quantity']) ?></span><?php if ($it['unit']) { ?> <span class="small muted"><?= e($it['unit']) ?></span><?php } ?></td>
            <td class="e num"><?= $m($it['unit_price']) ?></td>
            <?php if ($hasDiscount) { ?><td class="c num"><?= (float) $it['discount_percent'] > 0 ? e($it['discount_percent_label']).'%' : '—' ?></td><?php } ?>
            <?php if ($hasTax) { ?><td class="c num"><?= (float) $it['tax_rate'] > 0 ? e($it['tax_rate_label']).'%' : '—' ?></td><?php } ?>
            <td class="e num" style="font-weight: bold;"><?= $m($it['net']) ?></td>
        </tr>
        <?php } ?>
    </tbody>
</table>

<table class="totals" align="left">
    <tr><td>المجموع</td><td class="e num"><?= $m($t['subtotal']) ?></td></tr>
    <?php if ($hasDiscount) { ?><tr><td>الخصم</td><td class="e num">- <?= $m($t['discount']) ?></td></tr><?php } ?>
    <?php if ($hasTax) { ?><tr><td>الضريبة</td><td class="e num"><?= $m($t['tax']) ?></td></tr><?php } ?>
    <tr class="grand"><td>الإجمالي</td><td class="e num"><?= $m($t['total']) ?> <?= e($q['currency']) ?></td></tr>
    <?php if ($d['paid'] > 0) { ?>
    <tr><td>المدفوع</td><td class="e num">- <?= $m($d['paid']) ?></td></tr>
    <tr><td style="font-weight: bold; color: #00027C;">المتبقي</td><td class="e num" style="font-weight: bold; color: #00027C;"><?= $m($d['balance']) ?> <?= e($q['currency']) ?></td></tr>
    <?php } ?>
</table>
<div style="clear: both;"></div>

<?php if ($q['payment_details']) { ?>
<div class="section"><h3>طريقة الدفع</h3><div><?= nl2br(e($q['payment_details'])) ?></div></div>
<?php } ?>
<?php if ($q['terms']) { ?>
<div class="section"><h3>الشروط والأحكام</h3><div><?= nl2br(e($q['terms'])) ?></div></div>
<?php } ?>
<?php if ($q['notes']) { ?>
<div class="section"><h3>ملاحظات</h3><div><?= nl2br(e($q['notes'])) ?></div></div>
<?php } ?>

<p class="small muted" style="margin-top: 22pt; text-align: center;">شكراً لتعاملكم مع <?= e($co['name']) ?></p>

</body>
</html>

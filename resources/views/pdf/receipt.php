<?php
/**
 * Receipt / refund voucher (mPDF).
 *
 * @var array $d  prepared by App\Services\Finance\ReceiptPdf::render()
 */
$m = fn ($v) => number_format((float) $v, 2);
?>
<html dir="rtl" lang="ar">
<head>
<style>
    body { font-family: madani; font-size: 10.5pt; color: #1e293b; direction: rtl; }
    .num { font-family: madani; direction: ltr; }
    .doc-title { font-size: 22pt; font-weight: bold; color: #00027C; }
    .band { height: 4pt; background: linear-gradient(to left, #00027C, #0082F6, #00BEF2); margin: 10pt 0 16pt; }
    .box { background: #f4f6fb; border-radius: 6pt; padding: 12pt 14pt; }
    .row td { padding: 6pt 4pt; border-bottom: 0.6pt solid #e2e8f0; }
    .k { color: #64748b; width: 28%; }
    .amount { font-size: 20pt; font-weight: bold; color: #00027C; }
    .alloc th { background: #00027C; color: #fff; padding: 5pt; font-size: 9pt; text-align: right; }
    .alloc td { padding: 5pt; border-bottom: 0.6pt solid #e2e8f0; }
    .sign td { width: 50%; padding-top: 30pt; color: #64748b; font-size: 9pt; }
    .line { border-bottom: 0.8pt solid #94a3b8; height: 20pt; }
</style>
</head>
<body>
<table width="100%">
    <tr>
        <td width="50%"><img src="<?= e($d['logo']) ?>" style="height: 44pt;" /></td>
        <td width="50%" style="text-align: left;">
            <div class="doc-title"><?= e($d['title']) ?></div>
            <div class="num" style="color: #0082F6; font-weight: bold;"><?= e($d['number']) ?></div>
            <div class="num" style="color: #64748b;"><?= e($d['date']) ?></div>
        </td>
    </tr>
</table>
<div class="band"></div>

<div class="box">
    <div style="color: #64748b; font-size: 9pt;">المبلغ</div>
    <div class="amount num" style="text-align: right;"><?= $m($d['amount']) ?> <?= e($d['currency']) ?></div>
</div>

<table width="100%" style="margin-top: 12pt; border-collapse: collapse;">
    <tr class="row"><td class="k"><?= e($d['party_label']) ?></td><td style="font-weight: bold; color: #00027C;"><?= e($d['client']) ?></td></tr>
    <tr class="row"><td class="k">طريقة الدفع</td><td><?= e($d['method']) ?></td></tr>
    <?php if ($d['account']) { ?><tr class="row"><td class="k">الحساب</td><td><?= e($d['account']) ?></td></tr><?php } ?>
    <?php if ($d['reference']) { ?><tr class="row"><td class="k">المرجع</td><td class="num" style="text-align: right;"><?= e($d['reference']) ?></td></tr><?php } ?>
    <?php if ($d['notes']) { ?><tr class="row"><td class="k">البيان</td><td><?= nl2br(e($d['notes'])) ?></td></tr><?php } ?>
</table>

<?php if ($d['allocations']) { ?>
<table class="alloc" width="100%" style="margin-top: 14pt; border-collapse: collapse;">
    <thead><tr><th>عن الفاتورة</th><th style="text-align: left;">المبلغ</th></tr></thead>
    <tbody>
    <?php foreach ($d['allocations'] as $a) { ?>
        <tr><td class="num" style="text-align: right;"><?= e($a['invoice']) ?></td><td class="num" style="text-align: left;"><?= $m($a['amount']) ?> <?= e($a['currency']) ?></td></tr>
    <?php } ?>
    </tbody>
</table>
<?php } ?>

<table class="sign" width="100%">
    <tr>
        <td><div class="line"></div>المستلم<?= $d['by'] ? ': '.e($d['by']) : '' ?></td>
        <td><div class="line"></div>التوقيع والختم</td>
    </tr>
</table>
</body>
</html>

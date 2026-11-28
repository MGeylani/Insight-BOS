// Finance vocabularies shared by the finance pages.
export const invoiceStatusTone = {
    draft: 'slate',
    issued: 'navy',
    partial: 'amber',
    paid: 'green',
    overdue: 'red',
    cancelled: 'slate',
};

/** Same rounding as App\Models\Concerns\HasLineAmounts. */
export const r2 = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;

export function lineAmounts(it, taxRate = 0) {
    const gross = r2(Number(it.quantity || 0) * Number(it.unit_price || 0));
    const discount = r2(gross * Number(it.discount_percent || 0) / 100);
    const net = r2(gross - discount);
    return { gross, discount, net, tax: r2(net * Number(taxRate || 0) / 100) };
}

export function sumLines(items, taxRateOf) {
    const t = { subtotal: 0, discount: 0, net: 0, tax: 0 };
    items.forEach((it) => { const l = lineAmounts(it, taxRateOf(it)); t.subtotal += l.gross; t.discount += l.discount; t.net += l.net; t.tax += l.tax; });
    Object.keys(t).forEach((k) => (t[k] = r2(t[k])));
    return { ...t, total: r2(t.net + t.tax) };
}

export const hours = (minutes) => r2(Number(minutes || 0) / 60);

export const paymentKindLabel = { receipt: 'قبض', refund: 'ردّ' };
export const accountTypeIcon = { cash: 'Banknote', bank: 'Landmark', wallet: 'Wallet' };

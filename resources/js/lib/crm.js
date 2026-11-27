import { formatNumber } from '@/lib/format';

export const ratingTone = { hot: 'red', warm: 'amber', cold: 'sky' };

export const oppStageTone = {
    discovery: 'slate',
    proposal: 'sky',
    sent: 'navy',
    negotiation: 'plum',
    won: 'green',
    lost: 'red',
};

export const quoteStatusTone = {
    draft: 'slate',
    sent: 'navy',
    expired: 'amber',
    accepted: 'green',
    rejected: 'red',
    cancelled: 'slate',
};

export const contractStatusTone = {
    draft: 'slate',
    sent: 'amber',
    active: 'green',
    expired: 'amber',
    completed: 'navy',
    terminated: 'red',
    cancelled: 'slate',
};

export const scheduleStatusTone = { pending: 'slate', overdue: 'amber', done: 'green', cancelled: 'slate' };

export const leadStageAccent = {
    new: 'border-t-sky',
    contacted: 'border-t-brand',
    qualified: 'border-t-navy',
    unqualified: 'border-t-slate-300',
};

export function money(amount, currency = 'USD') {
    if (amount === null || amount === undefined || amount === '') return '—';
    return `${formatNumber(amount, 2)} ${currency}`;
}

export function usd(amount) {
    if (amount === null || amount === undefined) return '—';
    return `$${formatNumber(amount, 0)}`;
}

export const monthLabel = (ym) => {
    if (!ym || ym === 'none') return 'بلا تاريخ';
    const [y, m] = ym.split('-').map(Number);
    return new Intl.DateTimeFormat('ar', { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
};

/** Value for <input type="datetime-local"> from an ISO string. */
export function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

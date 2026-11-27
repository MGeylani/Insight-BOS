const dateFmt = new Intl.DateTimeFormat('ar', { day: 'numeric', month: 'short', year: 'numeric' });
const dateTimeFmt = new Intl.DateTimeFormat('ar', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

export const formatDate = (v) => (v ? dateFmt.format(new Date(v)) : '—');
export const formatDateTime = (v) => (v ? dateTimeFmt.format(new Date(v)) : '—');

export function formatNumber(v, decimals = 2) {
    if (v === null || v === undefined || v === '') return '—';
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: decimals }).format(Number(v));
}

export function initials(name = '') {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '';
    // Arabic letters join into a word when placed together, so show one letter for Arabic names.
    if (/[\u0600-\u06FF]/.test(parts[0])) return parts[0][0];
    return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join('');
}

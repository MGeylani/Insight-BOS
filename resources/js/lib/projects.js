export const projectStatusTone = {
    planning: 'sky',
    active: 'navy',
    on_hold: 'amber',
    completed: 'green',
    cancelled: 'slate',
};

export const riskLevelTone = { high: 'red', medium: 'amber', low: 'green' };
export const riskStatusTone = { open: 'amber', mitigated: 'green', occurred: 'red', closed: 'slate' };
export const severityTone = { low: 'slate', medium: 'sky', high: 'amber', critical: 'red' };
export const issueStatusTone = { open: 'amber', in_progress: 'navy', resolved: 'green' };
export const changeStatusTone = { pending: 'amber', approved: 'green', rejected: 'red' };

/** "+3 أيام" / "−2 أيام" / "—" */
export function daysLabel(n) {
    if (!n) return '—';
    return `${n > 0 ? '+' : '−'}${Math.abs(n)} يوم`;
}

// Shared helpers for the task pages.

export const priorityColor = { urgent: '#dc2626', high: '#f59e0b', normal: '#0082F6', low: '#94a3b8' };
export const priorityOrder = { urgent: 0, high: 1, normal: 2, low: 3 };
export const groupOrder = { not_started: 0, active: 1, done: 2, closed: 3 };

const dayFmt = new Intl.DateTimeFormat('ar', { day: 'numeric', month: 'short' });
const dayYearFmt = new Intl.DateTimeFormat('ar', { day: 'numeric', month: 'short', year: 'numeric' });
const timeFmt = new Intl.DateTimeFormat('ar', { hour: '2-digit', minute: '2-digit' });

/** "2026-12-01" or "2026-12-01T14:30" (local) -> Date */
export function parseLocal(v) {
    if (!v) return null;
    const [d, t] = v.split('T');
    const [y, m, day] = d.split('-').map(Number);
    const [hh, mm] = (t ?? '00:00').split(':').map(Number);
    return new Date(y, m - 1, day, hh, mm);
}

export const hasTime = (v) => Boolean(v && v.includes('T'));
export const datePart = (v) => (v ? v.slice(0, 10) : '');
export const timePart = (v) => (hasTime(v) ? v.slice(11, 16) : '');

export function todayStr(offset = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Short relative label: اليوم / غداً / أمس / ١٢ ديسمبر (+ time). */
export function dueLabel(v) {
    if (!v) return '';
    const d = parseLocal(v);
    const day = datePart(v);
    let label;
    if (day === todayStr()) label = 'اليوم';
    else if (day === todayStr(1)) label = 'غداً';
    else if (day === todayStr(-1)) label = 'أمس';
    else label = (d.getFullYear() === new Date().getFullYear() ? dayFmt : dayYearFmt).format(d);
    return hasTime(v) ? `${label} ${timeFmt.format(d)}` : label;
}

export function minutesLabel(min) {
    if (min === null || min === undefined || min === '') return '';
    const h = Math.floor(min / 60);
    const m = min % 60;
    return [h ? `${h}س` : '', m ? `${m}د` : ''].filter(Boolean).join(' ') || '0د';
}

/** Parse "2h 30m", "2.5", "1:30", "90m", "2س 30د" into minutes. */
export function parseMinutes(input) {
    if (input === null || input === undefined) return null;
    const s = String(input).trim().replace(/[٠-٩]/g, (c) => '٠١٢٣٤٥٦٧٨٩'.indexOf(c));
    if (!s) return null;
    if (/^\d+:\d{1,2}$/.test(s)) { const [h, m] = s.split(':').map(Number); return h * 60 + m; }
    if (/^\d+(\.\d+)?$/.test(s)) return Math.round(parseFloat(s) * 60);
    let total = 0; let matched = false;
    const h = s.match(/(\d+(?:\.\d+)?)\s*(h|س|ساعة|ساعات)/i); if (h) { total += parseFloat(h[1]) * 60; matched = true; }
    const m = s.match(/(\d+)\s*(m|د|دقيقة|دقائق)/i); if (m) { total += parseInt(m[1], 10); matched = true; }
    return matched ? Math.round(total) : null;
}

/** Nest rows whose parent is also in the list; others stay top level. */
export function buildTree(rows) {
    const byId = new Map(rows.map((r) => [r.id, { ...r, children: [] }]));
    const roots = [];
    for (const r of byId.values()) {
        const parent = r.parent_id ? byId.get(r.parent_id) : null;
        (parent ? parent.children : roots).push(r);
    }
    const sort = (list) => { list.sort((a, b) => a.position - b.position || a.id - b.id); list.forEach((x) => sort(x.children)); return list; };
    return sort(roots);
}

export const keep = { preserveScroll: true, preserveState: true };

import { router } from '@inertiajs/vue3';

/** Date value -> request fields: { due_date, due_time } */
export function dateParams(prefix, v) {
    return { [`${prefix}_date`]: v ? datePart(v) : null, [`${prefix}_time`]: v && hasTime(v) ? timePart(v) : null };
}

export function patchTask(id, data, opts = {}) {
    router.patch(`/tasks/${id}`, data, { ...keep, ...opts });
}

/** Plain text of a rich-text document (paragraph per line). */
export function docToPlain(doc) {
    if (!doc) return '';
    const out = [];
    const walk = (n) => {
        if (n.type === 'text') out.push(n.text);
        else if (n.type === 'mention') out.push('@' + (n.attrs?.label ?? ''));
        else if (n.type === 'hardBreak') out.push('\n');
        (n.content ?? []).forEach(walk);
        if (['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock', 'taskItem'].includes(n.type)) out.push('\n');
    };
    walk(doc);
    return out.join('').replace(/\n{3,}/g, '\n\n').trim();
}

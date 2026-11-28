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
    router.patch(`/tasks/${id}`, data, {
        ...keep, ...opts,
        // Closing a task that waits on open tasks asks first, then sends it again with force.
        onError: (errors) => {
            if (errors.blocked && !data.force && confirm(`${errors.blocked}\n\nإغلاقها رغم ذلك؟`)) patchTask(id, { ...data, force: true }, opts);
            else opts.onError?.(errors);
        },
    });
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

export const groupLabels = { status: 'الحالة', assignee: 'المسؤول', priority: 'الأولوية', due: 'التسليم', list: 'القائمة', none: 'بلا تجميع' };
export const sortLabels = { manual: 'يدوي (السحب)', due: 'التسليم', priority: 'الأولوية', created: 'الأحدث إنشاءً', updated: 'آخر تعديل', title: 'العنوان' };
export const columnLabels = {
    status: 'الحالة', assignees: 'المسؤولون', due: 'التسليم', start: 'البدء', priority: 'الأولوية', estimate: 'التقدير', tags: 'الوسوم',
    list: 'القائمة', client: 'العميل', project: 'المشروع', created: 'أُنشئت', completed: 'اكتملت', subtasks: 'الفرعية', comments: 'التعليقات',
};

const dueBucket = (t) => {
    if (!t.due) return 'none';
    if (t.overdue) return 'overdue';
    const d = datePart(t.due);
    if (d === todayStr()) return 'today';
    if (d === todayStr(1)) return 'tomorrow';
    if (d <= todayStr(7)) return 'week';
    return 'later';
};
const dueGroups = [
    ['overdue', 'متأخرة', '#dc2626'], ['today', 'اليوم', '#f59e0b'], ['tomorrow', 'غداً', '#0082F6'],
    ['week', 'خلال 7 أيام', '#00BEF2'], ['later', 'لاحقاً', '#864690'], ['none', 'بلا تاريخ', '#94a3b8'],
];

/**
 * Split rows into groups for list and board views.
 * Each group: { key, label, color, rows, drop(task) -> patch payload | null (not droppable), status? }
 */
export function groupTasks(rows, by, { statuses = [], users = [], priorities = {}, types = [], customFields = [] } = {}) {
    const extra = groupTasksExtra(rows, by, { types, customFields });
    if (extra) return extra;
    if (by === 'status') {
        const names = statuses.map((s) => s.name);
        const extra = [...new Map(rows.filter((t) => t.status && !names.includes(t.status.name)).map((t) => [t.status.name, t.status])).values()];
        return [...statuses, ...extra].map((s) => ({
            key: `s:${s.name}`, label: s.name, color: s.color, status: s, rows: rows.filter((t) => t.status?.name === s.name),
            drop: () => ({ status_id: s.id }),
        }));
    }
    if (by === 'priority') {
        return Object.entries(priorities).map(([k, l]) => ({
            key: `p:${k}`, label: l, color: priorityColor[k], rows: rows.filter((t) => t.priority === k), drop: () => ({ priority: k }),
        }));
    }
    if (by === 'assignee') {
        const ids = [...new Set(rows.flatMap((t) => t.assignees.map((a) => a.id)))];
        const people = ids.map((id) => users.find((u) => u.id === id) ?? rows.flatMap((t) => t.assignees).find((a) => a.id === id)).sort((a, b) => a.name.localeCompare(b.name, 'ar'));
        return [
            ...people.map((u) => ({
                key: `a:${u.id}`, label: u.name, color: '#00027C', user: u, rows: rows.filter((t) => t.assignees.some((a) => a.id === u.id)),
                drop: (task, fromKey) => {
                    const from = fromKey?.startsWith('a:') ? Number(fromKey.slice(2)) : null;
                    return { assignees: [...new Set([...task.assignees.map((a) => a.id).filter((id) => id !== from), u.id])] };
                },
            })),
            { key: 'a:none', label: 'بلا مسؤول', color: '#94a3b8', rows: rows.filter((t) => !t.assignees.length), drop: () => ({ assignees: [] }) },
        ];
    }
    if (by === 'due') {
        const drops = { today: () => ({ due_date: todayStr(), due_time: null }), tomorrow: () => ({ due_date: todayStr(1), due_time: null }), none: () => ({ due_date: null, due_time: null }) };
        return dueGroups.map(([k, l, c]) => ({ key: `d:${k}`, label: l, color: c, rows: rows.filter((t) => dueBucket(t) === k), drop: drops[k] ?? null }));
    }
    if (by === 'list') {
        const lists = [...new Map(rows.map((t) => [t.list_id, t.list])).values()].filter(Boolean);
        return lists.map((l) => ({ key: `l:${l.id}`, label: l.name, color: '#0082F6', rows: rows.filter((t) => t.list_id === l.id), drop: null }));
    }
    return [{ key: 'all', label: 'كل المهام', color: '#00027C', rows, drop: null }];
}

// ---- Task types and custom fields ----
import {
    CircleCheck, Diamond, Package, Camera, Megaphone, Users, PenTool, Video, FileText, Calendar, Bug, Lightbulb, Phone, Printer,
    Clapperboard, Image, Mail, Flag, Star, Rocket,
} from '@lucide/vue';

export const typeIcons = {
    'circle-check': CircleCheck, diamond: Diamond, package: Package, camera: Camera, megaphone: Megaphone, users: Users, 'pen-tool': PenTool,
    video: Video, 'file-text': FileText, calendar: Calendar, bug: Bug, lightbulb: Lightbulb, phone: Phone, printer: Printer,
    clapperboard: Clapperboard, image: Image, mail: Mail, flag: Flag, star: Star, rocket: Rocket,
};
columnLabels.type = 'النوع';
groupLabels.type = 'النوع';

export const fieldTypeLabels = {
    text: 'نص', number: 'رقم', money: 'مبلغ', dropdown: 'قائمة منسدلة', labels: 'تسميات', date: 'تاريخ', checkbox: 'مربع اختيار',
    people: 'أشخاص', url: 'رابط', rating: 'تقييم', percent: 'نسبة', file: 'ملف', formula: 'صيغة',
};

/** Plain-text display of a custom field value. */
export function fieldText(f, v, users = []) {
    if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) return '';
    const choice = (id) => f.options?.choices?.find((c) => c.id === id)?.label ?? id;
    switch (f.type) {
        case 'dropdown': return choice(v);
        case 'labels': return v.map(choice).join('، ');
        case 'checkbox': return v ? '✓' : '';
        case 'people': return v.map((id) => users.find((u) => u.id === id)?.name ?? '؟').join('، ');
        case 'money': return `${Number(v).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${f.options?.currency ?? 'USD'}`;
        case 'percent': return `${v}%`;
        case 'rating': return '★'.repeat(v);
        case 'formula': return Number(v).toLocaleString('en', { maximumFractionDigits: 2 });
        case 'date': return dueLabel(v);
        case 'file': return `${v.length} ملف`;
        default: return String(v);
    }
}

/** Group by task type or a dropdown custom field (extends groupTasks). */
export function groupTasksExtra(rows, by, { types = [], customFields = [] } = {}) {
    if (by === 'type') {
        return [
            ...types.map((t) => ({ key: `t:${t.id}`, label: t.name, color: t.color, rows: rows.filter((r) => r.type_id === t.id), drop: () => ({ task_type_id: t.id }) })),
            { key: 't:none', label: 'بلا نوع', color: '#94a3b8', rows: rows.filter((r) => !r.type_id || !types.some((t) => t.id === r.type_id)), drop: null },
        ];
    }
    const m = /^cf:(\d+)$/.exec(by ?? '');
    const f = m && customFields.find((x) => x.id === Number(m[1]));
    if (f && f.type === 'dropdown') {
        const val = (r) => r.fields?.[f.id];
        return [
            ...(f.options.choices ?? []).map((c) => ({ key: `c:${c.id}`, label: c.label, color: c.color, rows: rows.filter((r) => val(r) === c.id), fieldDrop: { field: f.id, value: c.id } })),
            { key: 'c:none', label: `بلا ${f.name}`, color: '#94a3b8', rows: rows.filter((r) => !val(r)), fieldDrop: { field: f.id, value: null } },
        ];
    }
    return null;
}

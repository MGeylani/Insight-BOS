<?php

namespace App\Services\Tasks;

use App\Models\SavedView;

/**
 * Display settings of a task view, from the URL or a saved view:
 *   view    list | board | calendar | table
 *   group   status | assignee | priority | due | list | none   (list and board)
 *   sort    manual | due | priority | created | title | updated  (+ dir asc|desc)
 *   columns table / list columns
 *   date    calendar: due | start
 */
class ViewConfig
{
    public const GROUPS = ['status', 'assignee', 'priority', 'due', 'list', 'type', 'none']; // + cf:<id> (dropdown fields)

    public const SORTS = ['manual', 'due', 'priority', 'created', 'title', 'updated'];

    public const COLUMNS = ['type', 'status', 'assignees', 'due', 'start', 'priority', 'estimate', 'tags', 'list', 'client', 'project', 'created', 'completed', 'subtasks', 'comments'];

    public const DEFAULT_COLUMNS = ['status', 'assignees', 'due', 'priority', 'estimate', 'tags'];

    /** @return array<string, mixed> */
    public static function clean(array $in): array
    {
        $view = in_array($in['view'] ?? null, SavedView::TYPES, true) ? $in['view'] : 'list';
        $isCf = fn ($v) => is_string($v) && preg_match('/^cf:\d+$/', $v);
        $columns = array_values(array_filter((array) ($in['columns'] ?? []), fn ($c) => in_array($c, self::COLUMNS, true) || $isCf($c)));

        return [
            'view' => $view,
            'group' => in_array($in['group'] ?? null, self::GROUPS, true) || $isCf($in['group'] ?? null) ? $in['group'] : ($view === 'table' ? 'none' : 'status'),
            'sort' => in_array($in['sort'] ?? null, self::SORTS, true) || $isCf($in['sort'] ?? null) ? $in['sort'] : 'manual',
            'dir' => ($in['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'columns' => $columns ?: self::DEFAULT_COLUMNS,
            'date' => ($in['date'] ?? 'due') === 'start' ? 'start' : 'due',
            'subtasks' => filter_var($in['subtasks'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }
}

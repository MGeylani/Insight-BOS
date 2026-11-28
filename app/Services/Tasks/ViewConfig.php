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
    public const GROUPS = ['status', 'assignee', 'priority', 'due', 'list', 'none'];

    public const SORTS = ['manual', 'due', 'priority', 'created', 'title', 'updated'];

    public const COLUMNS = ['status', 'assignees', 'due', 'start', 'priority', 'estimate', 'tags', 'list', 'client', 'project', 'created', 'completed', 'subtasks', 'comments'];

    public const DEFAULT_COLUMNS = ['status', 'assignees', 'due', 'priority', 'estimate', 'tags'];

    /** @return array<string, mixed> */
    public static function clean(array $in): array
    {
        $view = in_array($in['view'] ?? null, SavedView::TYPES, true) ? $in['view'] : 'list';
        $columns = array_values(array_intersect((array) ($in['columns'] ?? []), self::COLUMNS));

        return [
            'view' => $view,
            'group' => in_array($in['group'] ?? null, self::GROUPS, true) ? $in['group'] : ($view === 'table' ? 'none' : 'status'),
            'sort' => in_array($in['sort'] ?? null, self::SORTS, true) ? $in['sort'] : 'manual',
            'dir' => ($in['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'columns' => $columns ?: self::DEFAULT_COLUMNS,
            'date' => ($in['date'] ?? 'due') === 'start' ? 'start' : 'due',
            'subtasks' => filter_var($in['subtasks'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }
}

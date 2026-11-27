<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters records by the scope of a permission grant:
 *   all            -> every record
 *   own / assigned -> records the user owns (responsible person) or created
 *   no grant       -> nothing
 * A model can override scopeOwnerColumns() to change which columns mean "mine".
 */
trait VisibleByScope
{
    /** @return list<string> */
    public function scopeOwnerColumns(): array
    {
        return ['owner_id', 'created_by'];
    }

    public function scopeVisibleTo(Builder $query, User $user, string $permission = 'crm.view'): Builder
    {
        $scope = $user->permissionScope($permission);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === null) {
            return $query->whereRaw('1 = 0');
        }

        $table = $this->getTable();

        return $query->where(function (Builder $q) use ($user, $table) {
            foreach ($this->scopeOwnerColumns() as $col) {
                $q->orWhere("$table.$col", $user->id);
            }
        });
    }

    public function isVisibleTo(User $user, string $permission = 'crm.view'): bool
    {
        $scope = $user->permissionScope($permission);
        if ($scope === 'all') {
            return true;
        }
        if ($scope === null) {
            return false;
        }
        foreach ($this->scopeOwnerColumns() as $col) {
            if ((int) $this->getAttribute($col) === $user->id) {
                return true;
            }
        }

        return false;
    }
}

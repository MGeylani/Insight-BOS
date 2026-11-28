<?php

namespace App\Services\Finance;

use App\Http\Controllers\Finance\InvoiceController;
use App\Models\Invoice;
use App\Models\User;
use Closure;

/** Compact invoice lists for client / project / contract pages (empty without finance.view). */
class InvoiceLists
{
    /** @return list<array<string, mixed>> */
    public static function for(User $user, Closure $where, int $limit = 20): array
    {
        if (! $user->hasPermission('finance.view')) {
            return [];
        }

        return $where(Invoice::visibleTo($user, 'finance.view'))->with('items')->withPaid()->latest('id')->limit($limit)->get()
            ->map(fn (Invoice $i) => InvoiceController::row($i))->all();
    }
}

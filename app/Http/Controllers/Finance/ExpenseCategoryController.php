<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Expense categories: settings.manage or finance.edit with scope all. Deleting archives (old expenses keep it). */
class ExpenseCategoryController extends Controller
{
    private function authorizeManage(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->hasPermission('settings.manage') || $u->permissionScope('finance.edit') === 'all', 403);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        ExpenseCategory::create($this->validated($request));

        return back()->with('success', 'تمت إضافة التصنيف.');
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $category->update($this->validated($request));

        return back()->with('success', 'تم حفظ التصنيف.');
    }

    public function destroy(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $this->authorizeManage($request);
        $category->delete();

        return back()->with('success', 'أُرشف التصنيف.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(config('finance.expense_category_kinds')))],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}

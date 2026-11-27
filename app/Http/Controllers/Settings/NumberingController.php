<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NumberSequence;
use App\Services\ActivityLogger;
use App\Services\NumberSequencer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NumberingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/Numbering', [
            'sequences' => NumberSequence::orderBy('id')->get()->map(fn (NumberSequence $s) => $s->only('id', 'type', 'label', 'prefix', 'padding', 'yearly_reset', 'last_number') + [
                'next' => NumberSequencer::preview($s),
            ]),
        ]);
    }

    public function update(Request $request, NumberSequence $numberSequence): RedirectResponse
    {
        $data = $request->validate([
            'prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'padding' => ['required', 'integer', 'between:3,8'],
            'yearly_reset' => ['boolean'],
        ]);
        $numberSequence->update($data);
        ActivityLogger::log('settings.numbering', $numberSequence, "تعديل ترقيم {$numberSequence->label}", ActivityLogger::diff($numberSequence));

        return back()->with('success', 'تم حفظ صيغة الترقيم.');
    }
}

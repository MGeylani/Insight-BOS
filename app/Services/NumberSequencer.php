<?php

namespace App\Services;

use App\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

class NumberSequencer
{
    /**
     * Reserve the next document number, e.g. INV-2026-0001. Numbers are taken at issue time,
     * inside a row lock, so concurrent issues never share or skip a number.
     */
    public static function next(string $type, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($type, $year) {
            /** @var NumberSequence $seq */
            $seq = NumberSequence::where('type', $type)->lockForUpdate()->firstOrFail();

            if ($seq->yearly_reset && $seq->current_year !== $year) {
                $seq->current_year = $year;
                $seq->last_number = 0;
            }

            $seq->last_number++;
            $seq->save();

            return $seq->format($seq->last_number, $year);
        });
    }

    public static function preview(NumberSequence $seq, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $next = ($seq->yearly_reset && $seq->current_year !== $year) ? 1 : $seq->last_number + 1;

        return $seq->format($next, $year);
    }
}

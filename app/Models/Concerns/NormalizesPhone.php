<?php

namespace App\Models\Concerns;

/**
 * Keeps `phone_normalized` in sync with `phone` for duplicate detection.
 * The last 9 digits are compared, so +90 532..., 0532... and 532... match.
 */
trait NormalizesPhone
{
    protected static function bootNormalizesPhone(): void
    {
        static::saving(function ($model) {
            $model->phone_normalized = static::normalizePhone($model->phone);
        });
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 7 ? substr($digits, -9) : null;
    }
}

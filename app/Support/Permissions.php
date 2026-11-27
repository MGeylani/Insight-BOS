<?php

namespace App\Support;

class Permissions
{
    /** Scope strength: a broader scope covers narrower ones. */
    private const RANK = ['own' => 1, 'assigned' => 2, 'all' => 3];

    /**
     * @return list<string> every valid "module.action" key
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (config('permissions.modules') as $module => $def) {
            foreach (array_keys($def['actions']) as $action) {
                $keys[] = "$module.$action";
            }
        }

        return $keys;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function scopeCovers(string $granted, string $required): bool
    {
        return (self::RANK[$granted] ?? 0) >= (self::RANK[$required] ?? PHP_INT_MAX);
    }

    /**
     * Drop unknown permissions and invalid scopes.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, string>
     */
    public static function sanitize(array $map): array
    {
        $valid = array_flip(self::keys());
        $out = [];
        foreach ($map as $key => $scope) {
            if (isset($valid[$key]) && is_string($scope) && isset(self::RANK[$scope])) {
                $out[$key] = $scope;
            }
        }

        return $out;
    }

    /**
     * Catalog shaped for the UI permission matrix.
     *
     * @return list<array{key: string, label: string, actions: list<array{key: string, label: string}>}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (config('permissions.modules') as $module => $def) {
            $actions = [];
            foreach ($def['actions'] as $action => $label) {
                $actions[] = ['key' => "$module.$action", 'label' => $label];
            }
            $out[] = ['key' => $module, 'label' => $def['label'], 'actions' => $actions];
        }

        return $out;
    }
}

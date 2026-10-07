<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers, for a short cooldown, which Gemini models are overloaded (503/timeout)
 * so later requests skip them instead of waiting for the same failure again.
 */
final class GeminiModelCircuit
{
    public static function isTripped(string $model): bool
    {
        return Cache::has(self::key($model));
    }

    public static function trip(string $model): void
    {
        $cooldown = max(1, (int) config('ai.gemini.unavailable_cooldown', 120));

        Cache::put(self::key($model), true, now()->addSeconds($cooldown));
    }

    /**
     * Models still worth trying; if every model is tripped, all of them are returned.
     *
     * @param  list<string>  $models
     * @return list<string>
     */
    public static function available(array $models): array
    {
        $available = array_values(array_filter($models, fn (string $model): bool => ! self::isTripped($model)));

        return $available !== [] ? $available : $models;
    }

    private static function key(string $model): string
    {
        return 'gemini:model-unavailable:'.$model;
    }
}

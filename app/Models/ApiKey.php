<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = ['user_id', 'name', 'prefix', 'key_hash', 'plan', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ApiUsage::class);
    }

    /** @return array{0: static, 1: string} the model and the plaintext key (shown only once) */
    public static function issue(User $user, string $name, string $plan = 'free'): array
    {
        $plain = 'fn_live_'.Str::random(40);

        $key = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'prefix' => substr($plain, 0, 12),
            'key_hash' => self::hash($plain),
            'plan' => $plan,
        ]);

        return [$key, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function limits(): array
    {
        return (array) config("plans.{$this->plan}", config('plans.free'));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'created_by',
        'url',
        'canonical_url',
        'title',
        'description',
        'content',
        'author_name',
        'published_at',
        'language',
        'metadata',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** Articles the user created or has analyzed. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('created_by', $user->id)
            ->orWhereHas('analyses', fn (Builder $a) => $a->where('user_id', $user->id)));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }
}

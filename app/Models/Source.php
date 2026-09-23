<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'url',
        'type',
        'country',
        'language',
        'description',
        'reputation_score',
    ];

    protected function casts(): array
    {
        return [
            'reputation_score' => 'decimal:2',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}

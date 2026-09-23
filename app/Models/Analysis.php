<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Analysis extends Model
{
    use HasFactory;

    protected $casts = [
        'credibility_score' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'article_id',
        'status',
        'model_provider',
        'model_name',
        'algorithm_version',
        'credibility_score',
        'confidence_score',
        'final_verdict',
        'started_at',
        'completed_at',
        'error',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function score(): HasOne
    {
        return $this->hasOne(Score::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(ModelPrediction::class);
    }
}

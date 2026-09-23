<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Score extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_id',
        'evidence_score',
        'source_quality_score',
        'corroboration_score',
        'independence_score',
        'consistency_score',
        'language_score',
        'transparency_score',
        'final_score',
        'algorithm_version',
        'metadata',
    ];

    protected $casts = [
        'evidence_score' => 'decimal:2',
        'source_quality_score' => 'decimal:2',
        'corroboration_score' => 'decimal:2',
        'independence_score' => 'decimal:2',
        'consistency_score' => 'decimal:2',
        'language_score' => 'decimal:2',
        'transparency_score' => 'decimal:2',
        'final_score' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}

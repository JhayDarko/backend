<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelPrediction extends Model
{
    protected $fillable = [
        'analysis_id',
        'prediction_label',
        'confidence_score',
        'probability',
        'model_provider',
        'model_name',
        'algorithm_version',
        'metadata',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:4',
        'probability' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}

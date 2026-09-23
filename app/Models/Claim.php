<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Claim extends Model
{
    protected $fillable = [
        'analysis_id',
        'text',
        'type',
        'importance',
        'verifiable',
        'context',
        'fingerprint',
        'metadata',
    ];

    protected $casts = [
        'importance' => 'decimal:4',
        'verifiable' => 'boolean',
        'metadata' => 'array',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function claimVerifications(): HasMany
    {
        return $this->hasMany(ClaimVerification::class);
    }
}

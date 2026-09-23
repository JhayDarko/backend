<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClaimVerification extends Model
{
    protected $fillable = [
        'claim_id',
        'relation',
        'status',
        'provider',
        'query_used',
        'publisher',
        'publisher_site',
        'fact_check_url',
        'fact_check_title',
        'raw_rating',
        'normalized_rating',
        'review_date',
        'language',
        'relevance_score',
        'confidence',
        'explanation',
        'metadata',
        'fingerprint',
    ];

    protected $casts = [
        'relevance_score' => 'decimal:2',
        'confidence' => 'decimal:2',
        'review_date' => 'datetime',
        'metadata' => 'array',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function evidences(): BelongsToMany
    {
        return $this->belongsToMany(Evidence::class, 'verification_evidence', 'claim_verification_id', 'evidence_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Evidence extends Model
{
    protected $table = 'evidences';

    protected $fillable = [
        'source_id',
        'url',
        'title',
        'content',
        'snippet',
        'published_at',
        'retrieved_at',
        'content_hash',
        'similarity',
        'metadata',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'retrieved_at' => 'datetime',
        'similarity' => 'decimal:5',
        'metadata' => 'array',
    ];

    public function claimVerifications(): BelongsToMany
    {
        return $this->belongsToMany(ClaimVerification::class, 'verification_evidence', 'evidence_id', 'claim_verification_id');
    }
}

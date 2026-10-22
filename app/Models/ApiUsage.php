<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsage extends Model
{
    protected $fillable = ['api_key_id', 'date', 'requests', 'analyses', 'errors'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}

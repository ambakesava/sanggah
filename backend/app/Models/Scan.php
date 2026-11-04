<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scan extends Model
{
    protected $fillable = [
        'request_id', 'user_id', 'object_id', 'status', 'confidence',
        'image_hash', 'image_path', 'lang', 'model', 'latency_ms',
    ];

    public function object(): BelongsTo
    {
        return $this->belongsTo(CulturalObject::class, 'object_id');
    }
}

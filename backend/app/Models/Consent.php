<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consent extends Model
{
    protected $fillable = [
        'uuid', 'subject_type', 'subject_ref', 'purpose', 'granted',
        'granted_at', 'revoked_at', 'image_path',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}

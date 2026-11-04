<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CulturalObject extends Model
{
    protected $table = 'objects';

    protected $fillable = [
        'slug', 'name_id', 'name_en', 'aliases', 'category', 'visual_hint',
        'static_card_id', 'static_card_en', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'static_card_id' => 'array',
            'static_card_en' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function chunks(): BelongsToMany
    {
        return $this->belongsToMany(Chunk::class, 'chunk_object', 'object_id', 'chunk_id');
    }

    public function nameFor(string $lang): string
    {
        return $lang === 'en' ? $this->name_en : $this->name_id;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = [
        'code', 'title', 'publisher', 'url', 'type', 'lang', 'license',
        'accessed_at', 'content_hash', 'validated_by', 'validated_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'date:Y-m-d',
            'validated_at' => 'datetime',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function citation(int $n): array
    {
        return [
            'n' => $n,
            'source_id' => $this->id,
            'title' => $this->title,
            'publisher' => $this->publisher,
            'url' => $this->url,
            'accessed_at' => optional($this->accessed_at)->toDateString(),
        ];
    }
}

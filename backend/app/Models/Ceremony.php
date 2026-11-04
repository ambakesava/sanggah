<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ceremony extends Model
{
    protected $fillable = [
        'slug', 'name_id', 'name_en', 'type', 'date_start', 'date_end',
        'summary_id', 'summary_en', 'tips_id', 'tips_en', 'road_note_id', 'road_note_en',
        'status', 'ai_drafted', 'reviewed_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'date_start' => 'date:Y-m-d',
            'date_end' => 'date:Y-m-d',
            'ai_drafted' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'ceremony_source');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function nameFor(string $lang): string
    {
        return $lang === 'en' ? $this->name_en : $this->name_id;
    }
}

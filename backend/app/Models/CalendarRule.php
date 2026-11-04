<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarRule extends Model
{
    protected $fillable = [
        'code', 'name_id', 'name_en', 'rule_type', 'activity_tags', 'conditions',
        'effect', 'weight', 'source_id', 'status', 'validated_by', 'rules_version',
    ];

    protected function casts(): array
    {
        return [
            'activity_tags' => 'array',
            'conditions' => 'array',
            'weight' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toEngineArray(): array
    {
        return [
            'code' => $this->code,
            'name_id' => $this->name_id,
            'name_en' => $this->name_en,
            'rule_type' => $this->rule_type,
            'activity_tags' => $this->activity_tags ?? [],
            'conditions' => $this->conditions ?? [],
            'effect' => $this->effect,
            'weight' => $this->weight,
            'source_id' => $this->source_id,
            'status' => $this->status,
            'rules_version' => $this->rules_version,
        ];
    }
}

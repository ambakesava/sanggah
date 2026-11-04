<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SasihDay extends Model
{
    protected $table = 'sasih_calendar';

    protected $fillable = [
        'date', 'sasih', 'penanggal_pangelong', 'is_purnama', 'is_tilem', 'observances', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'is_purnama' => 'boolean',
            'is_tilem' => 'boolean',
            'observances' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function payloadFor(string $iso): ?array
    {
        $row = static::query()->whereDate('date', $iso)->first();
        if ($row === null) {
            return null;
        }

        return [
            'sasih' => $row->sasih,
            'penanggal_pangelong' => $row->penanggal_pangelong,
            'is_purnama' => (bool) $row->is_purnama,
            'is_tilem' => (bool) $row->is_tilem,
            'observances' => $row->observances ?? [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Chunk extends Model
{
    protected $fillable = [
        'source_id', 'lang', 'content', 'token_count', 'section', 'vector_id',
        'embedding_model', 'content_hash', 'corpus_version',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function objects(): BelongsToMany
    {
        return $this->belongsToMany(CulturalObject::class, 'chunk_object', 'chunk_id', 'object_id');
    }
}

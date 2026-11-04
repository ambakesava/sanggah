<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Models\Chunk;
use App\Models\CulturalObject;
use Illuminate\Support\Str;

/**
 * Lexical retrieval over curated chunks. A vector store is not required for the MVP;
 * AiService::embed() stays available for a later ingest step.
 */
final class RagService
{
    /**
     * @return list<array{n: int, chunk: Chunk, score: int}>
     */
    public function retrieve(CulturalObject $object, string $lang, ?string $question, int $limit = 5): array
    {
        $chunks = Chunk::query()
            ->with('source')
            ->whereHas('objects', fn ($q) => $q->where('objects.id', $object->id))
            ->orderBy('id')
            ->get();

        $preferred = $chunks->where('lang', $lang)->values();
        if ($preferred->count() < 2) {
            $preferred = $chunks->values();
        }
        if ($question === null || trim($question) === '') {
            return $this->number($preferred->take($limit)->all(), array_fill(0, $limit, 1));
        }

        $needles = $this->tokens($question);
        $scored = [];
        foreach ($preferred as $chunk) {
            $hay = $this->tokens($chunk->content);
            $score = count(array_intersect($needles, $hay));
            if ($score > 0) {
                $scored[] = ['chunk' => $chunk, 'score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $limit);
        $out = [];
        $n = 1;
        foreach ($scored as $row) {
            $out[] = ['n' => $n, 'chunk' => $row['chunk'], 'score' => $row['score']];
            $n++;
        }

        return $out;
    }

    /**
     * @param  list<Chunk>  $chunks
     * @param  list<int>  $scores
     * @return list<array{n: int, chunk: Chunk, score: int}>
     */
    private function number(array $chunks, array $scores): array
    {
        $out = [];
        $n = 1;
        foreach ($chunks as $i => $chunk) {
            $out[] = ['n' => $n, 'chunk' => $chunk, 'score' => $scores[$i] ?? 1];
            $n++;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $stop = ['yang', 'dengan', 'untuk', 'dari', 'pada', 'atau', 'adalah', 'ini', 'itu', 'anda', 'the', 'and', 'with', 'from', 'that', 'this', 'your', 'have', 'what', 'when', 'where', 'bisa', 'dapat', 'akan', 'tidak'];
        $tokens = [];
        foreach (preg_split('/\s+/', $text) ?: [] as $word) {
            if (mb_strlen($word) < 4 || in_array($word, $stop, true)) {
                continue;
            }
            $tokens[] = Str::lower($word);
        }

        return array_values(array_unique($tokens));
    }
}

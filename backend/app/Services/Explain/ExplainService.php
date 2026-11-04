<?php

declare(strict_types=1);

namespace App\Services\Explain;

use App\Exceptions\AiException;
use App\Models\CulturalObject;
use App\Models\Source;
use App\Services\Ai\AiService;
use App\Services\Ai\PromptLibrary;
use App\Services\Rag\CitationValidator;
use App\Services\Rag\RagService;
use Throwable;

final class ExplainService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly RagService $rag,
        private readonly CitationValidator $citations,
        private readonly PromptLibrary $prompts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function explain(CulturalObject $object, string $lang, ?string $question = null): array
    {
        $question = $question !== null ? trim($question) : null;
        $disclaimer = config('sanggah.disclaimer.culture.'.$lang);
        $curated = $this->curated($object, $lang);

        if ($question !== null && $question !== '') {
            $hits = $this->rag->retrieve($object, $lang, $question);
            if ($hits === []) {
                return $this->envelope('no_verified_source', null, $lang === 'en'
                    ? 'No verified source in the curated notes answers that question yet.'
                    : 'Belum ada sumber terverifikasi yang menjawab pertanyaan itu.', [], $disclaimer, 'curated');
            }
        } else {
            $hits = $this->rag->retrieve($object, $lang, null);
        }

        if ($this->ai->providerName() !== 'mock') {
            try {
                $llm = $this->fromModel($object, $lang, $question, $hits, $disclaimer);
                if ($llm !== null) {
                    return $llm;
                }
            } catch (AiException | Throwable) {
                // Fall through to the curated card.
            }
        }

        if ($question !== null && $question !== '') {
            $answer = trim(implode(' ', array_map(
                fn (array $hit) => trim($hit['chunk']->content),
                $hits
            )));
            if ($answer === '' || ! $this->citations->hasCitation($answer)) {
                return $this->envelope('no_verified_source', null, $lang === 'en'
                    ? 'No verified source in the curated notes answers that question yet.'
                    : 'Belum ada sumber terverifikasi yang menjawab pertanyaan itu.', [], $disclaimer, 'curated');
            }

            return $this->envelope('fallback_static', null, $answer, $curated['citations'], $disclaimer, 'curated');
        }

        return $this->envelope(
            'fallback_static',
            $curated['sections'],
            null,
            $curated['citations'],
            $disclaimer,
            'curated',
        );
    }

    /**
     * @param  list<array{n: int, chunk: mixed, score: int}>  $hits
     * @return array<string, mixed>|null
     */
    private function fromModel(CulturalObject $object, string $lang, ?string $question, array $hits, string $disclaimer): ?array
    {
        if ($hits === []) {
            return null;
        }
        $lines = [];
        $citations = [];
        foreach ($hits as $hit) {
            $source = $hit['chunk']->source;
            if ($source === null) {
                continue;
            }
            $lines[] = '['.$hit['n'].'] '.$hit['chunk']->content;
            $citations[] = $source->citation($hit['n']);
        }
        if ($lines === []) {
            return null;
        }
        $system = $this->prompts->get('explain');
        $user = "lang: {$lang}\nobject: {$object->slug}\nquestion: ".($question ?: 'Explain meaning, etiquette, dress code, and prohibitions.')
            ."\nCONTEXT:\n".implode("\n", $lines);
        $response = $this->ai->generate($system, $user, ['title' => 'ExplainResult']);
        $json = $response->json ?? [];
        if (! empty($json['insufficient_context'])) {
            return $this->envelope('no_verified_source', null, $lang === 'en'
                ? 'No verified source in the curated notes answers that question yet.'
                : 'Belum ada sumber terverifikasi yang menjawab pertanyaan itu.', $citations, $disclaimer, 'llm');
        }
        $maxN = count($citations);
        if ($question !== null && $question !== '') {
            $answer = $this->citations->sanitize((string) ($json['answer'] ?? ''), $maxN);
            if ($answer === '') {
                return null;
            }

            return $this->envelope('ok', null, $answer, $citations, $disclaimer, 'llm');
        }
        $sections = [];
        foreach (['meaning', 'etiquette', 'dress_code', 'prohibitions'] as $key) {
            $clean = $this->citations->sanitize((string) ($json['sections'][$key] ?? ''), $maxN);
            if ($clean === '') {
                return null;
            }
            $sections[$key] = $clean;
        }

        return $this->envelope('ok', $sections, null, $citations, $disclaimer, 'llm');
    }

    /**
     * @return array{sections: array<string, string>, citations: list<array<string, mixed>>}
     */
    private function curated(CulturalObject $object, string $lang): array
    {
        $card = $lang === 'en' ? ($object->static_card_en ?? []) : ($object->static_card_id ?? []);
        $sections = is_array($card['sections'] ?? null) ? $card['sections'] : [];
        $keys = is_array($card['citation_keys'] ?? null) ? $card['citation_keys'] : [];
        $sources = Source::query()->whereIn('code', array_values($keys))->get()->keyBy('code');
        $citations = [];
        foreach ($keys as $n => $code) {
            $source = $sources->get($code);
            if ($source !== null) {
                $citations[] = $source->citation((int) $n);
            }
        }
        ksort($sections);

        return [
            'sections' => [
                'meaning' => (string) ($sections['meaning'] ?? ''),
                'etiquette' => (string) ($sections['etiquette'] ?? ''),
                'dress_code' => (string) ($sections['dress_code'] ?? ''),
                'prohibitions' => (string) ($sections['prohibitions'] ?? ''),
            ],
            'citations' => $citations,
        ];
    }

    /**
     * @param  array<string, string>|null  $sections
     * @param  list<array<string, mixed>>  $citations
     * @return array<string, mixed>
     */
    private function envelope(string $status, ?array $sections, ?string $answer, array $citations, string $disclaimer, string $mode): array
    {
        return [
            'status' => $status,
            'sections' => $sections,
            'answer' => $answer,
            'citations' => array_values($citations),
            'disclaimer' => $disclaimer,
            'source_mode' => $mode,
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Models\Chunk;
use App\Models\CulturalObject;
use App\Models\Source;
use Illuminate\Database\Seeder;

class ObjectSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require database_path('seeders/data/objects.php');
        $sources = Source::query()->pluck('id', 'code');
        $version = (string) config('sanggah.corpus_version');

        foreach ($rows as $row) {
            $object = CulturalObject::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name_id' => $row['name_id'],
                    'name_en' => $row['name_en'],
                    'aliases' => $row['aliases'],
                    'category' => $row['category'],
                    'visual_hint' => $row['visual_hint'],
                    'static_card_id' => $row['static_card_id'],
                    'static_card_en' => $row['static_card_en'],
                    'is_active' => true,
                ]
            );

            foreach (['id' => $row['static_card_id'], 'en' => $row['static_card_en']] as $lang => $card) {
                $keys = $card['citation_keys'];
                $primary = $sources[$keys[1] ?? 'eiseman-1990'] ?? $sources->first();
                foreach ($card['sections'] as $section => $content) {
                    $chunk = Chunk::query()->updateOrCreate(
                        [
                            'content_hash' => hash('sha256', $object->slug.'|'.$lang.'|'.$section),
                        ],
                        [
                            'source_id' => $primary,
                            'lang' => $lang,
                            'content' => $content,
                            'token_count' => str_word_count(strip_tags($content)),
                            'section' => $section,
                            'embedding_model' => 'lexical-v1',
                            'corpus_version' => $version,
                        ]
                    );
                    $object->chunks()->syncWithoutDetaching([$chunk->id]);
                }
            }
        }
    }
}

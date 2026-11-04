<?php

namespace Database\Seeders;

use App\Models\CalendarRule;
use App\Models\Source;
use App\Services\Calendar\DewasaClause;
use Illuminate\Database\Seeder;

class CalendarRuleSeeder extends Seeder
{
    public function run(): void
    {
        $sources = Source::query()->pluck('id', 'code');
        $version = (string) config('sanggah.rules_version');
        $ayu = [
            ['saptawara' => 'Redite', 'penanggal' => 6],
            ['saptawara' => 'Soma', 'penanggal' => 3],
            ['saptawara' => 'Anggara', 'penanggal' => 7],
            ['saptawara' => 'Buda', 'penanggal' => 12],
            ['saptawara' => 'Buda', 'penanggal' => 13],
            ['saptawara' => 'Wraspati', 'penanggal' => 5],
            ['saptawara' => 'Sukra', 'penanggal' => 1],
            ['saptawara' => 'Saniscara', 'penanggal' => 5],
        ];

        $rules = [
            [
                'code' => 'ayu_nulus',
                'name_id' => 'Ayu Nulus',
                'name_en' => 'Ayu Nulus',
                'rule_type' => 'dewasa_ayu',
                'activity_tags' => ['buka_usaha'],
                'conditions' => ['any' => array_map(fn ($pair) => ['all' => [
                    ['saptawara' => $pair['saptawara']],
                    ['penanggal' => $pair['penanggal']],
                ]], $ayu)],
                'effect' => 'good',
                'source_id' => $sources['kalenderbali-alaayu'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
            ],
            [
                'code' => 'sri_tumpuk',
                'name_id' => 'Sri Tumpuk (caturwara dan astawara sama-sama Sri)',
                'name_en' => 'Sri Tumpuk (Sri on both caturwara and astawara)',
                'rule_type' => 'dewasa_ayu',
                'activity_tags' => ['buka_usaha'],
                'conditions' => ['caturwara' => 'Sri', 'astawara' => 'Sri'],
                'effect' => 'good',
                'source_id' => $sources['kalenderbali-alaayu'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
                'notes_ignored' => 'Heuristic published in wariga notes. Not a ruling.',
            ],
            [
                'code' => 'ala_dahat_saniscara',
                'name_id' => 'Ala Dahat (Saniscara pada Purnama atau Tilem)',
                'name_en' => 'Ala Dahat (Saniscara on Purnama or Tilem)',
                'rule_type' => 'dewasa_ala',
                'activity_tags' => ['buka_usaha', 'umum'],
                'conditions' => ['any' => [
                    ['all' => [['saptawara' => 'Saniscara'], ['is_purnama' => true]]],
                    ['all' => [['saptawara' => 'Saniscara'], ['is_tilem' => true]]],
                ]],
                'effect' => 'bad',
                'source_id' => $sources['sastrabali-wariga'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
            ],
            [
                'code' => 'amerta_bumi',
                'name_id' => 'Amerta Bumi (hari baik untuk nikah, subset terbit)',
                'name_en' => 'Amerta Bumi (a published good day for marriage)',
                'rule_type' => 'dewasa_ayu',
                'activity_tags' => ['nikah'],
                'conditions' => ['any' => [
                    ['all' => [['saptawara' => 'Soma'], ['pancawara' => 'Wage'], ['penanggal' => 1]]],
                    ['all' => [['saptawara' => 'Buda'], ['pancawara' => 'Pon'], ['penanggal' => 10]]],
                ]],
                'effect' => 'good',
                'source_id' => $sources['kalenderbali-alaayu'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
            ],
            [
                'code' => 'kamajaya',
                'name_id' => 'Kamajaya (hari baik untuk nikah, subset terbit)',
                'name_en' => 'Kamajaya (a published good day for marriage)',
                'rule_type' => 'dewasa_ayu',
                'activity_tags' => ['nikah'],
                'conditions' => ['any' => [
                    ['all' => [['saptawara' => 'Buda'], ['penanggal' => 2]]],
                    ['all' => [['saptawara' => 'Buda'], ['penanggal' => 3]]],
                    ['all' => [['saptawara' => 'Buda'], ['penanggal' => 7]]],
                ]],
                'effect' => 'good',
                'source_id' => $sources['kalenderbali-alaayu'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
            ],
            [
                'code' => 'derman_bagia',
                'name_id' => 'Derman Bagia (hari baik untuk nikah, subset terbit)',
                'name_en' => 'Derman Bagia (a published good day for marriage)',
                'rule_type' => 'dewasa_ayu',
                'activity_tags' => ['nikah'],
                'conditions' => ['any' => [
                    ['all' => [['saptawara' => 'Soma'], ['penanggal' => 2]]],
                    ['all' => [['saptawara' => 'Soma'], ['penanggal' => 3]]],
                    ['all' => [['saptawara' => 'Soma'], ['penanggal' => 5]]],
                    ['all' => [['saptawara' => 'Soma'], ['penanggal' => 12]]],
                ]],
                'effect' => 'good',
                'source_id' => $sources['kalenderbali-alaayu'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
            ],
            [
                'code' => 'nyepi_brata',
                'name_id' => 'Catur Brata Penyepian (tidak memulai kerja atau bepergian)',
                'name_en' => 'Catur Brata of Nyepi (no starting work or travel)',
                'rule_type' => 'dewasa_ala',
                'activity_tags' => ['buka_usaha', 'pindah_rumah', 'umum'],
                'conditions' => ['rerahinan_contains' => 'nyepi'],
                'effect' => 'bad',
                'source_id' => $sources['bimas-hindu-2026'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
            ],
        ];

        foreach ($rules as $rule) {
            unset($rule['notes_ignored']);
            $rule['weight'] = 1;
            $rule['rules_version'] = $version;
            CalendarRule::query()->updateOrCreate(['code' => $rule['code']], $rule);
        }

        /** @var list<array{code: string, name: string, activities: list<string>, clauses: list<string>}> $extra */
        $extra = require database_path('seeders/data/dewasa.php');
        foreach ($extra as $row) {
            CalendarRule::query()->updateOrCreate(['code' => $row['code']], [
                'name_id' => $row['name'],
                'name_en' => $row['name'],
                'rule_type' => 'dewasa_ayu',
                'activity_tags' => $row['activities'],
                'conditions' => DewasaClause::any($row['clauses']),
                'effect' => 'good',
                'weight' => 1,
                'source_id' => $sources['kalenderbali-alaayu'] ?? null,
                'status' => 'validated',
                'validated_by' => 'tim',
                'rules_version' => $version,
            ]);
        }
    }
}

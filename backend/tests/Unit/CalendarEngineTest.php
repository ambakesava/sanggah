<?php

namespace Tests\Unit;

use App\Services\Calendar\AnswerGuard;
use App\Services\Calendar\CalendarEngine;
use App\Services\Calendar\RelativeDateResolver;
use App\Services\Calendar\RuleEvaluator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class CalendarEngineTest extends TestCase
{
    private CalendarEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new CalendarEngine;
    }

    public function test_epoch_day_matches_the_published_first_row(): void
    {
        $day = $this->engine->describe(new DateTimeImmutable('2020-07-05'));

        $this->assertSame(1, $day['pawukon_day']);
        $this->assertSame('Sinta', $day['wuku']);
        $this->assertSame('Redite', $day['saptawara']);
        $this->assertSame('Paing', $day['pancawara']);
        $this->assertSame('Pasah', $day['triwara']);
        $this->assertSame('Tungleh', $day['sadwara']);
        $this->assertSame('Sri', $day['caturwara']);
        $this->assertSame('Sri', $day['astawara']);
        $this->assertSame('Dangu', $day['sangawara']);
        $this->assertSame('Sri', $day['dasawara']);
        $this->assertSame('Menga', $day['dwiwara']);
        $this->assertNull($day['ekawara']);
        $this->assertSame(5, $day['urip']);
    }

    public function test_galungan_kuningan_and_pagerwesi_reference_dates(): void
    {
        $galungan = $this->engine->describe(new DateTimeImmutable('2026-06-17'));
        $this->assertSame(74, $galungan['pawukon_day']);
        $this->assertSame('Buda', $galungan['saptawara']);
        $this->assertSame('Keliwon', $galungan['pancawara']);
        $this->assertSame('Dunggulan', $galungan['wuku']);
        $this->assertSame(['galungan'], $galungan['rerahinan']);

        $penampahan = $this->engine->describe(new DateTimeImmutable('2026-06-16'));
        $this->assertSame(73, $penampahan['pawukon_day']);
        $this->assertSame('Anggara', $penampahan['saptawara']);
        $this->assertSame('Wage', $penampahan['pancawara']);
        $this->assertSame('Dunggulan', $penampahan['wuku']);
        $this->assertContains('penampahan_galungan', $penampahan['rerahinan']);

        $kuningan = $this->engine->describe(new DateTimeImmutable('2026-06-27'));
        $this->assertSame(84, $kuningan['pawukon_day']);
        $this->assertSame('Saniscara', $kuningan['saptawara']);
        $this->assertSame('Keliwon', $kuningan['pancawara']);
        $this->assertSame('Kuningan', $kuningan['wuku']);
        $this->assertContains('kuningan', $kuningan['rerahinan']);

        foreach (['2026-11-04', '2026-04-08'] as $date) {
            $pagerwesi = $this->engine->describe(new DateTimeImmutable($date));
            $this->assertSame(4, $pagerwesi['pawukon_day'], $date);
            $this->assertSame('Buda', $pagerwesi['saptawara']);
            $this->assertSame('Keliwon', $pagerwesi['pancawara']);
            $this->assertSame('Sinta', $pagerwesi['wuku']);
            $this->assertContains('pagerwesi', $pagerwesi['rerahinan']);
        }

        foreach (['2026-04-04', '2026-10-31'] as $date) {
            $saraswati = $this->engine->describe(new DateTimeImmutable($date));
            $this->assertSame(210, $saraswati['pawukon_day'], $date);
            $this->assertSame('Saniscara', $saraswati['saptawara']);
            $this->assertSame('Umanis', $saraswati['pancawara']);
            $this->assertSame('Watugunung', $saraswati['wuku']);
            $this->assertContains('saraswati', $saraswati['rerahinan']);
        }
    }

    public function test_nyepi_comes_only_from_the_sasih_row(): void
    {
        $bare = $this->engine->describe(new DateTimeImmutable('2026-03-19'));
        $this->assertNotContains('nyepi', $bare['rerahinan']);

        $withTable = $this->engine->describe(new DateTimeImmutable('2026-03-19'), [
            'sasih' => 'Kedasa',
            'penanggal_pangelong' => 'penanggal 1',
            'is_purnama' => false,
            'is_tilem' => false,
            'observances' => ['nyepi'],
        ]);
        $this->assertContains('nyepi', $withTable['rerahinan']);
        $this->assertSame('Kedasa', $withTable['sasih']);
        $this->assertSame('penanggal', $withTable['phase']);
        $this->assertSame(1, $withTable['phase_day']);
    }

    public function test_jaya_and_kala_repeat_on_days_71_through_73(): void
    {
        $epoch = new DateTimeImmutable('2020-07-05');
        foreach ([70, 71, 72] as $offset) {
            $day = $this->engine->describe($epoch->modify('+'.$offset.' day'));
            $this->assertSame('Jaya', $day['caturwara'], 'day '.($offset + 1));
            $this->assertSame('Kala', $day['astawara'], 'day '.($offset + 1));
        }
        $after = $this->engine->describe($epoch->modify('+73 day'));
        $this->assertSame(74, $after['pawukon_day']);
        $this->assertSame('Menala', $after['caturwara']);
        $this->assertSame('Uma', $after['astawara']);
    }

    public function test_cycle_and_weekday_properties(): void
    {
        mt_srand(2026);
        for ($i = 0; $i < 300; $i++) {
            $date = (new DateTimeImmutable('1900-01-01'))->modify('+'.mt_rand(0, 80000).' day');
            $day = $this->engine->describe($date);
            $named = new DateTimeImmutable($day['date'].' 12:00:00', new \DateTimeZone('Asia/Makassar'));
            $weekday = ['Redite', 'Soma', 'Anggara', 'Buda', 'Wraspati', 'Sukra', 'Saniscara'][(int) $named->format('w')];
            $this->assertSame($weekday, $day['saptawara']);
            $this->assertContains($day['wuku'], CalendarEngine::WUKU);
            $again = $this->engine->describe($date->modify('+210 day'));
            $this->assertSame($day['pawukon_day'], $again['pawukon_day']);
            $this->assertSame($day['wuku'], $again['wuku']);
        }
    }

    public function test_relative_dates_and_rule_evaluator(): void
    {
        $resolver = new RelativeDateResolver;
        $today = new DateTimeImmutable('2026-11-04');
        $next = $resolver->resolve('minggu depan', $today);
        $this->assertSame(['from' => '2026-11-09', 'to' => '2026-11-15'], $next);

        $rules = new RuleEvaluator;
        $matched = $rules->match([
            'saptawara' => 'Buda',
            'phase' => 'penanggal',
            'phase_day' => 12,
            'rerahinan' => [],
            'caturwara' => 'Laba',
            'astawara' => 'Indra',
            'is_purnama' => false,
            'is_tilem' => false,
        ], [[
            'code' => 'ayu_nulus',
            'status' => 'validated',
            'effect' => 'good',
            'activity_tags' => ['buka_usaha'],
            'conditions' => ['all' => [
                ['saptawara' => 'Buda'],
                ['penanggal' => 12],
            ]],
        ]], 'buka_usaha');
        $this->assertCount(1, $matched);

        $guard = new AnswerGuard;
        $payload = [['date' => '2026-06-17', 'wuku' => 'Dunggulan', 'saptawara' => 'Buda', 'pancawara' => 'Keliwon', 'triwara' => 'Beteng', 'caturwara' => 'Menala', 'astawara' => 'Uma', 'sasih' => null, 'rerahinan' => ['galungan']]];
        $this->assertTrue($guard->accepts('On 2026-06-17 it is Galungan, Buda Keliwon, wuku Dunggulan.', $payload));
        $this->assertFalse($guard->accepts('Galungan is on 2026-06-18.', $payload));
        $this->assertFalse($guard->accepts('It is also Nyepi.', $payload));
    }
}

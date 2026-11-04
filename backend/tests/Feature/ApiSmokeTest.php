<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_health_and_reference_calendar_dates(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');

        $galungan = $this->getJson('/api/calendar/2026-06-17')->assertOk();
        $galungan->assertJsonPath('data.pawukon_day', 74);
        $galungan->assertJsonPath('data.wuku', 'Dunggulan');
        $galungan->assertJsonPath('data.saptawara', 'Buda');
        $galungan->assertJsonPath('data.pancawara', 'Keliwon');
        $this->assertContains('galungan', $galungan->json('data.rerahinan'));

        $this->getJson('/api/calendar/2026-06-27')
            ->assertOk()
            ->assertJsonPath('data.pawukon_day', 84)
            ->assertJsonPath('data.wuku', 'Kuningan');

        $this->getJson('/api/calendar/2026-11-04')
            ->assertOk()
            ->assertJsonPath('data.pawukon_day', 4)
            ->assertJsonPath('data.wuku', 'Sinta');

        $nyepi = $this->getJson('/api/calendar/2026-03-19')->assertOk();
        $this->assertContains('nyepi', $nyepi->json('data.rerahinan'));
        $this->assertSame('Kedasa', $nyepi->json('data.sasih'));
        $this->assertSame('penanggal 1', $nyepi->json('data.penanggal_pangelong'));
    }

    public function test_ask_ceremony_on_17_june_2026_is_galungan(): void
    {
        $response = $this->postJson('/api/ask', [
            'question' => 'is there a ceremony on 17 June 2026?',
            'lang' => 'en',
        ])->assertOk();

        $response->assertJsonPath('intent.intent', 'cek_upacara');
        $response->assertJsonPath('engine.days.0.date', '2026-06-17');
        $response->assertJsonPath('engine.days.0.pawukon_day', 74);
        $response->assertJsonPath('engine.days.0.wuku', 'Dunggulan');
        $response->assertJsonPath('engine.days.0.saptawara', 'Buda');
        $response->assertJsonPath('engine.days.0.pancawara', 'Keliwon');
        $this->assertContains('galungan', $response->json('engine.days.0.rerahinan'));
        $this->assertNotEmpty($response->json('disclaimer'));
        $this->assertStringContainsString('pemangku', strtolower($response->json('answer')));
    }

    public function test_ask_out_of_scope_and_relative_business_day(): void
    {
        $this->postJson('/api/ask', [
            'question' => 'what is the weather in Paris tomorrow?',
            'lang' => 'en',
        ])->assertOk()->assertJsonPath('intent.intent', 'out_of_scope');

        $this->travelTo('2026-11-04 09:00:00');
        $response = $this->postJson('/api/ask', [
            'question' => 'hari baik buka usaha minggu depan?',
            'lang' => 'id',
        ])->assertOk();
        $response->assertJsonPath('intent.intent', 'hari_baik');
        $response->assertJsonPath('intent.activity', 'buka_usaha');
        $response->assertJsonPath('engine.days.0.date', '2026-11-09');
        $this->assertCount(7, $response->json('engine.days'));

        $this->travelTo('2026-11-05 02:00:00');
        $nikah = $this->postJson('/api/ask', [
            'question' => 'saya mau nikah, kapan hari terbaik pling deket untuk saya nikah',
            'lang' => 'id',
        ])->assertOk();
        $nikah->assertJsonPath('intent.intent', 'hari_baik');
        $nikah->assertJsonPath('intent.activity', 'nikah');
        $nikah->assertJsonPath('engine.days.0.recommended', true);
        $this->assertGreaterThan('2026-11-05', $nikah->json('engine.days.0.date'));
        $this->assertStringContainsString($nikah->json('engine.days.0.date'), $nikah->json('answer'));

        $galungan = $this->postJson('/api/ask', [
            'question' => 'galungan 2026 kapan',
            'lang' => 'id',
        ])->assertOk();
        $galungan->assertJsonPath('intent.year', 2026);
        $galungan->assertJsonPath('engine.days.0.date', '2026-06-17');
        $this->assertContains('galungan', $galungan->json('engine.days.0.rerahinan'));
        $this->assertCount(1, $galungan->json('engine.days'));
        $this->assertStringContainsString('bimashindu.kemenag.go.id', json_encode($galungan->json('engine.days.0.ceremonies')));

        Cache::flush();
        Http::fake([
            'https://kalenderbali.org/*' => Http::response(<<<'HTML'
                <ul><li>17 Juni 2026. Hari Raya Galungan. Dari situs.</li></ul>
            HTML, 200),
        ]);
        $live = $this->postJson('/api/ask', [
            'question' => 'galungan 2026 kapan',
            'lang' => 'id',
        ])->assertOk();
        $live->assertJsonPath('engine.days.0.date', '2026-06-17');
        $live->assertJsonPath('engine.days.0.kalenderbali.0.name', 'Hari Raya Galungan');
        $this->assertStringContainsString('kalenderbali.org/rerainan.php', $live->json('engine.days.0.kalenderbali.0.url'));
    }

    public function test_ask_answer_is_prose_without_an_engine_dump(): void
    {
        $this->travelTo('2026-11-05 02:00:00');
        $near = $this->postJson('/api/ask', [
            'question' => 'hari baik untuk ngaben terdekat',
            'lang' => 'id',
        ])->assertOk();
        $answer = (string) $near->json('answer');
        $this->assertStringNotContainsString('pawukon day', strtolower($answer));
        $this->assertStringNotContainsString('Calendar engine', $answer);
        $this->assertStringNotContainsString('Mesin kalender', $answer);
        $this->assertStringNotContainsString('kajeng_keliwon', $answer);
        $this->assertStringNotContainsString((string) $near->json('disclaimer'), $answer);
        $this->assertStringContainsString('pemangku', strtolower($answer));
        $near->assertJsonPath('engine.days.0.recommended', true);
        $near->assertJsonPath('engine.days.0.date', '2026-11-24');
        $this->assertStringContainsString('2026-11-24', $answer);

        $week = $this->postJson('/api/ask', [
            'question' => 'hari baik untuk ngaben minggu depan',
            'lang' => 'id',
        ])->assertOk();
        $text = (string) $week->json('answer');
        $this->assertStringNotContainsString('pawukon day', strtolower($text));
        $this->assertStringNotContainsString('kajeng_keliwon', $text);
        $this->assertStringContainsString('Kajeng Kliwon', $text);
        $this->assertStringContainsString('Tumpek Landep', $text);
        $this->assertStringContainsString('pemangku', strtolower($text));
        $this->assertStringNotContainsString((string) $week->json('disclaimer'), $text);
        $this->assertFalse(collect($week->json('engine.days'))->contains(
            fn (array $day) => ($day['recommended'] ?? false) === true
        ));
    }

    public function test_potong_gigi_returns_a_validated_day(): void
    {
        $this->travelTo('2026-11-05 02:00:00');
        $response = $this->postJson('/api/ask', [
            'question' => 'kapan hari baik untuk potong gigi',
            'lang' => 'id',
        ])->assertOk();

        $response->assertJsonPath('intent.intent', 'hari_baik');
        $response->assertJsonPath('intent.activity', 'potong_gigi');
        $response->assertJsonPath('engine.days.0.recommended', true);
        $response->assertJsonPath('engine.days.0.date', '2026-07-24');
        $this->assertNotEmpty($response->json('engine.days.0.rules'));
    }

    public function test_scan_filename_hint_explain_and_rejection(): void
    {
        $png = $this->png();
        $ok = $this->post('/api/scan', [
            'image' => UploadedFile::fake()->createWithContent('penjor.png', $png),
            'lang' => 'id',
            'store_photo' => '0',
        ]);
        $ok->assertOk();
        $ok->assertJsonPath('status', 'identified');
        $ok->assertJsonPath('object.slug', 'penjor');
        $ok->assertJsonPath('photo_stored', false);
        $this->assertNotEmpty($ok->json('explanation.sections.meaning'));
        $this->assertNotEmpty($ok->json('explanation.citations'));

        $unsure = $this->post('/api/scan', [
            'image' => UploadedFile::fake()->createWithContent('pantai.png', $png),
            'lang' => 'id',
        ]);
        $unsure->assertOk()->assertJsonPath('status', 'needs_confirmation');
        $this->assertArrayNotHasKey('explanation', $unsure->json());

        $this->post('/api/scan', [
            'image' => UploadedFile::fake()->createWithContent('catatan.jpg', '%PDF-1.4 not an image'),
            'lang' => 'id',
        ])->assertStatus(415)->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');

        config(['sanggah.ai.force_fail' => true]);
        $this->post('/api/scan', [
            'image' => UploadedFile::fake()->createWithContent('penjor.png', $png),
            'lang' => 'id',
        ])->assertStatus(503)->assertJsonPath('error.code', 'AI_UNAVAILABLE');
    }

    public function test_manual_explain_follow_up_and_objects(): void
    {
        $this->postJson('/api/explain', [
            'object_slug' => 'kori_agung',
            'lang' => 'en',
            'question' => 'Can I enter the temple?',
        ])->assertOk()->assertJsonPath('status', 'fallback_static');

        $this->postJson('/api/explain', [
            'object_slug' => 'kori_agung',
            'lang' => 'en',
            'question' => 'What is the price of Bitcoin today?',
        ])->assertOk()->assertJsonPath('status', 'no_verified_source');

        $this->getJson('/api/objects?lang=id')->assertOk()->assertJsonFragment(['slug' => 'canang_sari']);
    }

    public function test_ceremonies_are_public_and_admin_crud_needs_sanctum(): void
    {
        $this->getJson('/api/ceremonies?from=2026-06-01&to=2026-06-30&lang=id')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'galungan-2026-06', 'date_start' => '2026-06-17']);

        $this->getJson('/api/admin/ceremonies')->assertUnauthorized();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'juri@sanggah.test',
            'password' => 'Sanggah-Juri-2026',
        ])->assertOk();
        $token = $login->json('token');

        $created = $this->withToken($token)->postJson('/api/admin/ceremonies', [
            'name_id' => 'Odalan Uji',
            'name_en' => 'Test Odalan',
            'type' => 'lokal',
            'date_start' => '2026-12-01',
            'tips_id' => 'Kenakan kain.',
            'tips_en' => 'Wear a cloth.',
            'summary_id' => 'Uji admin.',
            'summary_en' => 'Admin test.',
            'source_ids' => [Source::query()->value('id')],
            'status' => 'published',
        ])->assertCreated();

        $slug = $created->json('data.slug');
        $this->getJson('/api/ceremonies/'.$slug.'?lang=en')->assertOk()->assertJsonPath('data.road_note_label', 'Curated info, not real-time');
        $this->get('/api/ceremonies/'.$slug.'.ics')->assertOk()->assertHeader('content-type', 'text/calendar; charset=utf-8');

        $guest = User::factory()->create([
            'email' => 'tamu@sanggah.test',
            'role' => 'user',
        ]);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($guest->createToken('nope')->plainTextToken)
            ->getJson('/api/admin/ceremonies')
            ->assertForbidden();
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(16, 16);
        $green = imagecolorallocate($image, 20, 90, 60);
        imagefilledrectangle($image, 0, 0, 15, 15, $green);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}

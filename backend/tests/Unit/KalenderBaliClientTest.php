<?php

namespace Tests\Unit;

use App\Services\Calendar\KalenderBaliClient;
use PHPUnit\Framework\TestCase;

class KalenderBaliClientTest extends TestCase
{
    public function test_it_reads_a_rerainan_list(): void
    {
        $html = <<<'HTML'
        <h1>Rerainan Bulan Juni 2026</h1>
        <ul>
            <li>16 Juni 2026. Penampahan Galungan. Persiapan.</li>
            <li>17 Juni 2026. Hari Raya Galungan. Kemenangan dharma.</li>
            <li>27 Juni 2026. Hari Raya Kuningan.</li>
        </ul>
        HTML;

        $events = (new KalenderBaliClient)->parse($html, 'https://kalenderbali.org/rerainan.php?bl=6&th=2026');

        $this->assertSame('2026-06-17', $events[1]['date']);
        $this->assertSame('Hari Raya Galungan', $events[1]['name']);
        $this->assertSame('https://kalenderbali.org/rerainan.php?bl=6&th=2026', $events[1]['url']);
    }
}

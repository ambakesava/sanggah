<?php

namespace Tests\Unit;

use App\Services\Calendar\DewasaClause;
use PHPUnit\Framework\TestCase;

class DewasaClauseTest extends TestCase
{
    public function test_it_parses_a_published_potong_gigi_phrase(): void
    {
        $parsed = DewasaClause::one('Buda Keliwon Penanggal 12');

        $this->assertSame([
            'all' => [
                ['saptawara' => 'Buda'],
                ['pancawara' => 'Keliwon'],
                ['penanggal' => 12],
            ],
        ], $parsed);
    }
}
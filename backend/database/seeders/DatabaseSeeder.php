<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            SourceSeeder::class,
            ObjectSeeder::class,
            CalendarRuleSeeder::class,
            SasihCalendarSeeder::class,
            CeremonySeeder::class,
        ]);
    }
}

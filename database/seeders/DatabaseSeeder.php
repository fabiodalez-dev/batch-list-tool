<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            InitialDataSeeder::class,
        ]);

        // Feedback1 C1.5 — NRA physical location values (St Chris / St Paul /
        // RAS) live in {@see NraLocationSeeder}. It is intentionally NOT chained
        // here: a generic `db:seed` must not create them automatically (they are
        // deployment-specific reference data). Run it deliberately and standalone:
        //   php artisan db:seed --class=Database\\Seeders\\NraLocationSeeder
        // The seeder is idempotent (firstOrCreate), so re-running is safe.
    }
}

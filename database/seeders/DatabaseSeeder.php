<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demonstration data is for local development only — never production.
        if ($this->command && app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        } elseif ($this->command) {
            $this->command->warn('Skipping demo data outside local/testing environments.');
        }
    }
}

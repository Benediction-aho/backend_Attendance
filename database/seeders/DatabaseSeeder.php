<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Reference data (permission tiers, setting defaults) is created by the
     * migrations, and the first super admin by `php artisan
     * attendance:create-super-admin`. No default user is seeded, so that no
     * predictable account ever exists in a deployed environment.
     */
    public function run(): void
    {
        //
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class FcLocationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UsFcLocationsSeeder::class);
    }
}

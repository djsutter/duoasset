<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CurrencySeeder::class,
            PlatformSeeder::class,
            ClassificationSeeder::class,
        ]);

        if (config('app.demo_mode')) {
            $this->call([
                UploadedFilesDemoSeeder::class,
            ]);
        } else {
            $this->call([
                UploadedFilesSeeder::class,
            ]);
        }
    }
}

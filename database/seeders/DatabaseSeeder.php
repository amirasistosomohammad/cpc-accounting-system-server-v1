<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            // PersonnelSeeder::class,
            AccountTypeSeeder::class, // Seed account types first
            ChartOfAccountSeeder::class 
            // JournalEntrySeeder::class,
            // JournalEntryLargeNumbersSeeder::class,
        ]);
    }
}

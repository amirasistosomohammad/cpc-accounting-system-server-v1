<?php

namespace Database\Seeders;

use App\Models\Personnel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PersonnelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $personnel = [
            [
                'username' => 'john.doe',
                'password' => Hash::make('password123'),
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            [
                'username' => 'jane.smith',
                'password' => Hash::make('password123'),
                'first_name' => 'Jane',
                'last_name' => 'Smith',
            ],
            [
                'username' => 'mike.johnson',
                'password' => Hash::make('password123'),
                'first_name' => 'Mike',
                'last_name' => 'Johnson',
            ],
            [
                'username' => 'sarah.williams',
                'password' => Hash::make('password123'),
                'first_name' => 'Sarah',
                'last_name' => 'Williams',
            ],
            [
                'username' => 'david.brown',
                'password' => Hash::make('password123'),
                'first_name' => 'David',
                'last_name' => 'Brown',
            ],
        ];

        foreach ($personnel as $person) {
            Personnel::create($person);
        }
    }
}

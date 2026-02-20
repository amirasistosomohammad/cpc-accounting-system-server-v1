<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Account;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates the default admin and links them to the default business account
     * (created by migration backfill_default_account).
     */
    public function run(): void
    {
        $admin = Admin::create([
            'username' => 'admin@admin.com',
            'password' => Hash::make('123456'),
            'name' => 'System Administrator',
        ]);

        // Link admin to the default account so they have access after login
        $defaultAccount = Account::find(1);
        if ($defaultAccount) {
            $admin->accounts()->syncWithoutDetaching([$defaultAccount->id]);
        }
    }
}

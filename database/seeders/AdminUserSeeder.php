<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@yaan.com'],
            [
                'name'         => 'Super Admin',
                'phone'        => '9999999999',
                'password'     => Hash::make('admin123456'),
                'role'         => 'admin',
                'is_verified'  => DB::raw('true'),
                'firebase_uid' => 'admin_bypass_uid',
            ]
        );
    }
}

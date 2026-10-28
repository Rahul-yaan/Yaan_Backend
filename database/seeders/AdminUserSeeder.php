<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.admin_email') ?? 'admin@yaan.com';
        $password = config('app.admin_password');

        $admin = User::where('email', $email)->orWhere('role', 'admin')->first();

        // Without an explicit ADMIN_PASSWORD, create nothing
        if (empty($password)) {
            // If an existing admin still uses the insecure legacy default password, replace it with a random string
            if ($admin && Hash::check('admin123456', $admin->password)) {
                $admin->password = Hash::make(Str::random(32));
                $admin->save();
            }
            return;
        }

        // Require at least 10 characters for production security
        if (strlen($password) < 10) {
            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'         => 'Super Admin',
                'phone'        => '9999999999',
                'password'     => Hash::make($password),
                'role'         => 'admin',
                'is_verified'  => true,
                'firebase_uid' => 'admin_' . md5($email),
            ]
        );
    }
}

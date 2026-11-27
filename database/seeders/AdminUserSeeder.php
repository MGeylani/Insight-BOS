<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Creates the first super admin from ADMIN_EMAIL / ADMIN_PASSWORD (set them in the server .env).
     * Without ADMIN_PASSWORD a random one is generated and printed once.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@insightpromedia.com');

        if (User::withTrashed()->where('email', $email)->exists()) {
            return;
        }

        $password = env('ADMIN_PASSWORD') ?: Str::password(16, symbols: false);

        $user = User::create([
            'name' => env('ADMIN_NAME', 'مدير النظام'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

        if (! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Admin created: $email / $password  (change it after first login)");
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            MenuSeeder::class,
            SystemTasksSeeder::class,
        ]);

        $this->seedAdmin();
    }

    private function seedAdmin(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@example.com');
        $password = (string) env('ADMIN_PASSWORD', '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20);
        } elseif (($validator = Validator::make(['password' => $password], ['password' => Password::defaults()]))->fails()) {
            // Gleiche Regel wie in der Oberfläche: kein schwaches erstes Admin-Passwort über die .env.
            throw new RuntimeException('ADMIN_PASSWORD: '.implode(' ', $validator->errors()->all()));
        }

        $admin = User::withTrashed()->firstOrNew(['email' => $email]);

        if (! $admin->exists) {
            $admin->fill([
                'name' => (string) env('ADMIN_NAME', 'Administrator'),
                'password' => $password,
                'email_verified_at' => now(),
                'is_active' => true,
            ])->save();

            if ($generated) {
                $this->command?->warn("Admin-Passwort für {$email} (generiert, bitte notieren): {$password}");
            }
        }

        $admin->assignRole(User::ROLE_SUPER_ADMIN);
    }
}

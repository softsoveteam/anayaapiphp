<?php

namespace Database\Seeders;

use App\Enums\EmployeeStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['admin', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $admin = User::query()->updateOrCreate(
            ['unique_id' => 'ANAYA-ADMIN'],
            [
                'name' => 'Admin',
                'email' => 'admin@anaya.local',
                'status' => EmployeeStatus::Joined,
                'joining_date' => now(),
                'password' => 'Anaya@123',
            ]
        );
        $admin->syncRoles(['admin']);
    }
}

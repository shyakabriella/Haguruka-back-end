<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'System Admin',
                'email' => 'admin@haguruka.rw',
                'phone' => '0780000001',
                'password' => 'password123',
                'status' => 'active',
                'is_active' => true,
                'role_slug' => 'admin',
            ],
            [
                'name' => 'Haguruka Staff',
                'email' => 'staff@haguruka.rw',
                'phone' => '0780000002',
                'password' => 'password123',
                'status' => 'active',
                'is_active' => true,
                'role_slug' => 'haguruka_staff',
            ],
            [
                'name' => 'Police Officer',
                'email' => 'police@haguruka.rw',
                'phone' => '0780000003',
                'password' => 'password123',
                'status' => 'active',
                'is_active' => true,
                'role_slug' => 'police',
            ],
            [
                'name' => 'Health Officer',
                'email' => 'health@haguruka.rw',
                'phone' => '0780000004',
                'password' => 'password123',
                'status' => 'active',
                'is_active' => true,
                'role_slug' => 'health_officer',
            ],
            [
                'name' => 'Local Leader',
                'email' => 'leader@haguruka.rw',
                'phone' => '0780000005',
                'password' => 'password123',
                'status' => 'active',
                'is_active' => true,
                'role_slug' => 'local_leader',
            ],
        ];

        foreach ($users as $item) {
            $roleSlug = $item['role_slug'];
            unset($item['role_slug']);

            $user = User::updateOrCreate(
                ['email' => $item['email']],
                $item
            );

            if (Schema::hasTable('roles') && Schema::hasTable('role_user')) {
                $role = Role::where('slug', $roleSlug)->first();

                if ($role) {
                    $user->roles()->syncWithoutDetaching([$role->id]);
                }
            }
        }
    }
}
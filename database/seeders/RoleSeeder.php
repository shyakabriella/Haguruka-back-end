<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'System administrator with full access',
                'is_active' => true,
            ],
            [
                'name' => 'Haguruka Staff',
                'slug' => 'haguruka_staff',
                'description' => 'Haguruka staff responsible for managing cases',
                'is_active' => true,
            ],
            [
                'name' => 'Police',
                'slug' => 'police',
                'description' => 'Police officer handling escalated violence cases',
                'is_active' => true,
            ],
            [
                'name' => 'Health Officer',
                'slug' => 'health_officer',
                'description' => 'Health facility officer supporting victims',
                'is_active' => true,
            ],
            [
                'name' => 'Local Leader',
                'slug' => 'local_leader',
                'description' => 'Community or local authority representative',
                'is_active' => true,
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                $role
            );
        }
    }
}
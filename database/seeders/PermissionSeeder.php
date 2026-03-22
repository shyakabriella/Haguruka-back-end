<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'View Cases',
                'slug' => 'view_cases',
                'description' => 'Can view reported violence cases',
                'is_active' => true,
            ],
            [
                'name' => 'Create Cases',
                'slug' => 'create_cases',
                'description' => 'Can create new violence case records',
                'is_active' => true,
            ],
            [
                'name' => 'Update Cases',
                'slug' => 'update_cases',
                'description' => 'Can update case details',
                'is_active' => true,
            ],
            [
                'name' => 'Delete Cases',
                'slug' => 'delete_cases',
                'description' => 'Can delete case records',
                'is_active' => true,
            ],
            [
                'name' => 'Assign Cases',
                'slug' => 'assign_cases',
                'description' => 'Can assign cases to staff or authorities',
                'is_active' => true,
            ],
            [
                'name' => 'Escalate Cases',
                'slug' => 'escalate_cases',
                'description' => 'Can escalate urgent cases',
                'is_active' => true,
            ],
            [
                'name' => 'Manage Referrals',
                'slug' => 'manage_referrals',
                'description' => 'Can create and manage referrals',
                'is_active' => true,
            ],
            [
                'name' => 'View Reports',
                'slug' => 'view_reports',
                'description' => 'Can view analytics and reports',
                'is_active' => true,
            ],
            [
                'name' => 'Manage Users',
                'slug' => 'manage_users',
                'description' => 'Can create, update, and deactivate users',
                'is_active' => true,
            ],
            [
                'name' => 'Manage Roles',
                'slug' => 'manage_roles',
                'description' => 'Can manage roles and role assignments',
                'is_active' => true,
            ],
            [
                'name' => 'Manage Permissions',
                'slug' => 'manage_permissions',
                'description' => 'Can manage system permissions',
                'is_active' => true,
            ],
            [
                'name' => 'Manage Awareness Content',
                'slug' => 'manage_awareness_content',
                'description' => 'Can create and update awareness materials',
                'is_active' => true,
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['slug' => $permission['slug']],
                $permission
            );
        }
    }
}
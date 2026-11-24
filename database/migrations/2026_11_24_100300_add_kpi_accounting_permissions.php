<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'manage_kpi' => 'Manage KPI',
        'manage_accounting' => 'Manage Accounting',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name => $displayName) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['display_name' => $displayName]
            );
        }

        // Store admins get every permission by default, so give them the new ones too.
        Role::where('name', 'admin')->get()->each(
            fn (Role $role) => $role->givePermissionTo(array_keys($this->permissions))
        );

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys($this->permissions))->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};

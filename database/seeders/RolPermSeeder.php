<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolPermSeeder extends Seeder
{
    public function run(): void
    {
        // 🔹 Lista de permisos
        $permissions = [
            'view wo',
            'create wo',
            'edit wo',
            'delete wo',

            'view users',
            'create users',
            'edit users',
            'delete users',
        ];

        // Crear permisos (si no existen)
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm, 'guard_name' => 'web'],
                ['name' => $perm, 'guard_name' => 'web']
            );
        }

        // 🔹 Crear roles
        $roles = [
            'Admin',
            'Manager',
            'Worker',
            'Client',
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['name' => $roleName, 'guard_name' => 'web']
            );
        }

        // 🔹 Asignar permisos a roles
        $adminRole = Role::where('name', 'Admin')->first();
        $managerRole = Role::where('name', 'Manager')->first();
        $workerRole = Role::where('name', 'Worker')->first();
        $clientRole = Role::where('name', 'Client')->first();

        // Admin → todos los permisos
        $adminRole->givePermissionTo(Permission::all());

        // Manager → puede ver y editar assets, ver usuarios
        $managerRole->syncPermissions([
            'view wo',
            'edit wo',
            'view users',
        ]);

        // Worker → solo ver assets
        $workerRole->syncPermissions([
            'view wo',
        ]);

        // Client → nada (o si quieres, solo view assets)
        $clientRole->syncPermissions([
            'view wo',
        ]);

        // 🔹 Asignar rol a un usuario (ejemplo: el user con id=1 es Admin)
        $user = User::first();
        if ($user) {
            $user->assignRole('Admin');
        }
    }
}

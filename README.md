# proteng

# PDF
1. composer require barryvdh/laravel-dompdf

# Roles
1. composer require spatie/laravel-permission
2. php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
3. php artisan migrate
4. php artisan db:seed --class=RolPermSeeder

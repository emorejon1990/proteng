<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Panel;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasRole('Admin'),
            'manager' => $this->hasAnyRole(['Admin', 'Manager']),
            'worker' => $this->hasAnyRole(['Admin', 'Manager', 'Worker']),
            'customer' => $this->hasAnyRole(['Customer', 'Inst_Manager']),
            'auth' => $this->hasAnyRole(['Admin', 'Manager', 'Worker', 'Customer', 'Inst_Manager']),
            default => false,
        };
    }

    protected static function booted()
    {
        static::creating(function (User $user) {
            if (!empty($user->role)) {
                $user->assignRole($user->role);
            }
        });
    }

    public function getPanelUrl(): string
    {
        // Highest privilege determines the initial destination, independently of role order.
        foreach ([
            'Admin' => 'admin',
            'Manager' => 'manager',
            'Worker' => 'worker',
            'Customer' => 'customer',
            'Inst_Manager' => 'customer',
        ] as $role => $panel) {
            if ($this->hasRole($role)) {
                return Filament::getPanel($panel)->getUrl();
            }
        }

        abort(403, 'No authorized panel.');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class);
    }

    public function managedInstallations(): HasMany
    {
        return $this->hasMany(Installation::class, 'inst_manager_user_id');
    }

    public function workerInstallations(): HasMany
    {
        return $this->hasMany(Installation::class, 'worker_user_id');
    }

    public function hasRol(array|string $roles): bool
    {
        return $this->hasRole($roles);
    }
}

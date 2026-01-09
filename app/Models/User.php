<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Panel;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        // return str_ends_with($this->email, '@proteng.com') && $this->hasVerifiedEmail();
        return str_ends_with($this->email, '@proteng.com');
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
        return match ($this->getRoleNames()->first()) {
            'Admin' => Filament::getPanel('admin')->getUrl(),
            'Manager' => Filament::getPanel('manager')->getUrl(),
            'Worker' => Filament::getPanel('worker')->getUrl(),
            'Customer' => Filament::getPanel('customer')->getUrl(),
            default => url('/'),
        };
    }

    public function customer()
    {
        return $this->hasOne(Customer::class);
    }
}

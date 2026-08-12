<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'is_admin', 'paymongo_customer_id', 'avatar_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function connectionLinks(): HasMany
    {
        return $this->hasMany(ConnectionLink::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin;
    }

    public function enteredReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class, 'entered_by');
    }

    public function savedPaymentMethods(): HasMany
    {
        return $this->hasMany(SavedPaymentMethod::class);
    }

    /**
     * Avatar chosen in the profile settings (shared 1-4 set with the customer
     * portal). Null falls back to Filament's initials avatar provider.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        if ($this->avatar_id !== null) {
            return asset('avatars/avatar-'.$this->avatar_id.'.svg');
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}

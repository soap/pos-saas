<?php

namespace App\Modules\Identity\Models;

use App\Modules\Tenancy\Models\TenantMembership;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * MFA (app_authentication_*) บังคับเฉพาะ platform admin panel — API ที่ใช้ Sanctum ไม่ผ่าน challenge นี้
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasApiTokens, HasFactory, HasUuids, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** normalize email เป็น lowercase เสมอ (spec §4.1) */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /** panel /admin สำหรับ platform admin ที่ยัง active เท่านั้น */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_platform_admin && $this->status === 'active';
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    protected static function newFactory()
    {
        return UserFactory::new();
    }
}

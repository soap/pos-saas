<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Identity\Models\MembershipRole;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantMembership extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(MembershipRole::class, 'membership_id');
    }
}

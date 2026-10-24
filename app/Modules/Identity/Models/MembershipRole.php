<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipRole extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}

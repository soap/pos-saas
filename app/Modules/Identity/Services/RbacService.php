<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\MembershipRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Errors\ApiException;
use Illuminate\Support\Facades\DB;

class RbacService
{
    /** สร้าง/อัปเดต permissions และ system roles ตาม config/pos.php (เรียกซ้ำได้) */
    public function syncSystemRoles(): void
    {
        DB::transaction(function () {
            $all = config('pos.permissions');
            foreach ($all as $key => $desc) {
                Permission::query()->updateOrCreate(['key' => $key], ['description' => $desc]);
            }
            $permIds = Permission::query()->pluck('id', 'key');

            foreach (config('pos.roles') as $slug => $def) {
                $role = Role::query()->whereNull('tenant_id')->where('slug', $slug)->first()
                    ?? Role::create(['tenant_id' => null, 'slug' => $slug, 'name' => $def['name']]);
                $role->update(['name' => $def['name']]);

                $keys = [];
                foreach ($def['permissions'] as $p) {
                    if ($p === '*') {
                        $keys = array_merge($keys, array_keys($all));
                    } elseif (str_starts_with($p, '!')) {
                        $keys = array_diff($keys, [substr($p, 1)]);
                    } else {
                        $keys[] = $p;
                    }
                }
                $role->permissions()->sync($permIds->only(array_unique($keys))->values()->all());
            }
        });
    }

    public function findAssignableRole(string $tenantId, string $slug): Role
    {
        // tenant custom role มาก่อน system role ที่ชื่อซ้ำ
        $role = Role::query()->where('slug', $slug)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByRaw('tenant_id IS NULL')
            ->first();

        return $role ?? throw ApiException::unprocessable('role_not_found', "ไม่พบ role: {$slug}");
    }

    /** มอบ role ให้ membership; ป้องกัน role ข้าม tenant (spec §4.1) */
    public function assign(TenantMembership $membership, Role $role, ?string $storeId = null): MembershipRole
    {
        if ($role->tenant_id !== null && $role->tenant_id !== $membership->tenant_id) {
            throw ApiException::forbidden('role_cross_tenant', 'ไม่สามารถมอบ role ข้าม tenant');
        }

        return MembershipRole::query()->withoutGlobalScopes()->firstOrCreate([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'role_id' => $role->id,
            'store_id' => $storeId,
        ]);
    }
}

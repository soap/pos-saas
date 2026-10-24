<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RbacService;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreMembership;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class TenantProvisioner
{
    public function __construct(private RbacService $rbac, private AuditLogger $audit, private TenantContext $ctx) {}

    /**
     * สร้าง tenant + owner + สาขาแรก + subscription (trial หรือ active)
     *
     * @param  array{name:string,slug:string,timezone?:string,plan_code:string,owner:array{name:string,email:string,password?:string},store:array{code:string,name:string,timezone?:string}}  $data
     */
    public function provision(array $data): Tenant
    {
        // tenant ยังไม่มีอยู่จริง จึงไม่มี context — ทุก record ระบุ tenant_id เอง
        return $this->ctx->withoutTenancy(fn () => DB::transaction(function () use ($data) {
            $plan = Plan::query()->where('code', $data['plan_code'])->where('is_active', true)->first()
                ?? throw ApiException::unprocessable('plan_not_found', 'ไม่พบแพ็กเกจ');

            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'timezone' => $data['timezone'] ?? 'Asia/Bangkok',
                'status' => 'active',
            ]);

            $owner = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($data['owner']['email'])])->first();
            if (! $owner) {
                if (empty($data['owner']['password'])) {
                    throw ApiException::unprocessable('owner_password_required', 'ผู้ใช้ใหม่ต้องกำหนดรหัสผ่าน');
                }
                $owner = User::create([
                    'name' => $data['owner']['name'],
                    'email' => $data['owner']['email'],
                    'password' => $data['owner']['password'],
                    'status' => 'active',
                ]);
            }

            $membership = TenantMembership::create([
                'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'status' => 'active', 'joined_at' => now(),
            ]);
            $this->rbac->assign($membership, $this->rbac->findAssignableRole($tenant->id, 'owner'));

            $this->createStore($tenant, $data['store'], [$owner->id]);

            Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => $plan->price_satang === 0 ? 'active' : 'trialing',
                'starts_at' => now(),
                'trial_ends_at' => $plan->price_satang === 0 ? null : now()->addDays(14),
            ]);

            $this->audit->log('tenant.provisioned', $tenant, ['plan' => $plan->code, 'owner_id' => $owner->id], tenantId: $tenant->id);

            return $tenant;
        }));
    }

    /** สร้างสาขา และให้ user ที่ระบุเป็นสมาชิกของสาขา */
    public function createStore(Tenant $tenant, array $data, array $memberUserIds): Store
    {
        $store = Store::create([
            'tenant_id' => $tenant->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'timezone' => $data['timezone'] ?? $tenant->timezone,
            'status' => 'active',
            'settings' => $data['settings'] ?? (object) [],
        ]);
        foreach (array_unique($memberUserIds) as $uid) {
            StoreMembership::create([
                'tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $uid, 'status' => 'active',
            ]);
        }

        return $store;
    }
}

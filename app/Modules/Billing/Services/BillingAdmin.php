<?php

namespace App\Modules\Billing\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * งานของ platform admin ด้าน billing — ใช้ร่วมกันระหว่าง API (AdminController) และ Filament panel
 * เรียกได้เฉพาะในโหมดข้าม tenant (withoutTenancy / platform scope) จึงกรอง tenant_id เองทุก query
 */
class BillingAdmin
{
    public function __construct(private AuditLogger $audit, private SubscriptionGate $gate) {}

    /**
     * @param  array{code:string,name:string,price_satang:int,billing_interval:string,limits?:array<string,int>|null,is_active?:bool}  $data
     */
    public function createPlan(array $data): Plan
    {
        $plan = Plan::create(['limits' => [], 'is_active' => true, ...array_filter($data, fn ($v) => $v !== null)]);
        $this->audit->log('admin.plan_created', $plan, $data);

        return $plan;
    }

    /**
     * เปลี่ยนแพ็กเกจ/สถานะ (ยกเลิกอันเดิมแล้วสร้างใหม่)
     *
     * @param  array{tenant_id:string,plan_code:string,status:string,ends_at?:mixed,trial_ends_at?:mixed}  $data
     */
    public function changeSubscription(array $data): Subscription
    {
        $sub = DB::transaction(function () use ($data) {
            Subscription::query()->withoutGlobalScopes()->where('tenant_id', $data['tenant_id'])
                ->where('status', '<>', 'cancelled')->lockForUpdate()->update(['status' => 'cancelled', 'ends_at' => now(), 'updated_at' => now()]);

            return Subscription::create([
                'tenant_id' => $data['tenant_id'],
                'plan_id' => Plan::query()->where('code', $data['plan_code'])->value('id'),
                'status' => $data['status'],
                'starts_at' => now(),
                'ends_at' => $data['ends_at'] ?? null,
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
            ]);
        });
        $this->audit->log('admin.subscription_changed', $sub, $data, tenantId: $data['tenant_id']);

        return $sub;
    }

    /**
     * @return array{tenant_id:string,subscription:?Subscription,stores:int,members:int,orders_30d:int,limits:array{max_stores:?int,max_users:?int}}
     */
    public function usage(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id,
            'subscription' => $this->gate->current($tenant),
            'stores' => DB::table('stores')->where('tenant_id', $tenant->id)->count(),
            'members' => DB::table('tenant_memberships')->where('tenant_id', $tenant->id)->where('status', 'active')->count(),
            'orders_30d' => DB::table('orders')->where('tenant_id', $tenant->id)->where('created_at', '>=', now()->subDays(30))->count(),
            'limits' => [
                'max_stores' => $this->gate->limit($tenant, 'max_stores'),
                'max_users' => $this->gate->limit($tenant, 'max_users'),
            ],
        ];
    }
}

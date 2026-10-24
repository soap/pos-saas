<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionGate;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SaaS Admin (platform admin เท่านั้น) — spec §7.2
 */
class AdminController extends Controller
{
    public function __construct(private AuditLogger $audit, private SubscriptionGate $gate) {}

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::query()->orderBy('price_satang')->get()]);
    }

    public function createPlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', 'unique:plans,code'],
            'name' => ['required', 'string', 'max:255'],
            'price_satang' => ['required', 'integer', 'min:0'],
            'billing_interval' => ['required', 'in:month,year'],
            'limits' => ['nullable', 'array'],
            'limits.*' => ['integer', 'min:0'],
        ]);
        $plan = Plan::create($data + ['limits' => $data['limits'] ?? [], 'is_active' => true]);
        $this->audit->log('admin.plan_created', $plan, $data);

        return response()->json(['data' => $plan], 201);
    }

    public function tenants(): JsonResponse
    {
        return response()->json(['data' => Tenant::query()->orderBy('created_at', 'desc')->paginate(50)]);
    }

    /** POST /admin/tenants — สร้าง tenant + owner + สาขาแรก + subscription */
    public function createTenant(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => Tenant::slugRules(),
            'timezone' => ['nullable', 'timezone:all'],
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255'],
            'owner.password' => ['nullable', 'string', 'min:8', 'max:255'],
            'store.code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'store.name' => ['required', 'string', 'max:255'],
            'store.timezone' => ['nullable', 'timezone:all'],
        ]);
        $tenant = $provisioner->provision($data);

        return response()->json(['data' => $tenant->load('stores')], 201);
    }

    /** POST /admin/subscriptions — เปลี่ยนแพ็กเกจ/สถานะ (ยกเลิกอันเดิมแล้วสร้างใหม่) */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            'status' => ['required', 'in:trialing,active,past_due'],
            'ends_at' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
        ]);
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

        return response()->json(['data' => $sub->load('plan')], 201);
    }

    public function usage(string $tenantId): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $since = now()->subDays(30);

        return response()->json(['data' => [
            'tenant_id' => $tenant->id,
            'subscription' => $this->gate->current($tenant),
            'stores' => DB::table('stores')->where('tenant_id', $tenant->id)->count(),
            'members' => DB::table('tenant_memberships')->where('tenant_id', $tenant->id)->where('status', 'active')->count(),
            'orders_30d' => DB::table('orders')->where('tenant_id', $tenant->id)->where('created_at', '>=', $since)->count(),
            'limits' => [
                'max_stores' => $this->gate->limit($tenant, 'max_stores'),
                'max_users' => $this->gate->limit($tenant, 'max_users'),
            ],
        ]]);
    }
}

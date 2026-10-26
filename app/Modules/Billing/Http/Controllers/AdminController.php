<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\BillingAdmin;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SaaS Admin (platform admin เท่านั้น) — spec §7.2
 */
class AdminController extends Controller
{
    public function __construct(private BillingAdmin $billing) {}

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

        return response()->json(['data' => $this->billing->createPlan($data)], 201);
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
        $sub = $this->billing->changeSubscription($data);

        return response()->json(['data' => $sub->load('plan')], 201);
    }

    public function usage(string $tenantId): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return response()->json(['data' => $this->billing->usage($tenant)]);
    }
}

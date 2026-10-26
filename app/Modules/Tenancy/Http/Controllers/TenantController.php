<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Services\SubscriptionGate;
use App\Modules\Identity\Models\MembershipRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RbacService;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreMembership;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\Services\TenantProvisioner;
use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(
        private TenantContext $ctx,
        private TenantProvisioner $provisioner,
        private SubscriptionGate $gate,
        private RbacService $rbac,
        private AuditLogger $audit,
    ) {}

    /** GET /tenants — tenant ที่ผู้ใช้เป็นสมาชิก */
    public function index(Request $request): JsonResponse
    {
        $rows = TenantMembership::query()->withoutGlobalScope('tenant')->where('user_id', $request->user()->id)->where('status', 'active')
            ->with('tenant')->get()
            ->map(fn ($m) => ['id' => $m->tenant->id, 'name' => $m->tenant->name, 'slug' => $m->tenant->slug, 'status' => $m->tenant->status]);

        return response()->json(['data' => $rows]);
    }

    /** GET /context — permission ของผู้ใช้ใน tenant/store ปัจจุบัน (ใช้ซ่อน/แสดงปุ่มใน frontend) */
    public function context(): JsonResponse
    {
        return response()->json([
            'tenant' => ['id' => $this->ctx->tenant()->id, 'name' => $this->ctx->tenant()->name],
            'store' => $this->ctx->hasStore() ? ['id' => $this->ctx->store()->id, 'name' => $this->ctx->store()->name, 'timezone' => $this->ctx->store()->timezone] : null,
            'permissions' => $this->ctx->permissions(),
            'server_time' => now()->toAtomString(),
        ]);
    }

    /** GET /stores — สาขาที่ผู้ใช้มีสิทธิ์ใน tenant ปัจจุบัน */
    public function stores(): JsonResponse
    {
        $ids = StoreMembership::query()->where('user_id', $this->ctx->user()->id)->where('status', 'active')->pluck('store_id');
        $stores = Store::query()->whereIn('id', $ids)->orderBy('code')->get(['id', 'code', 'name', 'timezone', 'status', 'settings', 'qr_payment_account_id']);

        return response()->json(['data' => $stores]);
    }

    /** POST /stores */
    public function createStore(Request $request): JsonResponse
    {
        $tenant = $this->ctx->tenant();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('stores', 'code')->where('tenant_id', $tenant->id)],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone:all'],
            'settings' => ['nullable', 'array'],
            'settings.kitchen_enabled' => ['nullable', 'boolean'],
            'settings.tax_mode' => ['nullable', Rule::in(Store::TAX_MODES)],
        ]);

        $store = DB::transaction(function () use ($tenant, $data) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first(); // กัน race เกิน limit
            $this->gate->ensureWithinLimit($tenant, 'max_stores', Store::query()->count());

            return $this->provisioner->createStore($tenant, $data, [$this->ctx->user()->id]);
        });
        $this->audit->log('store.created', $store, ['code' => $store->code]);

        return response()->json(['data' => $store], 201);
    }

    /** GET /members */
    public function members(): JsonResponse
    {
        $rows = TenantMembership::query()->with(['user:id,name,email,status', 'roles.role:id,slug,name'])->get()
            ->map(fn ($m) => [
                'membership_id' => $m->id,
                'user' => $m->user,
                'status' => $m->status,
                'roles' => $m->roles->map(fn ($r) => ['role' => $r->role->slug, 'store_id' => $r->store_id])->values(),
                'store_ids' => StoreMembership::query()->where('user_id', $m->user_id)->where('status', 'active')->pluck('store_id'),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** POST /members — เพิ่มพนักงานเข้า tenant พร้อม role และสาขา */
    public function addMember(Request $request): JsonResponse
    {
        $tenant = $this->ctx->tenant();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', 'string', 'max:64'],
            'role_store_id' => ['nullable', 'uuid'],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['uuid'],
        ]);

        $storeIds = array_unique($data['store_ids']);
        if (Store::query()->whereIn('id', $storeIds)->count() !== count($storeIds)
            || (! empty($data['role_store_id']) && ! in_array($data['role_store_id'], $storeIds, true))) {
            throw ApiException::unprocessable('store_invalid', 'สาขาไม่ถูกต้อง');
        }
        $role = $this->rbac->findAssignableRole($tenant->id, $data['role']);

        $membership = DB::transaction(function () use ($tenant, $data, $storeIds, $role) {
            $this->gate->ensureWithinLimit($tenant, 'max_users', TenantMembership::query()->count());
            $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->first();
            if (! $user) {
                if (empty($data['password'])) {
                    throw ApiException::unprocessable('password_required', 'ผู้ใช้ใหม่ต้องกำหนดรหัสผ่าน');
                }
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'status' => 'active']);
            }
            $membership = TenantMembership::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['status' => 'active', 'joined_at' => now()],
            );
            foreach ($storeIds as $sid) {
                StoreMembership::query()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'store_id' => $sid, 'user_id' => $user->id],
                    ['status' => 'active'],
                );
            }
            $this->rbac->assign($membership, $role, $data['role_store_id'] ?? null);

            return $membership;
        });

        $this->audit->log('member.role_assigned', $membership, [
            'user_id' => $membership->user_id, 'role' => $role->slug, 'store_id' => $data['role_store_id'] ?? null, 'store_ids' => $storeIds,
        ]);

        return response()->json(['data' => ['membership_id' => $membership->id, 'user_id' => $membership->user_id]], 201);
    }

    /** DELETE /members/{membership}/roles/{role} */
    public function removeRole(string $membershipId, string $roleSlug): JsonResponse
    {
        $membership = TenantMembership::query()->findOrFail($membershipId);
        $deleted = MembershipRole::query()->where('membership_id', $membership->id)
            ->whereIn('role_id', fn ($q) => $q->select('id')->from('roles')->where('slug', $roleSlug))
            ->delete();
        $this->audit->log('member.role_removed', $membership, ['role' => $roleSlug, 'count' => $deleted]);

        return response()->json(['removed' => $deleted]);
    }
}

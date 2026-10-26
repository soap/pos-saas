<?php

namespace App\Support\Tenancy;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Errors\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Tenant/Store context ของ request ปัจจุบัน
 *
 * ค่าในนี้ถูกตั้งโดย middleware หลังจากตรวจ membership แล้วเท่านั้น
 * (ห้ามเชื่อ tenant_id/store_id จาก body ของ request — spec §2.1, §8)
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Store $store = null;

    private ?TenantMembership $membership = null;

    private ?User $user = null;

    /** @var array<string,bool>|null */
    private ?array $permissions = null;

    /** > 0 = อยู่ใน withoutTenancy() — global scope ไม่กรองและไม่ throw */
    private int $unscopedDepth = 0;

    /** true = ทั้ง request เป็นงานของ platform admin (ล้างโดย reset() ตอนเริ่ม request ถัดไป) */
    private bool $platformScope = false;

    public function reset(): void
    {
        $this->tenant = null;
        $this->store = null;
        $this->membership = null;
        $this->user = null;
        $this->permissions = null;
        $this->unscopedDepth = 0;
        $this->platformScope = false;
    }

    /**
     * เปิดโหมดข้าม tenant ไปจนจบ request — สำหรับ platform admin panel (Filament)
     * ใช้แทน withoutTenancy() เมื่อครอบ callback ไม่ได้ เช่น Livewire persistent middleware
     * ที่รัน middleware เสร็จก่อน component จะทำงาน
     */
    public function enterPlatformScope(): void
    {
        $this->platformScope = true;
    }

    /**
     * รันโค้ดที่ตั้งใจทำงานข้าม tenant (platform admin, provisioning, seeder, worker)
     * ภายใน callback ทุก query ต้องกรอง tenant_id เองให้ถูกต้อง
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutTenancy(callable $callback): mixed
    {
        $this->unscopedDepth++;
        try {
            return $callback();
        } finally {
            $this->unscopedDepth--;
        }
    }

    public function isUnscoped(): bool
    {
        return $this->platformScope || $this->unscopedDepth > 0;
    }

    /**
     * รันในนาม tenant/store แบบระบบ (webhook, queue job, command) — ไม่มี user และไม่มี permission
     * คืน context เดิมเมื่อจบ
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, ?Store $store, callable $callback): mixed
    {
        if ($store !== null && $store->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('store ไม่ได้อยู่ใน tenant นี้');
        }
        $previous = [$this->tenant, $this->store, $this->membership, $this->user, $this->permissions, $this->unscopedDepth];
        $this->tenant = $tenant;
        $this->store = $store;
        $this->membership = null;
        $this->user = null;
        $this->permissions = null;
        $this->unscopedDepth = 0;
        try {
            return $callback();
        } finally {
            [$this->tenant, $this->store, $this->membership, $this->user, $this->permissions, $this->unscopedDepth] = $previous;
        }
    }

    public function setTenant(Tenant $tenant, TenantMembership $membership, User $user): void
    {
        $this->tenant = $tenant;
        $this->membership = $membership;
        $this->user = $user;
        $this->store = null;
        $this->permissions = null;
    }

    public function setStore(Store $store): void
    {
        $this->store = $store;
        $this->permissions = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function hasStore(): bool
    {
        return $this->store !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw ApiException::badRequest('tenant_context_missing', 'ต้องระบุ tenant');
    }

    public function store(): Store
    {
        return $this->store ?? throw ApiException::badRequest('store_context_missing', 'ต้องระบุ X-Store-Id');
    }

    public function tenantId(): ?string
    {
        return $this->tenant?->id;
    }

    public function storeId(): ?string
    {
        return $this->store?->id;
    }

    public function membership(): TenantMembership
    {
        return $this->membership ?? throw ApiException::badRequest('tenant_context_missing', 'ต้องระบุ tenant');
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /** permission keys ที่ผู้ใช้มีใน tenant (และ store ปัจจุบัน ถ้ามี) */
    public function permissions(): array
    {
        if ($this->permissions === null) {
            if ($this->membership === null) {
                return [];
            }
            $query = DB::table('membership_roles as mr')
                ->join('role_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('mr.membership_id', $this->membership->id)
                ->where('mr.tenant_id', $this->membership->tenant_id)
                ->where(function ($q) {
                    $q->whereNull('mr.store_id');
                    if ($this->store) {
                        $q->orWhere('mr.store_id', $this->store->id);
                    }
                })
                ->distinct()
                ->pluck('p.key');
            $this->permissions = array_fill_keys($query->all(), true);
        }

        return array_keys($this->permissions);
    }

    public function can(string $permission): bool
    {
        $this->permissions();

        return isset($this->permissions[$permission]);
    }

    public function authorize(string $permission): void
    {
        if (! $this->can($permission)) {
            throw ApiException::forbidden('permission_denied', "ไม่มีสิทธิ์: {$permission}");
        }
    }
}

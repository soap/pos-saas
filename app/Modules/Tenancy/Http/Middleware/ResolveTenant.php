<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Billing\Services\SubscriptionGate;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * หา tenant ที่ request เลือก (path / subdomain / X-Tenant-Id — ดู TenantResolver)
 * แล้วตรวจว่า user ที่ login เป็นสมาชิก active ของ tenant นั้นจริง
 * การระบุ tenant เป็นเพียง "การเลือก" — สิทธิ์มาจาก membership เท่านั้น (spec §8)
 */
class ResolveTenant
{
    public function __construct(private TenantContext $ctx, private SubscriptionGate $gate, private TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolver->resolve($request);
        // {tenant} ใช้แค่เลือก tenant — ไม่ส่งต่อเป็น argument ของ controller
        $request->route()?->forgetParameter('tenant');

        $user = $request->user();
        // ยังไม่มี context ในจังหวะนี้ → ถอด scope และกรอง tenant_id เอง
        $membership = $tenantId === null ? null : TenantMembership::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        // ไม่บอกว่า tenant มีอยู่จริงหรือไม่ เพื่อไม่ให้ใช้เดา tenant id ได้
        if (! $membership) {
            throw ApiException::forbidden('tenant_access_denied', 'ไม่มีสิทธิ์เข้าถึง tenant นี้');
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        if ($tenant->status !== 'active') {
            throw ApiException::forbidden('tenant_inactive', 'tenant ถูกระงับหรือปิดใช้งาน');
        }

        $this->gate->ensureActive($tenant);
        $this->ctx->setTenant($tenant, $membership, $user);

        return $next($request);
    }
}

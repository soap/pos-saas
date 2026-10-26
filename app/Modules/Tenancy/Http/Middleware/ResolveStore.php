<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreMembership;
use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * อ่าน X-Store-Id แล้วตรวจว่า store อยู่ใน tenant ปัจจุบัน และ user มี store membership ที่ active
 * ต้องใช้หลัง ResolveTenant เสมอ
 *
 * ->middleware('store:optional') — ไม่ส่ง header ก็ผ่าน (context ระดับ tenant) แต่ถ้าส่งมาต้องตรวจเหมือนเดิม
 */
class ResolveStore
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $storeId = (string) $request->header('X-Store-Id', '');
        if ($storeId === '' && $mode === 'optional') {
            return $next($request);
        }
        if (! Str::isUuid($storeId)) {
            throw ApiException::badRequest('store_context_missing', 'ต้องระบุ X-Store-Id เป็น UUID');
        }

        $tenant = $this->ctx->tenant();
        $hasAccess = StoreMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('store_id', $storeId)
            ->where('user_id', $this->ctx->user()->id)
            ->where('status', 'active')
            ->exists();

        $store = $hasAccess ? Store::query()->where('tenant_id', $tenant->id)->find($storeId) : null;
        if (! $store) {
            throw ApiException::forbidden('store_access_denied', 'ไม่มีสิทธิ์เข้าถึงสาขานี้');
        }
        if ($store->status !== 'active') {
            throw ApiException::forbidden('store_inactive', 'สาขานี้ปิดใช้งาน');
        }

        $this->ctx->setStore($store);

        return $next($request);
    }
}

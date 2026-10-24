<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Support\Errors\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * route ของ platform admin ทำงานข้าม tenant โดยตั้งใจ → รันใน withoutTenancy()
 * (controller ต้องกรอง tenant_id เองทุก query)
 */
class RequirePlatformAdmin
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_admin) {
            throw ApiException::forbidden('platform_admin_required', 'ต้องเป็นผู้ดูแลแพลตฟอร์ม');
        }

        return $this->ctx->withoutTenancy(fn () => $next($request));
    }
}

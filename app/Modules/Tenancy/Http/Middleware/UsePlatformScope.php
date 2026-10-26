<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * platform admin panel (Filament) ทำงานข้าม tenant โดยตั้งใจ → เปิด platform scope ทั้ง request
 * ต้องเป็น persistent middleware เพื่อให้ครอบ Livewire update request ด้วย
 * (ทุก query ใน panel ต้องกรอง tenant_id เอง)
 */
class UsePlatformScope
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_admin, 403);

        $this->ctx->enterPlatformScope();

        return $next($request);
    }
}

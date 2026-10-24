<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ใช้: ->middleware('perm:orders.create')  (คั่นหลาย permission ด้วย | = มีอย่างใดอย่างหนึ่ง)
 */
class RequirePermission
{
    public function __construct(private TenantContext $ctx) {}

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $any = explode('|', $permissions);
        foreach ($any as $perm) {
            if ($this->ctx->can($perm)) {
                return $next($request);
            }
        }
        $this->ctx->authorize($any[0]); // throws 403

        return $next($request);
    }
}

<?php

namespace App\Providers;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Services\PaymentProviderRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\QueueTenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped = 1 instance ต่อ request/job (reset อัตโนมัติใน Octane/queue)
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(QueueTenancy::class);
        $this->app->singleton(PaymentProviderRegistry::class);

        // passkey ใช้เฉพาะ platform admin panel — route ถูกลงทะเบียนโดย Filament plugin ภายใต้ /admin
        // route กลางของ laravel/passkeys (/passkeys/login ฯลฯ) จึงปิดไว้ ไม่ให้มีทาง login นอก panel
        Passkeys::ignoreRoutes();
        Passkeys::useUserModel(User::class);
    }

    public function boot(): void
    {
        $this->app->make(QueueTenancy::class)->register($this->app['events']);

        // route parameter ทุกตัวต้องเป็น UUID → id ผิดรูปแบบได้ 404 แทน SQL error
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
        Route::pattern('id', $uuid);
        Route::pattern('item', $uuid);
        Route::pattern('tenant', Tenant::SLUG_PATTERN); // /t/{slug}

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by('login:'.mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by('login-ip:'.$r->ip()),
        ]);
        RateLimiter::for('payments', fn (Request $r) => Limit::perMinute(60)->by('pay:'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by('wh:'.$r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
    }
}

<?php

namespace App\Providers\Filament;

use AdriaanZon\FilamentPasskeys\FilamentPasskeysPlugin;
use AdriaanZon\FilamentPasskeys\PasskeyAuthentication;
use App\Modules\Tenancy\Http\Middleware\UsePlatformScope;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Platform admin panel (/admin) — ทั้ง panel ทำงานข้าม tenant ผ่าน UsePlatformScope
 * และบังคับ MFA (passkey หรือ authenticator app) เพราะเห็นข้อมูลทุก tenant
 * login ด้วย passkey แบบ passwordless ได้ (passkey บังคับ user verification จึงนับเป็น MFA ในตัว)
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->multiFactorAuthentication([
                PasskeyAuthentication::make(),
                AppAuthentication::make()
                    ->recoverable()
                    ->brandName('POS SaaS Admin'),
            ], isRequired: true)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->plugins([
                FilamentPasskeysPlugin::make()->passwordlessLogin(),
            ])
            ->brandName('POS SaaS Admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                UsePlatformScope::class,
            ], isPersistent: true);
    }
}

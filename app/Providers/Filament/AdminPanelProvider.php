<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FeedbackCenter;
use App\Http\Middleware\StaffLocale;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->defaultThemeMode(ThemeMode::Light)
            ->darkMode(false)
            ->sidebarWidth('18rem')
            ->maxContentWidth(Width::Full)
            ->homeUrl(fn () => FeedbackCenter::getUrl())
            ->userMenu(false)
            ->navigationGroups([
                NavigationGroup::make()->label(fn () => __('feedback.nav_overview'))->collapsible(false),
                NavigationGroup::make()->label(fn () => __('feedback.nav_customer_service'))->collapsible(false),
                NavigationGroup::make()->label(fn () => __('feedback.nav_administration'))->collapsible(false),
            ])
            ->brandName('Easy Gold · Feedback')
            ->brandLogo(fn () => view('filament.partials.brand'))
            ->brandLogoHeight('8rem')
            ->favicon(asset('images/easy-gold-mark.svg'))
            ->renderHook('panels::head.end', fn () => view('filament.partials.theme'))
            ->renderHook('panels::topbar.end', fn () => view('filament.partials.account-bar'))
            ->colors([
                'primary' => Color::hex('#ad3535'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->font('Noto Sans Lao')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                StaffLocale::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

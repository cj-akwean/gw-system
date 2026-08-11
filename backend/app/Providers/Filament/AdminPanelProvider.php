<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Livewire\AdminDatabaseNotifications;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\View\PanelsRenderHook;
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
            ->login(Login::class)
            ->authGuard('admin')
            ->spa()
            // FileUpload pages race Alpine's entangle under SPA swaps (Livewire
            // Entangle Error on data.csvFile) — keep them as plain reloads.
            ->spaUrlExceptions([
                '*/admin/meter-readings/import*',
                '*/admin/service-connections/import*',
            ])
            ->brandName('Guinobatan Waterworks')
            ->favicon('/favicon.svg')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => <<<'HTML'
                <style>
                    .fi-body { background-color: #dde8f4; }
                    .fi-body .fi-topbar { background-color: #f0f6fb; }
                    @media (max-width: 63.999rem) {
                        .fi-body .fi-sidebar { background-color: #f0f6fb; }
                    }
                    .fi-body:where(.dark, .dark *) { background-color: var(--gray-950); }
                    .fi-body:where(.dark, .dark *) .fi-topbar { background-color: var(--gray-900); }
                    @media (max-width: 63.999rem) {
                        .fi-body:where(.dark, .dark *) .fi-sidebar { background-color: var(--gray-900); }
                    }
                </style>
                HTML)
            ->databaseNotifications(livewireComponent: AdminDatabaseNotifications::class)
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

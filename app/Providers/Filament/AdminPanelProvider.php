<?php

namespace App\Providers\Filament;

use App\Enums\DnsTool;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Tool;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
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
            ->brandName('Network-Tools')
            // Angemeldet: Release, Commit und Commit-Datum klein unter dem Titel
            ->brandLogo(fn (): ?HtmlString => auth()->check() ? new HtmlString(view('filament.brand')->render()) : null)
            ->brandLogoHeight('2.5rem')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                NavigationGroup::make('Überwachung'),
                NavigationGroup::make(DnsTool::GROUP_TOOLS),
                NavigationGroup::make(DnsTool::GROUP_MAIL),
                NavigationGroup::make(DnsTool::GROUP_REVERSE),
                NavigationGroup::make(DnsTool::GROUP_DEBUG),
                NavigationGroup::make('Einstellungen')->collapsed(false),
            ])
            ->databaseNotifications(false)
            // Menüeinträge der Werkzeuge kommen aus der DnsTool-Registry (eine Seite für alle)
            ->navigationItems(array_map(fn (DnsTool $tool) => NavigationItem::make($tool->label())
                ->group($tool->group())
                ->icon($tool->icon())
                ->sort($tool->sort())
                ->url(fn (): string => Tool::getUrl(['tool' => $tool->value]))
                ->isActiveWhen(fn (): bool => request()->routeIs(Tool::getRouteName()) && request()->route('tool') === $tool->value), DnsTool::cases()))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            // Passkey-Client (Vite-Bundle) auf allen Panel-Seiten inkl. Login laden
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render("@vite('resources/js/passkeys.js')"))
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

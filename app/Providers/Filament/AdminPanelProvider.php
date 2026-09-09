<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Widgets\PrimeirosPassos;
use App\Filament\Widgets\ResumoDoPainel;
use App\Filament\Widgets\UltimasNotas;
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
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
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
            ->globalSearch(false)
            ->brandName(__('NFS-e Demo'))
            ->colors(['primary' => Color::Emerald, 'danger' => Color::Rose])
            ->maxContentWidth(Width::SevenExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            ->navigationGroups($this->grupos())
            ->navigationItems($this->itens())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([PrimeirosPassos::class, ResumoDoPainel::class, UltimasNotas::class])
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
            ->authMiddleware([Authenticate::class]);
    }

    /**
     * @return array<int, NavigationGroup>
     */
    private function grupos(): array
    {
        return [
            NavigationGroup::make(__('Emissão'))->icon(Heroicon::OutlinedDocumentCheck),
            NavigationGroup::make(__('Cadastros'))->icon(Heroicon::OutlinedRectangleStack)->collapsed(),
            NavigationGroup::make(__('API fiscal'))->icon(Heroicon::OutlinedSignal)->collapsed(),
        ];
    }

    /**
     * @return array<int, NavigationItem>
     */
    private function itens(): array
    {
        return [
            NavigationItem::make(__('Documentação (Swagger)'))
                ->url(fn (): string => route('fiscal.docs'), shouldOpenInNewTab: true)
                ->icon(Heroicon::OutlinedBookOpen)
                ->group(__('API fiscal'))
                ->sort(20),
        ];
    }
}

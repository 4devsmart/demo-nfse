<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Consultas\GuiaDeEmissao;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * O guia do fluxo de emissao dentro do painel: os dois passos da API, os
 * estados da nota e os codigos de erro. Antes isso estava so em tooltip, que
 * aparece depois de o usuario ja ter escolhido o caminho.
 */
class FluxoDeEmissao extends Page
{
    protected string $view = 'filament.pages.fluxo-de-emissao';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Emissão');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fluxo da emissão');
    }

    public function getTitle(): string
    {
        return __('Como funciona a emissão');
    }

    public function getSubheading(): string
    {
        return __('A API separa a montagem do XML da transmissão. Aqui estão os dois passos, os estados da nota e os códigos de erro.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $guia = app(GuiaDeEmissao::class);

        return [
            'passos' => $guia->passos(),
            'estados' => $guia->estados(),
            'falhas' => $guia->falhas(),
        ];
    }
}

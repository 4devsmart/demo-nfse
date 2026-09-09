<?php

declare(strict_types=1);

namespace App\Filament\Resources\CargasTributarias;

use App\Filament\Resources\CargasTributarias\Pages\ListCargasTributarias;
use App\Filament\Resources\CargasTributarias\Tables\CargasTributariasTable;
use App\Models\CargaTributariaAproximada;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A TabelaIBPTax dos itens da LC 116. So de leitura: quem a preenche e a
 * importacao do arquivo do IBPT.
 */
class CargaTributariaAproximadaResource extends Resource
{
    protected static ?string $model = CargaTributariaAproximada::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'codigo';

    /** Fixo: o automático junta pasta e classe e sai ilegível. */
    protected static ?string $slug = 'cargas-tributarias';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Cadastros');
    }

    public static function getModelLabel(): string
    {
        return __('carga tributária aproximada');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cargas tributárias aproximadas');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'descricao'];
    }

    public static function table(Table $table): Table
    {
        return CargasTributariasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCargasTributarias::route('/'),
        ];
    }
}

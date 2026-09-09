<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndicadoresDeOperacao;

use App\Filament\Resources\IndicadoresDeOperacao\Pages\ListIndicadoresDeOperacao;
use App\Filament\Resources\IndicadoresDeOperacao\Tables\IndicadoresDeOperacaoTable;
use App\Models\IndicadorDeOperacao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * O Anexo VII da NT 007. So de leitura, como a tabela de classificacao: e o
 * art. 11 da LC 214/2025 que a define, nao este sistema.
 */
class IndicadorDeOperacaoResource extends Resource
{
    protected static ?string $model = IndicadorDeOperacao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 50;

    /** Fixo: o plural automatico saia `indicador-de-operacaos`. */
    protected static ?string $slug = 'indicadores-de-operacao';

    protected static ?string $recordTitleAttribute = 'codigo';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Cadastros');
    }

    public static function getModelLabel(): string
    {
        return __('indicador da operação');
    }

    public static function getPluralModelLabel(): string
    {
        return __('indicadores da operação');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'tipo_operacao'];
    }

    public static function table(Table $table): Table
    {
        return IndicadoresDeOperacaoTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIndicadoresDeOperacao::route('/'),
        ];
    }
}

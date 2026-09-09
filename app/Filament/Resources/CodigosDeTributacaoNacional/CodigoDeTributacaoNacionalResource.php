<?php

declare(strict_types=1);

namespace App\Filament\Resources\CodigosDeTributacaoNacional;

use App\Filament\Resources\CodigosDeTributacaoNacional\Pages\ListCodigosDeTributacaoNacional;
use App\Filament\Resources\CodigosDeTributacaoNacional\Tables\CodigosDeTributacaoNacionalTable;
use App\Models\CodigoDeTributacaoNacional;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A lista de servicos anexa a LC 116/2003 como o Padrao Nacional a desdobrou.
 * So de leitura, como as outras tabelas oficiais: e a lei e o Comite Gestor que
 * as definem, nao este sistema.
 */
class CodigoDeTributacaoNacionalResource extends Resource
{
    protected static ?string $model = CodigoDeTributacaoNacional::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 60;

    /** Fixo: o plural automatico saia `codigo-de-tributacao-nacionals`. */
    protected static ?string $slug = 'codigos-de-tributacao-nacional';

    protected static ?string $recordTitleAttribute = 'codigo';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Cadastros');
    }

    public static function getModelLabel(): string
    {
        return __('código de tributação nacional');
    }

    public static function getPluralModelLabel(): string
    {
        return __('códigos de tributação nacional');
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
        return CodigosDeTributacaoNacionalTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCodigosDeTributacaoNacional::route('/'),
        ];
    }
}

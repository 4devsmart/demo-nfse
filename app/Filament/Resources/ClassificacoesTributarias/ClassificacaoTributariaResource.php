<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClassificacoesTributarias;

use App\Filament\Resources\ClassificacoesTributarias\Pages\ListClassificacoesTributarias;
use App\Filament\Resources\ClassificacoesTributarias\Tables\ClassificacoesTributariasTable;
use App\Models\ClassificacaoTributaria;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A tabela oficial de classificacao tributaria do IBS e da CBS. So de leitura:
 * quem a preenche e a importacao, e editar um codigo a mao faria a nota
 * divergir do que o fisco reconhece.
 */
class ClassificacaoTributariaResource extends Resource
{
    protected static ?string $model = ClassificacaoTributaria::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 40;

    /**
     * Fixo porque o automatico sai feio: o Filament junta o nome da pasta com o
     * da classe e produz `classificacoes-tributarias/classificacao-tributarias`.
     */
    protected static ?string $slug = 'classificacoes-tributarias';

    protected static ?string $recordTitleAttribute = 'codigo';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Cadastros');
    }

    public static function getModelLabel(): string
    {
        return __('classificação tributária');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classificações tributárias');
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
        return ClassificacoesTributariasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClassificacoesTributarias::route('/'),
        ];
    }
}

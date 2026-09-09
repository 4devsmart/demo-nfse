<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cidades;

use App\Filament\Resources\Cidades\Pages\ListCidades;
use App\Filament\Resources\Cidades\Schemas\CidadeForm;
use App\Filament\Resources\Cidades\Tables\CidadesTable;
use App\Models\Cidade;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CidadeResource extends Resource
{
    protected static ?string $model = Cidade::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Cadastros');
    }

    public static function getModelLabel(): string
    {
        return __('cidade');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cidades');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['nome', 'codigo_ibge'];
    }

    public static function form(Schema $schema): Schema
    {
        return CidadeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CidadesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCidades::route('/'),
        ];
    }
}

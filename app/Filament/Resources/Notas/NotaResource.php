<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas;

use App\Consultas\ResumoDeNotas;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Filament\Resources\Notas\Pages\ListNotas;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Filament\Resources\Notas\Schemas\NotaForm;
use App\Filament\Resources\Notas\Schemas\NotaInfolist;
use App\Filament\Resources\Notas\Tables\NotasTable;
use App\Models\Nota;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class NotaResource extends Resource
{
    protected static ?string $model = Nota::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'numero_nfse';

    /**
     * O grupo e casado por rotulo: o `NavigationGroup` do painel nao tem id
     * separado. Os dois lados passam pelo mesmo `__()` para nunca divergirem.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Emissão');
    }

    public static function getModelLabel(): string
    {
        return __('NFS-e');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notas de serviço');
    }

    public static function form(Schema $schema): Schema
    {
        return NotaForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NotaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotasTable::configure($table);
    }

    /**
     * O menu avisa quando ha nota rejeitada ou com desfecho indeterminado: sao
     * as unicas que dependem de alguem agir.
     */
    public static function getNavigationBadge(): ?string
    {
        $pendentes = app(ResumoDeNotas::class)->precisamDeAtencao();

        return $pendentes > 0 ? (string) $pendentes : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['numero_nfse', 'chave', 'id_dps', 'descricao_servico', 'cliente.razao_social'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        assert($record instanceof Nota);

        return $record->identificacao();
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        assert($record instanceof Nota);

        return [
            __('Tomador') => $record->cliente->razao_social,
            __('Situação') => $record->status->getLabel(),
        ];
    }

    public static function canEdit(Model $record): bool
    {
        assert($record instanceof Nota);

        return $record->status->permiteEditar();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotas::route('/'),
            'create' => CreateNota::route('/nova'),
            'view' => ViewNota::route('/{record}'),
            'edit' => EditNota::route('/{record}/editar'),
        ];
    }
}

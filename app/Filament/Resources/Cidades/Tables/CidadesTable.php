<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cidades\Tables;

use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CidadesTable
{
    private const UNIDADES_FEDERATIVAS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT',
        'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            // A tabela chega por AJAX, depois da pagina: o que o usuario espera
            // para ver a tela deixa de incluir a consulta.
            ->deferLoading()
            ->defaultSort('nome')
            ->striped()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->searchPlaceholder(__('Nome ou código IBGE'))
            ->emptyStateIcon(Heroicon::OutlinedMapPin)
            ->emptyStateHeading(__('Tabela de municípios vazia'))
            ->emptyStateDescription(__('Use "Atualizar pelo IBGE" para carregar os 5.571 municípios.'))
            ->columns([
                TextColumn::make('codigo_ibge')
                    ->label(__('Código IBGE'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage(__('Código copiado'))
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('nome')
                    ->label(__('Município'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('uf')
                    ->label(__('UF'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('uf')
                    ->label(__('UF'))
                    ->options(array_combine(self::UNIDADES_FEDERATIVAS, self::UNIDADES_FEDERATIVAS))
                    ->multiple(),
            ])
            ->recordActions([
                EditAction::make()->label(__('Editar'))->slideOver(),
            ]);
    }
}

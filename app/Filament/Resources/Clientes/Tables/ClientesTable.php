<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Tables;

use App\Domain\Enums\TipoPessoa;
use App\Models\Cliente;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A tabela chega por AJAX, depois da pagina: o que o usuario espera
            // para ver a tela deixa de incluir a consulta.
            ->deferLoading()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('cidade'))
            ->defaultSort('razao_social')
            ->striped()
            ->persistSearchInSession()
            ->emptyStateIcon(Heroicon::OutlinedUserPlus)
            ->emptyStateHeading(__('Nenhum tomador cadastrado'))
            ->emptyStateDescription(__('O tomador é quem recebe o serviço e aparece na nota.'))
            ->emptyStateActions([
                CreateAction::make()->label(__('Cadastrar tomador')),
            ])
            ->columns([
                TextColumn::make('razao_social')
                    ->label(__('Tomador'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Cliente $cliente): string => $cliente->documentoFederal()->formatado()),

                TextColumn::make('tipo_pessoa')
                    ->label(__('Tipo'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('cidade.nome')
                    ->label(__('Município'))
                    ->formatStateUsing(fn (Cliente $cliente): string => $cliente->cidade->nomeComUf())
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('E-mail'))
                    ->placeholder('—')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->toggleable(),

                TextColumn::make('notas_count')
                    ->label(__('Notas'))
                    ->counts('notas')
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tipo_pessoa')->label(__('Tipo'))->options(TipoPessoa::class),
            ])
            ->recordActions([
                EditAction::make()->label(__('Abrir')),
            ]);
    }
}

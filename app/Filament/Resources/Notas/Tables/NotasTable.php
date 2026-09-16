<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Tables;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\StatusNota;
use App\Filament\Resources\Notas\Acoes\AcoesDaNota;
use App\Models\Nota;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A tabela chega por AJAX, depois da pagina: o que o usuario espera
            // para ver a tela deixa de incluir a consulta.
            ->deferLoading()
            // As acoes leem empresa e cliente: carregar junto evita o N+1.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['empresa', 'cliente']))
            ->defaultSort('id', 'desc')
            ->striped()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->recordUrl(fn (Nota $nota): string => route('filament.admin.resources.notas.view', $nota))
            ->emptyStateIcon(Heroicon::OutlinedDocumentPlus)
            ->emptyStateHeading(__('Nenhuma nota por aqui'))
            ->emptyStateDescription(__('Uma nota nasce como rascunho: nada é transmitido até você mandar.'))
            ->emptyStateActions([
                CreateAction::make()->label(__('Criar a primeira NFS-e')),
            ])
            ->columns(self::colunas())
            ->filters(self::filtros())
            ->recordActions([
                ActionGroup::make(AcoesDaNota::todas())
                    ->label(__('Ações'))
                    ->icon(Heroicon::OutlinedEllipsisHorizontal)
                    ->button()
                    ->color('gray'),
            ]);
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function colunas(): array
    {
        return [
            TextColumn::make('status')
                ->label(__('Situação'))
                ->badge()
                ->sortable(),

            TextColumn::make('numero')
                ->label(__('Documento'))
                ->description(fn (Nota $nota): string => __('DPS :serie/:numero', ['serie' => $nota->serie, 'numero' => $nota->numero]))
                ->formatStateUsing(fn (Nota $nota): string => $nota->numero_nfse ?? '—')
                ->fontFamily(FontFamily::Mono)
                ->sortable(),

            TextColumn::make('cliente.razao_social')
                ->label(__('Tomador'))
                ->description(fn (Nota $nota): string => $nota->cliente->documentoFederal()->formatado())
                ->searchable()
                ->limit(30),

            TextColumn::make('competencia')
                ->label(__('Competência'))
                ->date('m/Y')
                ->sortable(),

            TextColumn::make('valor_servico')
                ->label(__('Valor'))
                ->money('BRL')
                ->sortable()
                ->alignEnd()
                ->summarize(Sum::make()->label(__('Total'))->money('BRL')),

            TextColumn::make('empresa.razao_social')
                ->label(__('Emitente'))
                ->limit(24)
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('ambiente')
                ->label(__('Ambiente'))
                ->badge()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('provedor')
                ->label(__('Provedor'))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('created_at')
                ->label(__('Criada em'))
                ->dateTime('d/m/Y H:i')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function filtros(): array
    {
        return [
            SelectFilter::make('status')->label(__('Situação'))->options(StatusNota::class)->multiple(),
            SelectFilter::make('ambiente')->label(__('Ambiente'))->options(Ambiente::class),
            SelectFilter::make('empresa_id')->label(__('Emitente'))->relationship('empresa', 'razao_social'),

            Filter::make('precisam_de_atencao')
                ->label(__('Só as que pedem ação'))
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                    StatusNota::Indeterminada,
                    StatusNota::EmProcessamento,
                    StatusNota::Rejeitada,
                ])),
        ];
    }
}

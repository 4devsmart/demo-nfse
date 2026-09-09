<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Consultas\NotasRecentes;
use App\Filament\Resources\Notas\NotaResource;
use App\Models\Nota;
use Filament\Actions\Action;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * O painel abre no que aconteceu por ultimo, com poucas colunas: quem quer o
 * detalhe clica.
 */
class UltimasNotas extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Últimas notas'))
            ->description(__('As :quantidade mais recentes, de qualquer emitente.', ['quantidade' => NotasRecentes::QUANTIDADE_NO_PAINEL]))
            ->query(fn (): Builder => app(NotasRecentes::class)->consulta())
            // O painel abre com os cartoes de resumo; a lista chega logo
            // depois, sem segurar a pintura da pagina.
            ->deferLoading()
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedDocumentPlus)
            ->emptyStateHeading(__('Nenhuma nota ainda'))
            ->emptyStateDescription(__('A primeira emissão aparece aqui.'))
            ->recordUrl(fn (Nota $nota): string => NotaResource::getUrl('view', ['record' => $nota]))
            ->columns([
                TextColumn::make('status')->label(__('Situação'))->badge(),

                TextColumn::make('numero')
                    ->label(__('Documento'))
                    ->formatStateUsing(fn (Nota $nota): string => $nota->numero_nfse ?? __('DPS :serie/:numero', ['serie' => $nota->serie, 'numero' => $nota->numero]))
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('cliente.razao_social')->label(__('Tomador'))->limit(28),

                TextColumn::make('competencia')->label(__('Competência'))->date('m/Y'),

                TextColumn::make('valor_servico')->label(__('Valor'))->money('BRL')->alignEnd(),
            ])
            ->headerActions([
                Action::make('todas')
                    ->label(__('Ver todas'))
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('gray')
                    ->link()
                    ->url(fn (): string => NotaResource::getUrl('index')),
            ]);
    }
}

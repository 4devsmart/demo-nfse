<?php

declare(strict_types=1);

namespace App\Filament\Resources\CodigosDeTributacaoNacional\Tables;

use App\Consultas\BuscaDeCodigosDeServico;
use App\Models\CodigoDeTributacaoNacional;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CodigosDeTributacaoNacionalTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deferLoading()
            // A coluna da descricao mostra o subitem da LC 116 embaixo, e sem
            // isto sao 1 + N consultas por pagina da tabela.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('item'))
            ->defaultSort('codigo')
            ->striped()
            ->persistSearchInSession()
            ->searchPlaceholder(__('Código ou descrição do serviço'))
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver)
            ->emptyStateHeading(__('Tabela de códigos vazia'))
            ->emptyStateDescription(__('Rode `php artisan db:seed --class=CodigoDeTributacaoNacionalSeeder` para carregar a tabela.'))
            ->columns([
                TextColumn::make('codigo')
                    ->label(__('cTribNac'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage(__('Código copiado'))
                    ->fontFamily(FontFamily::Mono),

                // A coluna mostra "01.07" e a coluna guarda "0107": sem isto,
                // procurar exatamente o que o crachá exibe não acha nada.
                TextColumn::make('item_lista_servico')
                    ->label(__('Item — LC 116'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $prefixo = app(BuscaDeCodigosDeServico::class)->prefixoDoSubitem($search);

                        // Busca por texto não tem dígito nenhum, e `LIKE '%'`
                        // casaria a tabela inteira por esta coluna. O `whereIn`
                        // vazio é o "nada casa" sem SQL escrito à mão.
                        return $prefixo === ''
                            ? $query->whereIn('item_lista_servico', [])
                            : $query->where('item_lista_servico', 'like', "{$prefixo}%");
                    })
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (CodigoDeTributacaoNacional $record): string => $record->itemPontuado()),

                TextColumn::make('descricao')
                    ->label(__('Serviço'))
                    ->searchable()
                    ->wrap()
                    ->limit(220)
                    // O subitem da LC 116 embaixo da descricao do desdobramento:
                    // e o texto da lei que o codigo detalha, e sao os dois que a
                    // nota precisa casar.
                    ->description(fn (CodigoDeTributacaoNacional $record): ?string => $record->item?->descricao),
            ]);
    }
}

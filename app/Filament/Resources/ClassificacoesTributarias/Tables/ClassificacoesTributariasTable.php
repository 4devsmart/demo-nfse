<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClassificacoesTributarias\Tables;

use App\Consultas\BuscaDeClassificacoes;
use App\Models\ClassificacaoTributaria;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClassificacoesTributariasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deferLoading()
            ->defaultSort('codigo')
            ->striped()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->searchPlaceholder(__('Código ou descrição'))
            ->emptyStateIcon(Heroicon::OutlinedTableCells)
            ->emptyStateHeading(__('Tabela de classificação tributária vazia'))
            ->emptyStateDescription(__('Use "Atualizar pela SVRS" para carregar os códigos válidos para NFS-e.'))
            ->columns([
                TextColumn::make('codigo')
                    ->label(__('cClassTrib'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage(__('Código copiado'))
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('cst')
                    ->label(__('CST'))
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->tooltip(fn (ClassificacaoTributaria $registro): string => $registro->nome_cst),

                TextColumn::make('descricao')
                    ->label(__('Descrição'))
                    ->searchable()
                    ->wrap()
                    ->limit(120),

                TextColumn::make('percentual_reducao_ibs')
                    ->label(__('Redução IBS'))
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('percentual_reducao_cbs')
                    ->label(__('Redução CBS'))
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(),

                IconColumn::make('permite_credito_presumido')
                    ->label(__('Crédito presumido'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('vigencia_fim')
                    ->label(__('Vigência até'))
                    ->date('d/m/Y')
                    ->placeholder(__('sem prazo'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('cst')
                    ->label(__('CST'))
                    ->options(fn (): array => app(BuscaDeClassificacoes::class)->situacoesTributarias())
                    ->multiple(),

                // Codigo revogado continua na tabela para explicar nota antiga,
                // mas nao e o que se procura no dia a dia.
                Filter::make('vigente')
                    ->label(__('Só as vigentes'))
                    ->default()
                    ->query(self::apenasVigentes(...)),
            ]);
    }

    /**
     * O parâmetro se chama `$query`, e não `$consulta` como o resto do projeto,
     * porque o nome é contrato: o Filament injeta os argumentos de closure pelo
     * NOME, e `InteractsWithTableQuery` passa a chave `query`. Com outro nome, a
     * injeção não acha nada, cai no container e entrega um `Builder` novo, sem
     * model — que não conhece escopo nenhum e estoura em `vigente()`.
     *
     * O método é nomeado, e não uma closure escrita no lugar, por outro motivo:
     * a regra de vigência mora no escopo do model, e o analisador estático só a
     * enxerga quando o `Builder` vem com o genérico. Closure passada como
     * argumento não carrega PHPDoc que ele leia; método nomeado carrega.
     *
     * @param  Builder<ClassificacaoTributaria>  $query
     * @return Builder<ClassificacaoTributaria>
     */
    private static function apenasVigentes(Builder $query): Builder
    {
        return $query->vigente();
    }
}

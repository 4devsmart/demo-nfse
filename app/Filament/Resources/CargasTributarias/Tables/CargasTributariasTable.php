<?php

declare(strict_types=1);

namespace App\Filament\Resources\CargasTributarias\Tables;

use App\Models\CargaTributariaAproximada;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CargasTributariasTable
{
    private const UNIDADES_FEDERATIVAS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT',
        'PA', 'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->deferLoading()
            ->defaultSort('codigo')
            ->striped()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->searchPlaceholder(__('Código ou descrição'))
            ->emptyStateIcon(Heroicon::OutlinedReceiptPercent)
            ->emptyStateHeading(__('Tabela do IBPT vazia'))
            ->emptyStateDescription(__('Use "Importar tabela do IBPT" para carregar o arquivo baixado com o CNPJ da empresa.'))
            ->columns([
                TextColumn::make('codigo')
                    ->label(__('LC 116'))
                    ->searchable()
                    ->sortable()
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('uf')
                    ->label(__('UF'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('descricao')
                    ->label(__('Serviço'))
                    ->searchable()
                    ->wrap()
                    ->limit(110),

                TextColumn::make('percentual_federal')
                    ->label(__('Federal'))
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('percentual_estadual')
                    ->label(__('Estadual'))
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('percentual_municipal')
                    ->label(__('Municipal'))
                    ->suffix('%')
                    ->alignEnd()
                    ->sortable(),

                // A tabela vence: o IBPT publica uma nova a cada poucos meses,
                // e emitir com percentual vencido descumpre a lei que mandou
                // destacá-lo. Por isso a validade fica na listagem, colorida.
                TextColumn::make('vigencia_fim')
                    ->label(__('Vigente até'))
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn (CargaTributariaAproximada $registro): string => $registro->estaVencida() ? 'danger' : 'success')
                    ->description(fn (CargaTributariaAproximada $registro): string => __('versão :versao', ['versao' => $registro->versao])),
            ])
            ->filters([
                SelectFilter::make('uf')
                    ->label(__('UF'))
                    ->options(array_combine(self::UNIDADES_FEDERATIVAS, self::UNIDADES_FEDERATIVAS))
                    ->multiple(),
            ]);
    }
}

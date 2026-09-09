<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndicadoresDeOperacao\Tables;

use App\Models\IndicadorDeOperacao;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IndicadoresDeOperacaoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deferLoading()
            ->defaultSort('codigo')
            ->striped()
            ->persistSearchInSession()
            ->searchPlaceholder(__('Código ou tipo de operação'))
            ->emptyStateIcon(Heroicon::OutlinedMapPin)
            ->emptyStateHeading(__('Tabela de indicadores vazia'))
            ->emptyStateDescription(__('Rode `php artisan db:seed --class=IndicadorDeOperacaoSeeder` para carregar o Anexo VII.'))
            ->columns([
                TextColumn::make('codigo')
                    ->label(__('cIndOp'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage(__('Código copiado'))
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('tipo_operacao')
                    ->label(__('Tipo de operação'))
                    ->searchable()
                    ->wrap(),

                TextColumn::make('local_do_fornecimento')
                    ->label(__('Local do fornecimento'))
                    ->wrap()
                    ->limit(140)
                    ->description(fn (IndicadorDeOperacao $record): string => $record->caracteristica),

                TextColumn::make('dispositivo_legal')
                    ->label(__('LC 214/2025'))
                    ->badge()
                    ->color('gray'),
            ]);
    }
}

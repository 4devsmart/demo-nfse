<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empresas\Tables;

use App\Models\Empresa;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmpresasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A tabela chega por AJAX, depois da pagina: o que o usuario espera
            // para ver a tela deixa de incluir a consulta.
            ->deferLoading()
            // Sem isto cada linha faria a sua propria consulta pela cidade.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('cidade'))
            ->defaultSort('razao_social')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2)
            ->emptyStateHeading(__('Nenhum emitente cadastrado'))
            ->emptyStateDescription(__('É o emitente que traz o CNPJ, o certificado A1 e o município, que decide o provedor.'))
            ->emptyStateActions([
                CreateAction::make()->label(__('Cadastrar emitente')),
            ])
            ->columns([
                TextColumn::make('razao_social')
                    ->label(__('Razão social'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Empresa $empresa): string => $empresa->documentoFederal()->formatado()),

                TextColumn::make('cidade.nome')
                    ->label(__('Município'))
                    ->formatStateUsing(fn (Empresa $empresa): string => $empresa->cidade->nomeComUf())
                    ->description(fn (Empresa $empresa): string => "IBGE {$empresa->cidade->codigo_ibge}")
                    ->sortable(),

                TextColumn::make('ambiente')
                    ->label(__('Ambiente'))
                    ->badge(),

                TextColumn::make('proximo_numero_dps')
                    ->label(__('Próxima DPS'))
                    ->formatStateUsing(fn (Empresa $empresa): string => "{$empresa->serie_dps}/{$empresa->proximo_numero_dps}")
                    ->alignCenter(),

                TextColumn::make('certificado_valido_ate')
                    ->label(__('Certificado A1'))
                    ->badge()
                    ->placeholder(__('não enviado'))
                    ->formatStateUsing(fn (Empresa $empresa): string => self::situacaoDoCertificado($empresa))
                    ->color(fn (Empresa $empresa): string => self::corDoCertificado($empresa)),
            ])
            ->recordActions([
                EditAction::make()->label(__('Abrir')),
            ]);
    }

    private static function situacaoDoCertificado(Empresa $empresa): string
    {
        if (! $empresa->temCertificado()) {
            return __('não enviado');
        }

        $validade = $empresa->certificado_valido_ate;

        if ($validade === null) {
            return __('enviado');
        }

        return $validade->isPast()
            ? __('vencido em :data', ['data' => $validade->format('d/m/Y')])
            : __('até :data', ['data' => $validade->format('d/m/Y')]);
    }

    private static function corDoCertificado(Empresa $empresa): string
    {
        if (! $empresa->temCertificado()) {
            return 'gray';
        }

        $dias = $empresa->diasAteOCertificadoVencer();

        if ($dias === null) {
            return 'info';
        }

        if ($dias < 0) {
            return 'danger';
        }

        return $dias <= 30 ? 'warning' : 'success';
    }
}

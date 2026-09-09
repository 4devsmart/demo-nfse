<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use App\Models\Cliente;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCliente extends EditRecord
{
    protected static string $resource = ClienteResource::class;

    public function getTitle(): string
    {
        return $this->obterCliente()->razao_social;
    }

    public function getSubheading(): string
    {
        $cliente = $this->obterCliente();

        return "{$cliente->documentoFederal()->formatado()} · {$cliente->cidade->nomeComUf()}";
    }

    /**
     * Desabilitada, e não escondida, pelo mesmo motivo de `OperacaoFiscal`: ação
     * que some deixa quem opera procurando o que não há. O `disabled` do
     * Filament é conferido no servidor ao montar e ao chamar a ação, então ele
     * também é a guarda, não só o aviso.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->outlined()
                ->disabled(fn (Cliente $record): bool => $record->impedimentoParaApagar() !== null)
                ->tooltip(fn (Cliente $record): ?string => $record->impedimentoParaApagar()),
        ];
    }

    /**
     * `getRecord()` é declarado como Model porque a página base serve a
     * qualquer recurso. Aqui sabemos qual é, e `assert` deixa isso escrito.
     */
    private function obterCliente(): Cliente
    {
        $registro = $this->getRecord();
        assert($registro instanceof Cliente);

        return $registro;
    }
}

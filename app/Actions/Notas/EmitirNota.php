<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Models\Nota;
use LogicException;

/**
 * A emissao completa: gera a DPS e transmite. Sao duas chamadas porque a
 * primeira devolve o id_dps antes de qualquer coisa sair.
 */
final readonly class EmitirNota
{
    public function __construct(
        private GerarDps $gerarDps,
        private TransmitirNota $transmitirNota,
    ) {}

    public function executar(Nota $nota): Nota
    {
        $this->exigirQuePodeEmitir($nota);

        return $this->transmitirNota->executar($this->gerarDps->executar($nota));
    }

    /**
     * A conferencia vem antes do primeiro passo, e nao entre os dois. Gerar a
     * DPS para so entao descobrir que falta certificado deixaria a nota com XML
     * novo e sem transmissao nenhuma.
     */
    private function exigirQuePodeEmitir(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraEmitir();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}

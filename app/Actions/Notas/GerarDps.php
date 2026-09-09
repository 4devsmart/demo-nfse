<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Domain\Enums\StatusNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Traducao\MontadorDaDps;
use App\Models\Nota;
use LogicException;

/**
 * Passo 1 de 2. Poe o identificador da DPS nas maos do sistema ANTES de qualquer
 * byte sair: se a transmissao der timeout depois, e ele que permite descobrir se
 * a nota existe.
 */
final readonly class GerarDps
{
    public function __construct(
        private GatewayFiscal $gateway,
        private MontadorDaDps $montador,
    ) {}

    public function executar(Nota $nota): Nota
    {
        $this->exigirQuePodeMontar($nota);

        $gerada = $this->gateway->gerarDps($this->montador->montar($nota));

        $nota->forceFill([
            'id_dps' => $gerada->idDps,
            'xml_dps' => $gerada->xmlEmBase64,
            'provedor' => trim("{$gerada->provedor} ({$gerada->layout})"),
            'status' => StatusNota::DpsGerada,
            'mensagens' => null,
        ])->save();

        return $nota;
    }

    /**
     * Sem esta guarda, `EmitirNota` numa nota ja autorizada regravava o status
     * para `DpsGerada` antes de `TransmitirNota` conferir, e a conferencia
     * passava: o documento saia duas vezes.
     */
    private function exigirQuePodeMontar(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraGerarDps();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}

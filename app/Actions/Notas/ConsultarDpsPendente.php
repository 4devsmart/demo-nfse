<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\RespostaCrua;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Nota;
use LogicException;

/**
 * O fecho do modelo sem estado. Depois de um desfecho indeterminado e ISTO que
 * se chama, nunca reenviar a DPS, que duplicaria documento fiscal.
 *
 * O retorno vem cru: o formato varia por provedor, e normalizar esconderia
 * informacao que so ele tem. Por isso a nota nao muda de status sozinha aqui.
 */
final readonly class ConsultarDpsPendente
{
    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
    ) {}

    public function executar(Nota $nota): RespostaCrua
    {
        $this->exigirIdentificadorDaDps($nota);

        $resposta = $this->gateway->consultarDps(
            $this->contextoDaEmpresa->montar($nota->empresa, $nota->ambiente),
            (string) $nota->id_dps,
        );

        $nota->forceFill([
            'mensagens' => [['codigo' => (string) $resposta->codigo, 'descricao' => $resposta->resposta]],
        ])->save();

        return $resposta;
    }

    private function exigirIdentificadorDaDps(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraConsultarADps();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}

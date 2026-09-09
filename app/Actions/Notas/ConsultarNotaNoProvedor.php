<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\RespostaCrua;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Nota;
use LogicException;

/**
 * Pergunta ao provedor o estado atual de uma nota que ja existe. Serve depois de
 * um cancelamento sem resposta, ou so para conferir o que a prefeitura tem.
 *
 * O retorno vem cru: o formato varia por provedor, e normaliza-lo esconderia
 * informacao que so ele tem.
 */
final readonly class ConsultarNotaNoProvedor
{
    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
    ) {}

    public function executar(Nota $nota): RespostaCrua
    {
        $this->exigirChave($nota);

        return $this->gateway->consultarNota(
            $this->contextoDaEmpresa->montar($nota->empresa, $nota->ambiente),
            (string) $nota->chave,
        );
    }

    private function exigirChave(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraConsultarPelaChave();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}

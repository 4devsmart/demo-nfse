<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Respostas\RespostaCrua;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Nota;
use LogicException;

/**
 * Pergunta ao provedor se um RPS virou nota. E o terceiro caminho de
 * recuperacao, ao lado do id_dps e da chave: aqui a pergunta e feita pelo par
 * serie/numero, que este sistema controla desde o rascunho.
 */
final readonly class ConsultarPorRps
{
    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
    ) {}

    public function executar(Nota $nota, ConsultaPorRps $consulta): RespostaCrua
    {
        $this->exigirCertificado($nota);

        return $this->gateway->consultarPorRps(
            $this->contextoDaEmpresa->montar($nota->empresa, $nota->ambiente),
            $consulta,
        );
    }

    /**
     * Toda chamada ao provedor e assinada. Sem esta guarda a falta de
     * certificado chegava como `CertificadoInvalido` cru, em vez da frase que
     * `ImpedimentosDaNota` da a todos os outros caminhos.
     */
    private function exigirCertificado(Nota $nota): void
    {
        $impedimento = $nota->impedimentos()->paraFalarComOProvedor();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Nota;
use LogicException;

/**
 * A segunda metade da transmissao nos provedores que recebem por lote
 * assincrono. Pergunta pelo protocolo e conclui a nota: autorizada, com o XML
 * que o provedor devolveu, ou rejeitada, com o motivo.
 *
 * Ao contrario de `ConsultarDpsPendente`, aqui a nota muda de status sozinha. A
 * resposta tem o formato da transmissao, e e a mesma conclusao que a
 * transmissao tiraria se o provedor tivesse decidido na hora.
 */
final readonly class ConsultarLoteDaNota
{
    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
        private RegistrarDesfechoDaTransmissao $registrar,
    ) {}

    public function executar(Nota $nota): Nota
    {
        $impedimento = $nota->impedimentos()->paraConsultarOLote();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }

        $resposta = $this->gateway->consultarLote(
            $this->contextoDaEmpresa->montar($nota->empresa, $nota->ambiente),
            (string) $nota->protocolo,
        );

        return $this->registrar->executar($nota, $resposta, acabouDeTransmitir: false);
    }
}

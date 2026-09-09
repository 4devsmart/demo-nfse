<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Traducao\ContextoDaEmpresa;
use App\Models\Nota;
use LogicException;

/**
 * Vai buscar no ADN o documento do evento que cancelou a nota.
 *
 * Existe porque a resposta do cancelamento nao o entrega: no Padrao Nacional
 * ela volta com o evento registrado, protocolo vazio e, no campo `xml_b64`, a
 * frase "Indice informado nao encontrado". A consulta pela chave tambem nao
 * serve, ela devolve a NFS-e como foi autorizada, sem o evento. O documento
 * existe, assinado, na fila DF-e do emitente, e e de la que ele vem.
 *
 * O cursor e de quem chama, e este sistema nao guarda um: o passeio comeca do
 * inicio da fila e para no primeiro evento daquela chave. O teto de paginas
 * existe para que uma fila grande nao vire uma espera sem fim na tela; quando
 * ele e atingido sem achar, a resposta e "nao achei", e nao um documento
 * qualquer.
 */
final readonly class BuscarXmlDoEvento
{
    private const PAGINAS = 40;

    public function __construct(
        private GatewayFiscal $gateway,
        private ContextoDaEmpresa $contextoDaEmpresa,
    ) {}

    public function executar(Nota $nota): bool
    {
        $chave = $this->exigirChave($nota);

        $contexto = $this->contextoDaEmpresa->montar($nota->empresa, $nota->ambiente);
        $nsu = 0;

        for ($pagina = 0; $pagina < self::PAGINAS; $pagina++) {
            $lote = $this->gateway->distribuirDocumentos($contexto, $nsu);

            if ($lote->vazio()) {
                return false;
            }

            foreach ($lote->documentos as $documento) {
                if ($documento->ehEventoDa($chave) && $documento->xml !== '') {
                    $nota->forceFill(['xml_evento' => base64_encode($documento->xml)])->save();

                    return true;
                }
            }

            $nsu = $lote->ultimoNsu($nsu);
        }

        return false;
    }

    private function exigirChave(Nota $nota): string
    {
        $impedimento = $nota->impedimentos()->paraBuscarOEvento();

        if ($impedimento !== null) {
            throw new LogicException($impedimento);
        }

        return (string) $nota->chave;
    }
}

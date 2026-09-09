<?php

declare(strict_types=1);

namespace App\Fiscal\Contracts;

use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Pedidos\ContextoDoProvedor;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Pedidos\NotaSubstituida;
use App\Fiscal\Pedidos\PayloadDps;
use App\Fiscal\Respostas\Danfse;
use App\Fiscal\Respostas\DpsGerada;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Respostas\IdentificacaoDaApi;
use App\Fiscal\Respostas\LoteDeDocumentos;
use App\Fiscal\Respostas\MunicipioAtendido;
use App\Fiscal\Respostas\NotaTransmitida;
use App\Fiscal\Respostas\RespostaCrua;

/**
 * A porta para a API fiscal. Os casos de uso dependem desta interface, nunca do
 * cliente HTTP: e o que permite testar a emissao inteira sem rede.
 */
interface GatewayFiscal
{
    /**
     * Monta a DPS. Nao assina, nao fala com a prefeitura, nao pede certificado.
     *
     * @throws FalhaFiscal
     */
    public function gerarDps(PayloadDps $dps): DpsGerada;

    /**
     * Assina e envia. Timeout aqui volta como DesfechoIndeterminado: nao repetir.
     *
     * @throws FalhaFiscal
     */
    public function transmitirDps(ContextoDoProvedor $contexto, string $xmlEmBase64): NotaTransmitida;

    /**
     * A DPS virou nota? E o fecho do modelo sem estado depois de um 502.
     *
     * @throws FalhaFiscal
     */
    public function consultarDps(ContextoDoProvedor $contexto, string $idDps): RespostaCrua;

    /**
     * Consulta a nota pela chave de acesso. Diferente de consultarDps, que
     * pergunta se a DPS virou nota, esta pergunta o estado de uma nota que ja
     * existe, util depois de um cancelamento sem resposta.
     *
     * @throws FalhaFiscal
     */
    public function consultarNota(ContextoDoProvedor $contexto, string $chave): RespostaCrua;

    /**
     * @throws FalhaFiscal
     */
    public function cancelarNota(ContextoDoProvedor $contexto, string $chave, MotivoDoCancelamento $motivo): EventoRegistrado;

    /**
     * "Aquele RPS virou nota?", o mesmo par serie/numero que este sistema
     * controla. Nem todo provedor oferece.
     *
     * @throws FalhaFiscal
     */
    public function consultarPorRps(ContextoDoProvedor $contexto, ConsultaPorRps $consulta): RespostaCrua;

    /**
     * Emite a DPS substituta identificando a nota antiga. Chamada unica, como o
     * cancelamento. Nem todo provedor implementa: o Padrao Nacional faz
     * substituicao pelo grupo `subst` da DPS, que esta API nao expoe.
     *
     * @throws FalhaFiscal
     */
    public function substituirNota(
        ContextoDoProvedor $contexto,
        PayloadDps $substituta,
        NotaSubstituida $substituida,
        MotivoDoCancelamento $motivo,
    ): EventoRegistrado;

    /**
     * A fila DF-e do ADN a partir de um NSU. E onde o documento do evento fica:
     * a resposta do cancelamento nao o traz, e a consulta pela chave devolve a
     * NFS-e como foi autorizada, sem o evento.
     *
     * O cursor e de quem chama, a API nao guarda onde se parou. Nao chamar em
     * paralelo para o mesmo CNPJ.
     *
     * @throws FalhaFiscal
     */
    public function distribuirDocumentos(ContextoDoProvedor $contexto, int $nsu): LoteDeDocumentos;

    /**
     * DANFSE a partir do XML autorizado: render local, sem certificado.
     *
     * @throws FalhaFiscal
     */
    public function gerarDanfse(string $xmlEmBase64, CodigoIbge $municipio): Danfse;

    /**
     * O municipio e atendido, e por quem? Nao leva certificado.
     *
     * @throws FalhaFiscal
     */
    public function municipio(CodigoIbge $codigo): MunicipioAtendido;

    /**
     * @throws FalhaFiscal
     */
    public function identificacao(): IdentificacaoDaApi;
}

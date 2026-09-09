<?php

declare(strict_types=1);

namespace Tests\Apoio;

use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Pedidos\ContextoDoProvedor;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Pedidos\NotaSubstituida;
use App\Fiscal\Pedidos\PayloadDps;
use App\Fiscal\Respostas\Danfse;
use App\Fiscal\Respostas\DocumentoDistribuido;
use App\Fiscal\Respostas\DpsGerada;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Respostas\IdentificacaoDaApi;
use App\Fiscal\Respostas\LoteDeDocumentos;
use App\Fiscal\Respostas\Mensagens;
use App\Fiscal\Respostas\MunicipioAtendido;
use App\Fiscal\Respostas\NotaTransmitida;
use App\Fiscal\Respostas\RespostaCrua;
use RuntimeException;
use Throwable;

/**
 * O dublê do gateway. Existe porque os casos de uso dependem da interface, e nao
 * do cliente HTTP: a emissao inteira e testavel sem rede.
 */
final class GatewayFiscalFalso implements GatewayFiscal
{
    private const DOCUMENTOS_POR_PAGINA = 2;

    public ?PayloadDps $ultimaDpsMontada = null;

    public ?string $ultimoXmlTransmitido = null;

    public ?FalhaFiscal $falhaNaTransmissao = null;

    public ?FalhaFiscal $falhaNaSubstituicao = null;

    public ?FalhaFiscal $falhaNoCancelamento = null;

    public ?FalhaFiscal $falhaNoDanfse = null;

    /** Quantas vezes a tabela de provedores foi perguntada, para cobrar o cache. */
    public int $municipiosConsultados = 0;

    /** Os NSU pedidos, na ordem: e por eles que se ve o cursor andar. */
    /** @var list<int> */
    public array $nsuPedidos = [];

    /** @var list<DocumentoDistribuido> */
    private array $filaDfe = [];

    private ?NotaTransmitida $respostaDaTransmissao = null;

    private ?EventoRegistrado $respostaDoCancelamento = null;

    private ?MunicipioAtendido $respostaDoMunicipio = null;

    private ?Throwable $falhaNoMunicipio = null;

    public function responderTransmissaoCom(NotaTransmitida $resposta): void
    {
        $this->respostaDaTransmissao = $resposta;
    }

    public function falharNaTransmissaoCom(FalhaFiscal $falha): void
    {
        $this->falhaNaTransmissao = $falha;
    }

    public function responderCancelamentoCom(EventoRegistrado $evento): void
    {
        $this->respostaDoCancelamento = $evento;
    }

    /**
     * @param  list<DocumentoDistribuido>  $documentos
     */
    public function encherAFilaDfeCom(array $documentos): void
    {
        $this->filaDfe = $documentos;
    }

    public function falharNoDanfseCom(FalhaFiscal $falha): void
    {
        $this->falhaNoDanfse = $falha;
    }

    public function responderMunicipioCom(MunicipioAtendido $municipio): void
    {
        $this->respostaDoMunicipio = $municipio;
    }

    /**
     * `Throwable`, e nao `FalhaFiscal`: API fora do ar chega como
     * `ConnectionException`, e e justamente esse caso que a previsao do
     * provedor precisa aguentar sem virar informacao fiscal.
     */
    public function falharNoMunicipioCom(?Throwable $falha): void
    {
        $this->falhaNoMunicipio = $falha;
    }

    public function gerarDps(PayloadDps $dps): DpsGerada
    {
        $this->ultimaDpsMontada = $dps;

        return new DpsGerada(
            idDps: 'DPS330455721913124300019700001000000000000001',
            xmlEmBase64: base64_encode('<DPS/>'),
            layout: 'padrao_nacional',
            provedor: 'PadraoNacional',
        );
    }

    public function transmitirDps(ContextoDoProvedor $contexto, string $xmlEmBase64): NotaTransmitida
    {
        $this->ultimoXmlTransmitido = $xmlEmBase64;

        if ($this->falhaNaTransmissao instanceof FalhaFiscal) {
            throw $this->falhaNaTransmissao;
        }

        return $this->respostaDaTransmissao ?? new NotaTransmitida(
            status: 'autorizado',
            numero: '202600000001',
            chave: str_repeat('3', 50),
            codigoDeVerificacao: 'ABC123',
            protocolo: 'PROTO-1',
            situacao: 'autorizada',
            xmlEmBase64: base64_encode('<NFSe/>'),
            erros: Mensagens::vazia(),
            alertas: Mensagens::vazia(),
        );
    }

    public function consultarDps(ContextoDoProvedor $contexto, string $idDps): RespostaCrua
    {
        return new RespostaCrua(0, "nota encontrada para {$idDps}", base64_encode('<NFSe/>'));
    }

    /**
     * O retorno vem no formato da biblioteca fiscal: linhas de cabecalho e, no
     * Padrao Nacional, a NFS-e inteira depois de `XmlRetorno=`. E XML dentro de
     * texto, e e por isso que a tela precisa escapa-lo.
     */
    public function consultarNota(ContextoDoProvedor $contexto, string $chave): RespostaCrua
    {
        $resposta = "[ConsultaNFSe]\nMetodo=24\nSituacao=\nXmlEnvio=/nfse/{$chave}\n"
            .'XmlRetorno=<?xml version="1.0" encoding="utf-8"?><NFSe><infNFSe><nNFSe>18</nNFSe></infNFSe></NFSe>';

        return new RespostaCrua(0, $resposta, base64_encode('<NFSe/>'));
    }

    public function cancelarNota(ContextoDoProvedor $contexto, string $chave, MotivoDoCancelamento $motivo): EventoRegistrado
    {
        if ($this->falhaNoCancelamento instanceof FalhaFiscal) {
            throw $this->falhaNoCancelamento;
        }

        return $this->respostaDoCancelamento ?? new EventoRegistrado(
            tipo: 'cancelamento',
            status: 'concluido',
            chave: $chave,
            protocolo: 'PROTO-CANC',
            dataHora: now()->toIso8601String(),
            xmlEmBase64: base64_encode('<evento/>'),
            mensagens: Mensagens::vazia(),
        );
    }

    /**
     * Pagina como o ADN: devolve o que esta acima do NSU pedido, ate o tamanho
     * da pagina, e fila vazia quando nao ha mais nada.
     */
    public function distribuirDocumentos(ContextoDoProvedor $contexto, int $nsu): LoteDeDocumentos
    {
        $this->nsuPedidos[] = $nsu;

        $acima = array_values(array_filter(
            $this->filaDfe,
            fn (DocumentoDistribuido $documento): bool => $documento->nsu > $nsu,
        ));

        return new LoteDeDocumentos(array_slice($acima, 0, self::DOCUMENTOS_POR_PAGINA));
    }

    public function consultarPorRps(ContextoDoProvedor $contexto, ConsultaPorRps $consulta): RespostaCrua
    {
        return new RespostaCrua(0, "RPS {$consulta->serie}/{$consulta->numero} encontrado", base64_encode('<NFSe/>'));
    }

    public function substituirNota(
        ContextoDoProvedor $contexto,
        PayloadDps $substituta,
        NotaSubstituida $substituida,
        MotivoDoCancelamento $motivo,
    ): EventoRegistrado {
        if ($this->falhaNaSubstituicao instanceof FalhaFiscal) {
            throw $this->falhaNaSubstituicao;
        }

        return new EventoRegistrado(
            tipo: 'substituicao',
            status: 'concluido',
            chave: str_repeat('9', 50),
            protocolo: 'PROTO-SUBST',
            dataHora: now()->toIso8601String(),
            xmlEmBase64: base64_encode('<NFSe substituta/>'),
            mensagens: Mensagens::vazia(),
            numero: '202600000002',
            codigoDeVerificacao: 'SUBST01',
        );
    }

    public function gerarDanfse(string $xmlEmBase64, CodigoIbge $municipio): Danfse
    {
        if ($this->falhaNoDanfse instanceof FalhaFiscal) {
            throw $this->falhaNoDanfse;
        }

        return new Danfse(base64_encode('%PDF-1.4 fake'));
    }

    public function municipio(CodigoIbge $codigo): MunicipioAtendido
    {
        $this->municipiosConsultados++;

        if ($this->falhaNoMunicipio instanceof Throwable) {
            throw $this->falhaNoMunicipio;
        }

        return $this->respostaDoMunicipio ?? new MunicipioAtendido($codigo, 'PadraoNacional', 'padrao_nacional', true);
    }

    public function identificacao(): IdentificacaoDaApi
    {
        throw new RuntimeException('Não usado nos testes.');
    }
}

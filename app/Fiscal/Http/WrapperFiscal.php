<?php

declare(strict_types=1);

namespace App\Fiscal\Http;

use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Pedidos\ContextoDoProvedor;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Pedidos\NotaCancelada;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as ClienteHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Cliente HTTP da wrapper-api. E a unica classe do projeto que sabe rota, verbo
 * e formato de erro da API fiscal.
 *
 * Rejeicao do provedor volta 422 com o corpo completo, e nao e falha de
 * protocolo: `transmitirDps` e `cancelarNota` devolvem a resposta em vez de
 * lancar, para que a rejeicao chegue ao usuario com o motivo do fisco.
 *
 * Tres rotas gravam documento fiscal no provedor, transmissao, cancelamento e
 * substituicao, e so elas passam `gravaNoProvedor: true`. A diferenca aparece
 * nos dois desfechos em que nao se sabe o que aconteceu do outro lado:
 *
 *   - a API nao responde (`ConnectionException`): nas demais rotas isso e
 *     `api_inacessivel`, que autoriza repetir; nessas e desfecho indeterminado,
 *     porque o silencio nao distingue "nao chegou" de "chegou e demorou";
 *   - a API responde um 5xx que nao se sabe ler, sem `erro.codigo` ou com um
 *     codigo que este sistema nao conhece: mesma duvida, mesmo tratamento.
 *
 * Nos dois casos, repetir duplicaria a nota.
 */
final readonly class WrapperFiscal implements GatewayFiscal
{
    /**
     * O aperto de mao TCP ou acontece em milissegundos ou nao vai acontecer.
     * Sem este teto, host que aceita pacote e nao responde consome o timeout de
     * leitura inteiro, e quem esta na tela espera por ele.
     */
    private const SEGUNDOS_PARA_CONECTAR = 3;

    public function __construct(
        private ClienteHttp $http,
        private string $urlBase,
        private string $token,
        private int $segundosDeTimeout,
    ) {}

    public function gerarDps(PayloadDps $dps): DpsGerada
    {
        return DpsGerada::doCorpoDaResposta($this->postar('/v1/nfse/xml', $dps->paraApi()));
    }

    public function transmitirDps(ContextoDoProvedor $contexto, string $xmlEmBase64): NotaTransmitida
    {
        return NotaTransmitida::doCorpoDaResposta($this->postar(
            '/v1/nfse/transmissao',
            ['xml_b64' => $xmlEmBase64, ...$contexto->paraApi()],
            aceitando: [422],
            gravaNoProvedor: true,
        ));
    }

    /**
     * So leitura, e por isso sem `gravaNoProvedor`: o lote ja foi entregue na
     * transmissao, e perguntar de novo nao cria documento nenhum.
     */
    public function consultarLote(ContextoDoProvedor $contexto, string $protocolo): NotaTransmitida
    {
        return NotaTransmitida::doCorpoDaResposta($this->postar(
            '/v1/nfse/transmissao/lote',
            ['protocolo' => $protocolo, ...$contexto->paraApi()],
            aceitando: [422],
        ));
    }

    public function consultarDps(ContextoDoProvedor $contexto, string $idDps): RespostaCrua
    {
        return RespostaCrua::doCorpoDaResposta($this->postar(
            '/v1/nfse/consulta-dps',
            ['chave' => $idDps, ...$contexto->paraApi()],
        ));
    }

    public function consultarNota(ContextoDoProvedor $contexto, string $chave): RespostaCrua
    {
        return RespostaCrua::doCorpoDaResposta($this->postar(
            '/v1/nfse/consulta',
            ['chave' => $chave, ...$contexto->paraApi()],
        ));
    }

    public function cancelarNota(ContextoDoProvedor $contexto, NotaCancelada $nota, MotivoDoCancelamento $motivo): EventoRegistrado
    {
        return EventoRegistrado::doCorpoDaResposta($this->postar(
            '/v1/nfse/eventos/cancelamento',
            [...$nota->paraApi(), 'evento' => [...$motivo->paraApi(), ...$nota->paraEvento()], ...$contexto->paraApi()],
            aceitando: [422],
            gravaNoProvedor: true,
        ));
    }

    public function consultarPorRps(ContextoDoProvedor $contexto, ConsultaPorRps $consulta): RespostaCrua
    {
        return RespostaCrua::doCorpoDaResposta($this->postar(
            '/v1/nfse/consultas/rps',
            [...$consulta->paraApi(), ...$contexto->paraApi()],
        ));
    }

    public function substituirNota(
        ContextoDoProvedor $contexto,
        PayloadDps $substituta,
        NotaSubstituida $substituida,
        MotivoDoCancelamento $motivo,
    ): EventoRegistrado {
        return EventoRegistrado::doCorpoDaResposta($this->postar(
            '/v1/nfse/eventos/substituicao',
            [
                'evento' => [
                    'dps' => $substituta->paraApi(),
                    'substituida' => $substituida->paraApi(),
                    ...$motivo->paraApi(),
                ],
                ...$contexto->paraApi(),
            ],
            aceitando: [422],
            gravaNoProvedor: true,
        ));
    }

    public function distribuirDocumentos(ContextoDoProvedor $contexto, int $nsu): LoteDeDocumentos
    {
        return LoteDeDocumentos::doCorpoDaResposta($this->postar(
            '/v1/nfse/distribuicao',
            ['nsu' => $nsu, ...$contexto->paraApi()],
        ));
    }

    public function gerarDanfse(string $xmlEmBase64, CodigoIbge $municipio): Danfse
    {
        return Danfse::doCorpoDaResposta($this->postar('/v1/nfse/pdf', [
            'xml_b64' => $xmlEmBase64,
            'municipio' => (string) $municipio,
        ]));
    }

    public function municipio(CodigoIbge $codigo): MunicipioAtendido
    {
        return MunicipioAtendido::doCorpoDaResposta($this->obter("/v1/nfse/municipios/{$codigo}"));
    }

    public function identificacao(): IdentificacaoDaApi
    {
        return IdentificacaoDaApi::dasRespostas(
            $this->obter('/v1/ping'),
            $this->obter('/v1/capacidades'),
        );
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @param  list<int>  $aceitando
     * @return array<string, mixed>
     */
    private function postar(string $rota, array $corpo, array $aceitando = [], bool $gravaNoProvedor = false): array
    {
        return $this->interpretar(
            fn (): Response => $this->requisicao()->post($rota, $corpo),
            $aceitando,
            $gravaNoProvedor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function obter(string $rota): array
    {
        return $this->interpretar(fn (): Response => $this->requisicao()->get($rota));
    }

    /**
     * @param  callable(): Response  $chamada
     * @param  list<int>  $aceitando
     * @return array<string, mixed>
     */
    private function interpretar(callable $chamada, array $aceitando = [], bool $gravaNoProvedor = false): array
    {
        try {
            $resposta = $chamada();
        } catch (ConnectionException $falha) {
            throw $gravaNoProvedor
                ? FalhaFiscal::semRespostaDepoisDeEnviar($falha->getMessage(), $falha)
                : FalhaFiscal::semResposta($falha->getMessage(), $falha);
        }

        $corpo = (array) $resposta->json();

        if ($resposta->successful() || in_array($resposta->status(), $aceitando, true)) {
            return $this->corpoUtil($corpo, $resposta->status(), $aceitando);
        }

        throw $this->falhaDe($corpo, $resposta->status(), $gravaNoProvedor);
    }

    /**
     * Um 5xx que nao se sabe ler e o mesmo silencio do timeout, com outro
     * disfarce: a API respondeu, mas nao disse o que aconteceu com o documento.
     * Em rota que grava, isso e duvida, e duvida nao autoriza reenviar.
     *
     * A guarda e por codigo, nao por status: quando a API diz `regras_de_negocio`
     * num 500, quem manda e o codigo, que afirma que nada foi transmitido. Ler
     * isso como duvida mandaria consultar uma nota que nao existe. E 4xx fica de
     * fora inteiro, porque e recusa na porta: nada chegou a executar.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function falhaDe(array $corpo, int $status, bool $gravaNoProvedor): FalhaFiscal
    {
        $falha = FalhaFiscal::doCorpoDaResposta($corpo, $status);

        if ($gravaNoProvedor && $status >= 500 && CodigoDeFalha::tryFrom($falha->codigo) === null) {
            return FalhaFiscal::semDesfechoLegivel($status, $falha->getMessage());
        }

        return $falha;
    }

    /**
     * Um 422 aceito ainda pode ser recusa antes de transmitir, e ai vem no
     * envelope de erro, nao no corpo do documento.
     *
     * @param  array<string, mixed>  $corpo
     * @param  list<int>  $aceitando
     * @return array<string, mixed>
     */
    private function corpoUtil(array $corpo, int $status, array $aceitando): array
    {
        if (in_array($status, $aceitando, true) && isset($corpo['erro'])) {
            throw FalhaFiscal::doCorpoDaResposta($corpo, $status);
        }

        return $corpo;
    }

    /**
     * A URL base vem da configuracao e pode chegar com barra no fim. Nao ha
     * `rtrim` aqui: como todas as rotas acima sao relativas, quem
     * junta e o `PendingRequest::send()` do Laravel, que ja apara a base antes
     * de concatenar. Aparar de novo era codigo morto, e o teste que dizia
     * guarda-lo passava por causa do framework, nao dele.
     *
     * O espelho da documentacao e o caso oposto: la a URL e montada por
     * concatenacao e entregue absoluta, o Laravel nao encosta nela, e o `rtrim`
     * de `DocumentacaoFiscalController` e carga real.
     */
    private function requisicao(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->urlBase)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(self::SEGUNDOS_PARA_CONECTAR)
            ->timeout($this->segundosDeTimeout);
    }
}

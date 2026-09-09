<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as ClienteHttp;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Espelha o Swagger UI que a propria API fiscal publica. Em producao ela nao tem
 * router nenhum e nao expoe porta: este wrapper e o unico caminho ate a
 * documentacao, e ele fica atras do login do painel.
 *
 * O espelho nao leva o Bearer. `/docs` e `/openapi.yaml` sao abertos na API;
 * `/v1` nao e espelhado porque espelha-lo de forma util exigiria mandar o token
 * do servidor, e ai a pagina de documentacao viraria
 * um proxy autenticado para emitir documento fiscal. Para experimentar as rotas,
 * use a porta publicada em desenvolvimento (compose.override.yaml).
 */
final class DocumentacaoFiscalController extends Controller
{
    /** Ver `WrapperFiscal`: o aperto de mao nao usa o timeout de leitura. */
    private const SEGUNDOS_PARA_CONECTAR = 3;

    public function __construct(
        private readonly ClienteHttp $http,
        private readonly string $urlBase,
        private readonly int $segundosDeTimeout,
    ) {}

    public function pagina(): Response
    {
        return $this->espelhar('/docs');
    }

    public function recurso(string $arquivo): Response
    {
        return $this->espelhar('/docs/'.$arquivo);
    }

    public function especificacao(): Response
    {
        return $this->espelhar('/openapi.yaml');
    }

    private function espelhar(string $rota): Response
    {
        try {
            $resposta = $this->http
                ->connectTimeout(self::SEGUNDOS_PARA_CONECTAR)
                ->timeout($this->segundosDeTimeout)
                ->get(rtrim($this->urlBase, '/').$rota);
        } catch (ConnectionException $falha) {
            return new Response(
                __('A API fiscal não respondeu: :motivo', ['motivo' => $falha->getMessage()]),
                Status::HTTP_BAD_GATEWAY,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return new Response(
            $resposta->body(),
            $resposta->status(),
            ['Content-Type' => $resposta->header('Content-Type') ?: 'application/octet-stream'],
        );
    }
}

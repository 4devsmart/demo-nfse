<?php

declare(strict_types=1);

namespace App\Fiscal\Excecoes;

use RuntimeException;
use Throwable;

/**
 * O envelope de erro da API fiscal e sempre `{"erro":{"codigo","mensagem","detalhes"}}`.
 * O contrato manda tratar pelo `codigo`, nunca pela mensagem, e e o que esta
 * classe preserva.
 */
class FalhaFiscal extends RuntimeException
{
    /**
     * @param  array<int|string, mixed>  $detalhes
     */
    public function __construct(
        public readonly string $codigo,
        string $mensagem,
        public readonly array $detalhes = [],
        public readonly int $statusHttp = 0,
        ?Throwable $anterior = null,
    ) {
        parent::__construct($mensagem, 0, $anterior);
    }

    /**
     * @param  array<int|string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo, int $statusHttp): self
    {
        $erro = is_array($corpo['erro'] ?? null) ? $corpo['erro'] : [];
        $codigo = (string) ($erro['codigo'] ?? 'falha_desconhecida');
        $mensagem = (string) ($erro['mensagem'] ?? __('A API fiscal recusou a chamada.'));
        $detalhes = (array) ($erro['detalhes'] ?? []);

        if ($codigo === CodigoDeFalha::DesfechoIndeterminado->value) {
            return new DesfechoIndeterminado($codigo, $mensagem, $detalhes, $statusHttp);
        }

        return new self($codigo, $mensagem, $detalhes, $statusHttp);
    }

    public static function semResposta(string $motivo, ?Throwable $anterior = null): self
    {
        return new self(
            CodigoDeFalha::ApiInacessivel->value,
            __('A API fiscal não respondeu: :motivo', ['motivo' => $motivo]),
            [],
            0,
            $anterior,
        );
    }

    /**
     * O mesmo silêncio, depois de a chamada ter saído, e aí ele quer dizer
     * outra coisa. `semResposta` diz "nada saiu, repetir é seguro"; isso só se
     * sabe quando a conexão nem chegou a se abrir. Um timeout de leitura chega
     * pelo mesmo `ConnectionException` e não distingue "a prefeitura nunca viu"
     * de "a prefeitura viu e demorou". Em rota que grava documento fiscal, a
     * dúvida vale por indeterminado: repetir duplicaria a nota.
     */
    public static function semRespostaDepoisDeEnviar(string $motivo, ?Throwable $anterior = null): DesfechoIndeterminado
    {
        return new DesfechoIndeterminado(
            CodigoDeFalha::DesfechoIndeterminado->value,
            __('A API fiscal não respondeu depois de a chamada sair: :motivo', ['motivo' => $motivo]),
            [],
            0,
            $anterior,
        );
    }

    /**
     * A API respondeu, e a resposta não diz nada: um 5xx sem `erro.codigo`, ou
     * com um código que este sistema não conhece. Um proxy no meio do caminho,
     * um corpo HTML, um corpo vazio.
     *
     * Em rota que grava documento fiscal isso vale o mesmo que o silêncio de
     * `semRespostaDepoisDeEnviar`: o erro pode ter sido antes de a prefeitura
     * receber, ou depois. A diferença é só que aqui houve resposta, e o status
     * dela entra na frase porque é o único dado que sobrou.
     */
    public static function semDesfechoLegivel(int $statusHttp, string $motivo): DesfechoIndeterminado
    {
        return new DesfechoIndeterminado(
            CodigoDeFalha::DesfechoIndeterminado->value,
            __('A API fiscal respondeu :status sem dizer o que aconteceu com o documento: :motivo', ['status' => $statusHttp, 'motivo' => $motivo]),
            [],
            $statusHttp,
        );
    }

    /**
     * Nao ha caminho de tela que pergunte isto: quem opera le `oQueFazer()`,
     * que responde por codigo e diz mais. Quem pergunta e a suite, e e de
     * proposito: e nela que cada caminho de falha declara se repetir duplicaria
     * documento fiscal, ao lado do codigo que o produziu.
     */
    public function ehSeguroRepetir(): bool
    {
        return CodigoDeFalha::tryFrom($this->codigo)?->podeRepetir() ?? false;
    }

    /**
     * O que fazer agora, pelo codigo da falha. Codigo que a API inventou depois
     * desta versao volta vazio: melhor sem orientacao do que com a errada.
     */
    public function oQueFazer(): string
    {
        return CodigoDeFalha::tryFrom($this->codigo)?->oQueFazer() ?? '';
    }

    public function resumo(): string
    {
        return "[{$this->codigo}] {$this->getMessage()}";
    }
}

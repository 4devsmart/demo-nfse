<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\FalhaFiscal;

/**
 * Retorno de GET /v1/nfse/municipios/{codigo}. Nao leva certificado: e como se
 * descobre, antes de montar qualquer coisa, se vale a pena tentar.
 */
final readonly class MunicipioAtendido
{
    public function __construct(
        public CodigoIbge $codigo,
        public string $provedor,
        public string $layout,
        public bool $suportado,
    ) {}

    /**
     * Sem codigo utilizavel nao ha resposta: corpo vazio, corpo que nao e JSON
     * ou `codigo` fora dos sete digitos precisam falhar aqui. Sem esta guarda a
     * tela diria "sem provedor de NFS-e conhecido" para o que na verdade foi uma
     * resposta ilegivel, escondendo a falha atras de uma informacao fiscal.
     *
     * `0000000` NAO e esse caso: e o que a propria API devolve, com 200 e
     * `suportado: false`, quando o municipio perguntado nao existe na tabela
     * dela. Sao sete digitos, entao passa por esta guarda, e "sem provedor
     * conhecido" e a leitura certa dessa resposta.
     *
     * @param  array<string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo): self
    {
        $resposta = new LeitorDaResposta($corpo);
        $codigo = $resposta->texto('codigo');

        if (! CodigoIbge::ehValido($codigo)) {
            throw new FalhaFiscal(
                CodigoDeFalha::JsonInvalido->value,
                __('A API respondeu sobre o município sem um código IBGE utilizável: ":codigo".', ['codigo' => $codigo]),
            );
        }

        return new self(
            codigo: CodigoIbge::deSeteDigitos($codigo),
            provedor: $resposta->texto('provedor'),
            layout: $resposta->texto('layout'),
            suportado: $resposta->logico('suportado'),
        );
    }

    public function descricao(): string
    {
        if (! $this->suportado) {
            return __('sem provedor de NFS-e conhecido');
        }

        return "{$this->provedor} ({$this->layout})";
    }
}

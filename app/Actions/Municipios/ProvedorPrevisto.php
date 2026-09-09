<?php

declare(strict_types=1);

namespace App\Actions\Municipios;

use App\Fiscal\Respostas\MunicipioAtendido;

/**
 * O que se sabe, antes de emitir, sobre quem vai receber a DPS.
 *
 * Sao tres estados, e nao dois: "atendido por X", "municipio sem provedor
 * conhecido" e "nao deu para perguntar agora". Juntar os dois ultimos faria a
 * tela dizer que o municipio nao tem provedor toda vez que a API estivesse fora
 * do ar, o que e informacao fiscal errada saindo de uma falha de rede.
 */
final readonly class ProvedorPrevisto
{
    /** Como a API nomeia o leiaute do Padrao Nacional em `GET /nfse/municipios`. */
    private const LEIAUTE_NACIONAL = 'padrao_nacional';

    private function __construct(private ?MunicipioAtendido $municipio) {}

    public static function de(MunicipioAtendido $municipio): self
    {
        return new self($municipio);
    }

    public static function naoConsultado(): self
    {
        return new self(null);
    }

    public function consultado(): bool
    {
        return $this->municipio instanceof MunicipioAtendido;
    }

    public function atendido(): bool
    {
        return $this->municipio?->suportado === true;
    }

    /**
     * O leiaute do provedor, que e o que decide quais operacoes existem.
     *
     * A consulta por RPS e o par serie/numero do mundo ABRASF. No Padrao
     * Nacional o caminho equivalente e a chave da DPS, o `id_dps`, e mandar o
     * pedido no formato ABRASF volta com "Chave da DPS não informada" (X126).
     *
     * Sem consulta nao ha resposta: com a API fora do ar nao se afirma nada
     * sobre o leiaute do municipio.
     */
    public function ehPadraoNacional(): bool
    {
        return $this->municipio?->layout === self::LEIAUTE_NACIONAL;
    }

    public function descricao(): string
    {
        return $this->municipio?->descricao() ?? __('não deu para consultar agora');
    }
}

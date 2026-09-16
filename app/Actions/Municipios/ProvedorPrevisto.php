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

    /** @var list<string> */
    private const PROVEDORES_COM_LOCALIDADE_NO_RPS = ['Giss', 'Ginfes', 'Saatri', 'ISSNatal'];

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

    /**
     * O provedor quer o `cLocalidadeIncid` dentro do RPS?
     *
     * A lista sai dos gravadores do ACBr que escrevem o campo como obrigatorio
     * no RPS: Giss 2.04, Ginfes, Saatri 2.03 e ISSNatal. Nos outros ABRASF ele
     * so aparece na NFS-e que o provedor devolve, e no Padrao Nacional quem o
     * calcula e a Sefin. Tinus e SpeedGov o aceitam opcional e ficam de fora: o
     * Tinus, recebendo o campo, anexa ao RPS o grupo de valores da NFS-e
     * inteiro, que esta nota nao preenche.
     */
    public function exigeLocalidadeDeIncidencia(): bool
    {
        return in_array($this->municipio?->provedor, self::PROVEDORES_COM_LOCALIDADE_NO_RPS, true);
    }

    public function descricao(): string
    {
        return $this->municipio?->descricao() ?? __('não deu para consultar agora');
    }
}

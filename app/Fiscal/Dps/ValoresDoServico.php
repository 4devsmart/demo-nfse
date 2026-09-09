<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\TributacaoIssqn;
use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Dinheiro;

/**
 * `infDPS.valores`. Os atalhos de `valores` e o grupo `tribMun` vao juntos de
 * proposito: o Padrao Nacional le `tribMun`, e o construtor ABRASF cai nos
 * atalhos quando o provedor do municipio nao e o nacional.
 *
 * `tribFed` e opcional e sai do JSON quando ninguem o preenche: quem monta o
 * grupo e `RetencoesFederais`, e o `semVazios()` do ConstrutorDps poda o nulo.
 */
final readonly class ValoresDoServico
{
    private function __construct(
        public Dinheiro $valorDoServico,
        public Aliquota $aliquotaDoIss,
        public TributacaoIssqn $tributacao,
        public RetencaoIssqn $retencao,
        public Dinheiro $deducoes,
        public Dinheiro $descontoIncondicionado,
        public Dinheiro $descontoCondicionado,
        public ?ExigibilidadeSuspensa $exigibilidadeSuspensa = null,
        public ?BeneficioMunicipal $beneficioMunicipal = null,
        public ?TotaisAproximados $totaisAproximados = null,
        public ?RetencoesFederais $retencoesFederais = null,
        public bool $issqnNoSimplesNacional = false,
    ) {}

    public static function cobrados(Dinheiro $valorDoServico, Aliquota $aliquotaDoIss): self
    {
        return new self(
            $valorDoServico,
            $aliquotaDoIss,
            TributacaoIssqn::OperacaoTributavel,
            RetencaoIssqn::NaoRetido,
            Dinheiro::zero(),
            Dinheiro::zero(),
            Dinheiro::zero(),
        );
    }

    public function tributadosComo(TributacaoIssqn $tributacao, RetencaoIssqn $retencao): self
    {
        return $this->com(tributacao: $tributacao, retencao: $retencao);
    }

    public function comDeducoes(Dinheiro $deducoes): self
    {
        return $this->com(deducoes: $deducoes);
    }

    public function comDescontos(Dinheiro $incondicionado, Dinheiro $condicionado): self
    {
        return $this->com(descontoIncondicionado: $incondicionado, descontoCondicionado: $condicionado);
    }

    public function comExigibilidadeSuspensa(?ExigibilidadeSuspensa $suspensao): self
    {
        return $this->com(exigibilidadeSuspensa: $suspensao);
    }

    public function comBeneficioMunicipal(?BeneficioMunicipal $beneficio): self
    {
        return $this->com(beneficioMunicipal: $beneficio);
    }

    public function comTotaisAproximados(?TotaisAproximados $totais): self
    {
        return $this->com(totaisAproximados: $totais);
    }

    public function comRetencoesFederais(?RetencoesFederais $retencoes): self
    {
        return $this->com(retencoesFederais: $retencoes);
    }

    /**
     * O ISSQN do prestador vai na guia unica do Simples, e nao nesta nota.
     * Quem sabe disso e o cadastro do emitente: `opSimpNac` 3 com
     * `regApTribSN` 1.
     */
    public function comIssqnNoSimplesNacional(bool $apuraPeloSimples): self
    {
        return $this->com(issqnNoSimplesNacional: $apuraPeloSimples);
    }

    /**
     * Um so lugar reconstroi o objeto: os `com*` acima ficam de uma linha, e
     * acrescentar um grupo novo nao obriga a mexer em todos eles.
     */
    private function com(
        ?TributacaoIssqn $tributacao = null,
        ?RetencaoIssqn $retencao = null,
        ?Dinheiro $deducoes = null,
        ?Dinheiro $descontoIncondicionado = null,
        ?Dinheiro $descontoCondicionado = null,
        ?ExigibilidadeSuspensa $exigibilidadeSuspensa = null,
        ?BeneficioMunicipal $beneficioMunicipal = null,
        ?TotaisAproximados $totaisAproximados = null,
        ?RetencoesFederais $retencoesFederais = null,
        ?bool $issqnNoSimplesNacional = null,
    ): self {
        return new self(
            $this->valorDoServico,
            $this->aliquotaDoIss,
            $tributacao ?? $this->tributacao,
            $retencao ?? $this->retencao,
            $deducoes ?? $this->deducoes,
            $descontoIncondicionado ?? $this->descontoIncondicionado,
            $descontoCondicionado ?? $this->descontoCondicionado,
            $exigibilidadeSuspensa ?? $this->exigibilidadeSuspensa,
            $beneficioMunicipal ?? $this->beneficioMunicipal,
            $totaisAproximados ?? $this->totaisAproximados,
            $retencoesFederais ?? $this->retencoesFederais,
            $issqnNoSimplesNacional ?? $this->issqnNoSimplesNacional,
        );
    }

    /**
     * Deducoes e desconto incondicionado saem da base. Juntos, eles podem
     * passar do valor do servico, e ai a base fica negativa e o ISSQN tambem.
     * Nao ha nada no caminho que trave isso sozinho: `Dinheiro` subtrai o que
     * mandarem, e a API recebe `vServ`, `vDeducoes` e `vDescIncond` separados,
     * entao a conta so acontece do outro lado.
     *
     * Quem pergunta e o ConstrutorDps, que recusa montar a DPS; a conta em si
     * continua honesta, para que a previa da tela mostre o numero negativo em
     * vez de um zero que nao explica nada.
     */
    public function abatimentosPassamDoServico(): bool
    {
        return $this->baseDeCalculo()->centavos < 0;
    }

    /**
     * O beneficio municipal entra aqui, e nao no fim: ele reduz a base, entao
     * muda o imposto devido.
     */
    public function baseDeCalculo(): Dinheiro
    {
        $base = $this->valorDoServico
            ->subtrair($this->deducoes)
            ->subtrair($this->descontoIncondicionado);

        if ($this->beneficioMunicipal === null) {
            return $base;
        }

        return $base->subtrair($base->multiplicarPor($this->beneficioMunicipal->percentualDeReducao));
    }

    /**
     * Zero tambem quando o ISSQN e apurado pelo Simples e ninguem retem: o
     * imposto sai na guia unica, e nao nesta nota. Cobrar aliquota sobre a base
     * aqui mostraria na previa um imposto que este documento nao carrega.
     */
    public function issqnDevido(): Dinheiro
    {
        if (! $this->tributacao->temIssqnDevido() || ! $this->declaraAliquota()) {
            return Dinheiro::zero();
        }

        return $this->baseDeCalculo()->multiplicarPor($this->aliquotaDoIss);
    }

    /**
     * Os dois descontos saem do liquido; so o incondicionado sai da base. E a
     * diferenca entre eles: o condicionado nao muda o imposto devido, mas muda
     * o que o tomador paga, e por isso entra aqui e nao em `baseDeCalculo()`.
     *
     * As retencoes federais saem sempre, e nao dependem de pergunta como o
     * ISSQN: no leiaute nao existe "IRRF devido nao retido". O campo so guarda
     * valor retido, entao valor informado ja e valor que o prestador nao recebe.
     *
     * A conta segue o `vLiq` do Padrao Nacional, que e o layout de referencia
     * deste projeto. Ela nao e uma previsao do numero do provedor, e nao ha
     * como ser: a API nao devolve total calculado em rota nenhuma, nem na
     * transmissao, nem nas consultas, e `GET /nfse/municipios/{codigo}` so
     * diz provedor, layout e se e atendido. O numero de quem autorizou existe
     * apenas dentro do XML autorizado, depois do fato. Em municipio de layout
     * `proprio` os dois podem divergir, e quem tem razao la e o XML.
     */
    public function valorLiquido(): Dinheiro
    {
        $liquido = $this->valorDoServico
            ->subtrair($this->descontoIncondicionado)
            ->subtrair($this->descontoCondicionado)
            ->subtrair($this->retencoesFederais?->total() ?? Dinheiro::zero());

        if ($this->retencao === RetencaoIssqn::NaoRetido) {
            return $liquido;
        }

        return $liquido->subtrair($this->issqnDevido());
    }

    /**
     * A aliquota declarada, ou `null` quando nao ha imposto a que ela se aplique.
     *
     * Exportacao, nao incidencia e imunidade nao tem aliquota: o `issqnDevido()`
     * ja devolvia zero nas tres, mas o `pAliq` seguia saindo com o percentual do
     * cadastro, e o XML de uma operacao imune declarava 5%. O `semVazios()` do
     * ConstrutorDps poda o nulo, entao o campo simplesmente nao aparece.
     */
    private function aliquotaDeclarada(): ?float
    {
        return $this->declaraAliquota() ? $this->aliquotaDoIss->percentual : null;
    }

    /**
     * Se ha aliquota municipal a declarar nesta nota.
     *
     * Nao ha em duas situacoes. Exportacao, nao incidencia e imunidade nao tem
     * aliquota: nao ha imposto a que ela se aplique.
     *
     * E o ME/EPP que apura o ISSQN pelo Simples, quando ninguem retem: a
     * rejeicao E0625 recusa a nota que informa aliquota nesse caso, porque o
     * imposto esta dentro da guia unica e a aliquota e a da tabela do Simples,
     * nao a do municipio. Retencao muda tudo: retido, ha aliquota e ha imposto
     * neste documento.
     *
     * Beneficio municipal fica de fora da regra, e de proposito. A E0625 abre
     * excecao para beneficio de isencao ou de aliquota diferenciada, e o grupo
     * `tribMun` do leiaute nao diz de que tipo e o beneficio: guarda numero e
     * percentual de reducao. Sem como distinguir, a nota com beneficio segue
     * declarando a aliquota, que e o lado em que errar produz rejeicao
     * diferente e nao silencio.
     */
    public function declaraAliquota(): bool
    {
        if (! $this->tributacao->temIssqnDevido()) {
            return false;
        }

        return ! $this->issqnNoSimplesNacional
            || $this->retencao !== RetencaoIssqn::NaoRetido
            || $this->beneficioMunicipal !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function paraApi(): array
    {
        return [
            'vServ' => $this->valorDoServico->emReais(),
            'vDeducoes' => $this->deducoes->emReais(),
            'vDescIncond' => $this->descontoIncondicionado->emReais(),
            'vDescCond' => $this->descontoCondicionado->emReais(),
            'tribISSQN' => $this->tributacao->value,
            'pAliq' => $this->aliquotaDeclarada(),
            'iss_retido' => $this->retencao === RetencaoIssqn::NaoRetido ? 2 : 1,
            'tribMun' => [
                'tribISSQN' => $this->tributacao->value,
                'tpRetISSQN' => $this->retencao->value,
                'pAliq' => $this->aliquotaDeclarada(),
                ...($this->exigibilidadeSuspensa?->paraApi() ?? []),
                ...($this->beneficioMunicipal?->paraApi() ?? []),
            ],
            'tribFed' => $this->retencoesFederais?->paraApi(),
            'totTrib' => $this->totaisAproximados?->paraApi(),
        ];
    }
}

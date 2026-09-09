<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\Enums\RetencaoDeContribuicoesSociais;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Dinheiro;

/**
 * `infDPS.valores.trib.tribFed`, na forma que a NT 007 (7/2/2026) deixou.
 *
 * O ponto da NT e uma distincao que o layout antigo nao deixava clara, e que
 * muito contribuinte errou: `vPis` e `vCofins` sao os valores DEVIDOS na
 * operacao, debito de apuracao propria. O que o tomador RETEM nao vai neles.
 * Usar esses campos para retencao reduzia indevidamente a base do IBS e da CBS,
 * porque o inciso V do parag. 2 do art. 12 da LC 214/2025 manda excluir esses
 * tributos dessa base.
 *
 * O valor retido das tres contribuicoes sociais, PIS, COFINS e CSLL, vai somado
 * num campo so, `vRetCSLL`, e quem diz quais das tres entraram na soma e o
 * `tpRetPisCofins`. Parece estranho ler CSLL no nome do campo que carrega
 * tambem PIS e COFINS, mas e o que a NT determina, e a EFD-Reinf continua
 * recebendo as tres separadas como sempre recebeu.
 *
 * IRRF e contribuicao previdenciaria seguem em campos proprios, e nao entram
 * nessa soma.
 */
final readonly class RetencoesFederais
{
    /**
     * O piso de dispensa da CSRF: a Lei 10.833/2003, art. 31, parag. 3, dispensa
     * a retencao "para pagamentos de valor igual ou inferior a R$ 5.000,00".
     *
     * Quem responde se ela vale e quem emite, e nao este sistema: o parag. 4 do
     * mesmo artigo manda somar os pagamentos do mes ao mesmo prestador, e o
     * acumulado do mes nao esta aqui. Por isso o numero fica exposto para a tela
     * avisar, e nada e suprimido sozinho.
     */
    public const CENTAVOS_DISPENSADOS_DA_CSRF = 500000;

    private function __construct(
        public Dinheiro $baseDeCalculo,
        public Dinheiro $valorBrutoDaNota,
        public SituacaoTributariaPisCofins $situacao,
        public Aliquota $aliquotaDoPis,
        public Aliquota $aliquotaDaCofins,
        public Aliquota $aliquotaDaCsll,
        public Aliquota $aliquotaDoIrrf,
        public Aliquota $aliquotaPrevidenciaria,
        public bool $retemPis,
        public bool $retemCofins,
        public bool $retemCsll,
    ) {}

    /**
     * Sao duas bases, porque a lei manda duas, e ate aqui havia so uma.
     *
     * A CSRF incide "sobre o montante a ser pago" (Lei 10.833/2003, art. 31,
     * caput) e o IRRF sobre "as importancias pagas ou creditadas" (RIR/2018,
     * art. 714, caput): as duas sao o valor do servico menos o desconto
     * incondicionado, que e abatimento certo e ja reduz o que o tomador paga. E
     * a mesma base do PIS e da COFINS devidos, porque desconto incondicional
     * nao integra a receita (Lei 10.833, art. 1, parag. 3, V, "a").
     *
     * A previdenciaria e a excecao, e nao por descuido: o art. 31 da Lei
     * 8.212/91 manda reter 11% "do valor bruto da nota fiscal ou fatura", e
     * bruto ali quer dizer bruto.
     *
     * Deducao e beneficio municipal nao entram em nenhuma das duas: sao regra do
     * municipio, e nao alcancam tributo federal.
     */
    public static function sobre(Dinheiro $montanteAPagar, Dinheiro $valorBrutoDaNota): self
    {
        return new self(
            $montanteAPagar,
            $valorBrutoDaNota,
            SituacaoTributariaPisCofins::TributavelAliquotaBasica,
            Aliquota::deQuatroCasas(0),
            Aliquota::deQuatroCasas(0),
            Aliquota::deQuatroCasas(0),
            Aliquota::deQuatroCasas(0),
            Aliquota::deQuatroCasas(0),
            false,
            false,
            false,
        );
    }

    public function tributadasComo(SituacaoTributariaPisCofins $situacao): self
    {
        return $this->com(situacao: $situacao);
    }

    public function comPisCofins(Aliquota $pis, Aliquota $cofins): self
    {
        return $this->com(aliquotaDoPis: $pis, aliquotaDaCofins: $cofins);
    }

    public function comCsll(Aliquota $csll): self
    {
        return $this->com(aliquotaDaCsll: $csll);
    }

    public function comIrrf(Aliquota $irrf): self
    {
        return $this->com(aliquotaDoIrrf: $irrf);
    }

    public function comPrevidenciaria(Aliquota $previdenciaria): self
    {
        return $this->com(aliquotaPrevidenciaria: $previdenciaria);
    }

    /**
     * Quais das tres contribuicoes sociais o tomador retem. E resposta da
     * operacao, nao do cadastro: o mesmo prestador tem tomador que retem e
     * tomador que nao retem.
     */
    public function retidasPeloTomador(bool $pis, bool $cofins, bool $csll): self
    {
        return $this->com(retemPis: $pis, retemCofins: $cofins, retemCsll: $csll);
    }

    /**
     * Um so lugar reconstroi o objeto, como em `ValoresDoServico`. Os booleanos
     * nao usam `??` porque `false` e resposta, nao ausencia: `retidasPeloTomador`
     * manda os tres juntos, sempre.
     */
    private function com(
        ?SituacaoTributariaPisCofins $situacao = null,
        ?Aliquota $aliquotaDoPis = null,
        ?Aliquota $aliquotaDaCofins = null,
        ?Aliquota $aliquotaDaCsll = null,
        ?Aliquota $aliquotaDoIrrf = null,
        ?Aliquota $aliquotaPrevidenciaria = null,
        ?bool $retemPis = null,
        ?bool $retemCofins = null,
        ?bool $retemCsll = null,
    ): self {
        return new self(
            $this->baseDeCalculo,
            $this->valorBrutoDaNota,
            $situacao ?? $this->situacao,
            $aliquotaDoPis ?? $this->aliquotaDoPis,
            $aliquotaDaCofins ?? $this->aliquotaDaCofins,
            $aliquotaDaCsll ?? $this->aliquotaDaCsll,
            $aliquotaDoIrrf ?? $this->aliquotaDoIrrf,
            $aliquotaPrevidenciaria ?? $this->aliquotaPrevidenciaria,
            $retemPis ?? $this->retemPis,
            $retemCofins ?? $this->retemCofins,
            $retemCsll ?? $this->retemCsll,
        );
    }

    /**
     * O PIS devido na operacao, que e o que `vPis` quer dizer. Situacao sem
     * contribuicao a apurar devolve zero: aplicar a aliquota sobre uma operacao
     * isenta produziria um debito que nao existe.
     */
    public function pisDevido(): Dinheiro
    {
        if (! $this->situacao->temContribuicaoDevida()) {
            return Dinheiro::zero();
        }

        return $this->baseDeCalculo->multiplicarComArredondamentoBancario($this->aliquotaDoPis);
    }

    public function cofinsDevido(): Dinheiro
    {
        if (! $this->situacao->temContribuicaoDevida()) {
            return Dinheiro::zero();
        }

        return $this->baseDeCalculo->multiplicarComArredondamentoBancario($this->aliquotaDaCofins);
    }

    public function pisRetido(): Dinheiro
    {
        return $this->retemPis
            ? $this->baseDeCalculo->multiplicarComArredondamentoBancario($this->aliquotaDoPis)
            : Dinheiro::zero();
    }

    public function cofinsRetido(): Dinheiro
    {
        return $this->retemCofins
            ? $this->baseDeCalculo->multiplicarComArredondamentoBancario($this->aliquotaDaCofins)
            : Dinheiro::zero();
    }

    public function csllRetida(): Dinheiro
    {
        return $this->retemCsll
            ? $this->baseDeCalculo->multiplicarPor($this->aliquotaDaCsll)
            : Dinheiro::zero();
    }

    /**
     * `vRetCSLL`: a soma das tres contribuicoes sociais retidas. Quem le a nota
     * descobre a composicao pelo `tpRetPisCofins`, nao por este numero.
     */
    public function contribuicoesSociaisRetidas(): Dinheiro
    {
        return $this->pisRetido()
            ->somar($this->cofinsRetido())
            ->somar($this->csllRetida());
    }

    /**
     * IRRF e previdenciaria nao tem par "devido/retido" no leiaute: existe so o
     * campo do valor retido. Aliquota informada, portanto, e retencao.
     */
    public function irrfRetido(): Dinheiro
    {
        return $this->baseDeCalculo->multiplicarPor($this->aliquotaDoIrrf);
    }

    /**
     * Unica que incide sobre o bruto: o art. 31 da Lei 8.212/91 manda reter 11%
     * "do valor bruto da nota fiscal ou fatura".
     */
    public function previdenciariaRetida(): Dinheiro
    {
        return $this->valorBrutoDaNota->multiplicarPor($this->aliquotaPrevidenciaria);
    }

    public function abaixoDoPisoDaCsrf(): bool
    {
        return $this->baseDeCalculo->centavos <= self::CENTAVOS_DISPENSADOS_DA_CSRF;
    }

    public function tipoDeRetencao(): RetencaoDeContribuicoesSociais
    {
        return RetencaoDeContribuicoesSociais::deCombinacao($this->retemPis, $this->retemCofins, $this->retemCsll);
    }

    /**
     * Tudo que sai do liquido do prestador por retencao federal.
     */
    public function total(): Dinheiro
    {
        return $this->contribuicoesSociaisRetidas()
            ->somar($this->irrfRetido())
            ->somar($this->previdenciariaRetida());
    }

    /**
     * Nada a declarar: sem aliquota e sem retencao, o grupo inteiro so ocuparia
     * espaco no XML. Quem decide omiti-lo e `ValoresDoServico`.
     */
    public function estaZerado(): bool
    {
        return $this->aliquotaDoPis->ehZero()
            && $this->aliquotaDaCofins->ehZero()
            && $this->aliquotaDaCsll->ehZero()
            && $this->aliquotaDoIrrf->ehZero()
            && $this->aliquotaPrevidenciaria->ehZero();
    }

    /**
     * @return array<string, float|int|string>
     */
    public function paraApi(): array
    {
        return [
            'CST' => $this->situacao->value,
            'vBCPisCofins' => $this->baseDeCalculo->emReais(),
            'pAliqPis' => $this->aliquotaDoPis->percentual,
            'pAliqCofins' => $this->aliquotaDaCofins->percentual,
            'vPis' => $this->pisDevido()->emReais(),
            'vCofins' => $this->cofinsDevido()->emReais(),
            'tpRetPisCofins' => $this->tipoDeRetencao()->value,
            'vRetCP' => $this->previdenciariaRetida()->emReais(),
            'vRetIRRF' => $this->irrfRetido()->emReais(),
            'vRetCSLL' => $this->contribuicoesSociaisRetidas()->emReais(),
        ];
    }
}

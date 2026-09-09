<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\RetencaoDeContribuicoesSociais;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Dinheiro;
use App\Fiscal\Dps\RetencoesFederais;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O grupo `tribFed` como a NT 007 (7/2/2026) o deixou. O que estes testes
 * protegem e a distincao que a NT existiu para corrigir: `vPis` e `vCofins` sao
 * valores DEVIDOS, e o que o tomador RETEM das tres contribuicoes sociais vai
 * somado em `vRetCSLL`.
 *
 * Confundir os dois reduz indevidamente a base do IBS e da CBS, porque o inciso
 * V do parag. 2 do art. 12 da LC 214/2025 manda excluir esses tributos dessa
 * base. Foi exatamente o erro que a NT descreve.
 */
class RetencoesFederaisTest extends TestCase
{
    /**
     * Os percentuais da Lei 10.833/2003, art. 31, sobre mil reais: PIS 0,65%,
     * COFINS 3% e CSLL 1%, que juntos sao a CSRF de 4,65%. Sem desconto, as
     * duas bases coincidem.
     */
    private function sobreMilReais(): RetencoesFederais
    {
        return RetencoesFederais::sobre(Dinheiro::deReais(1000), Dinheiro::deReais(1000))
            ->comPisCofins(Aliquota::deQuatroCasas(0.65), Aliquota::deQuatroCasas(3))
            ->comCsll(Aliquota::deQuatroCasas(1))
            ->comIrrf(Aliquota::deQuatroCasas(1.5))
            ->comPrevidenciaria(Aliquota::deQuatroCasas(11));
    }

    /**
     * Sao duas bases porque a lei manda duas, e ate esta correcao havia so uma.
     *
     * A CSRF incide "sobre o montante a ser pago" (Lei 10.833/2003, art. 31,
     * caput) e o IRRF sobre "as importancias pagas ou creditadas" (RIR/2018,
     * art. 714): as duas descontam o incondicionado. A previdenciaria incide
     * sobre "o valor bruto da nota fiscal" (Lei 8.212/91, art. 31), e nao
     * desconta.
     *
     * O defeito que este teste tranca custava dinheiro do prestador: mil reais
     * com duzentos de desconto retinham 46,50 de CSRF e 15,00 de IRRF, contra
     * os 37,20 e 12,00 da lei.
     */
    public function test_a_csrf_e_o_irrf_descontam_o_incondicionado_e_a_previdenciaria_nao(): void
    {
        $retencoes = RetencoesFederais::sobre(Dinheiro::deReais(800), Dinheiro::deReais(1000))
            ->comPisCofins(Aliquota::deQuatroCasas(0.65), Aliquota::deQuatroCasas(3))
            ->comCsll(Aliquota::deQuatroCasas(1))
            ->comIrrf(Aliquota::deQuatroCasas(1.5))
            ->comPrevidenciaria(Aliquota::deQuatroCasas(11))
            ->retidasPeloTomador(pis: true, cofins: true, csll: true);

        $this->assertSame(37.2, $retencoes->contribuicoesSociaisRetidas()->emReais());
        $this->assertSame(12.0, $retencoes->irrfRetido()->emReais());
        $this->assertSame(110.0, $retencoes->previdenciariaRetida()->emReais());

        // `vBCPisCofins` segue a mesma base: desconto incondicional nao integra
        // a receita (Lei 10.833, art. 1, parag. 3, V, "a").
        $this->assertSame(800.0, $retencoes->paraApi()['vBCPisCofins']);
    }

    /**
     * A Lei 10.833/2003, art. 31, parag. 3, dispensa a retencao das tres
     * contribuicoes ate cinco mil reais. Quem decide se ela vale e quem emite,
     * porque o parag. 4 manda somar os pagamentos do mes e o acumulado nao esta
     * aqui: o sistema so precisa saber apontar o piso.
     */
    public function test_o_piso_de_dispensa_da_csrf_e_de_cinco_mil_reais(): void
    {
        $base = fn (float $reais): RetencoesFederais => RetencoesFederais::sobre(
            Dinheiro::deReais($reais),
            Dinheiro::deReais($reais),
        );

        $this->assertTrue($base(1000)->abaixoDoPisoDaCsrf());
        $this->assertTrue($base(5000)->abaixoDoPisoDaCsrf());
        $this->assertFalse($base(5000.01)->abaixoDoPisoDaCsrf());
    }

    public function test_as_tres_contribuicoes_sociais_retidas_vao_somadas_num_campo_so(): void
    {
        $retencoes = $this->sobreMilReais()->retidasPeloTomador(pis: true, cofins: true, csll: true);

        // 6,50 + 30,00 + 10,00: a CSRF de 4,65% que a guia recolhe junta.
        $this->assertSame(46.5, $retencoes->contribuicoesSociaisRetidas()->emReais());
        $this->assertSame(46.5, $retencoes->paraApi()['vRetCSLL']);
    }

    /**
     * O ponto inteiro da NT: reter nao zera o devido. Uma nota com PIS retido
     * continua declarando o PIS devido da operacao em `vPis`.
     */
    public function test_reter_nao_apaga_o_valor_devido(): void
    {
        $corpo = $this->sobreMilReais()
            ->retidasPeloTomador(pis: true, cofins: true, csll: true)
            ->paraApi();

        $this->assertSame(6.5, $corpo['vPis']);
        $this->assertSame(30.0, $corpo['vCofins']);
    }

    /**
     * E o contrario tambem: sem retencao nenhuma o devido continua la, porque
     * ele descreve a operacao, nao o que o tomador fez.
     */
    public function test_o_valor_devido_existe_mesmo_sem_retencao(): void
    {
        $corpo = $this->sobreMilReais()->paraApi();

        $this->assertSame(6.5, $corpo['vPis']);
        $this->assertSame(30.0, $corpo['vCofins']);
        $this->assertSame(0.0, $corpo['vRetCSLL']);
        $this->assertSame(RetencaoDeContribuicoesSociais::NenhumaRetida->value, $corpo['tpRetPisCofins']);
    }

    public function test_situacao_sem_contribuicao_a_apurar_nao_gera_valor_devido(): void
    {
        $corpo = $this->sobreMilReais()
            ->tributadasComo(SituacaoTributariaPisCofins::IsentaDaContribuicao)
            ->paraApi();

        $this->assertSame(0.0, $corpo['vPis']);
        $this->assertSame(0.0, $corpo['vCofins']);
        $this->assertSame('07', $corpo['CST']);
    }

    /**
     * As oito combinacoes de tres booleanos cabem nos codigos 0 e 3 a 9, sem
     * sobra e sem falta. Os codigos 1 e 2 sao os antigos, e a NT 007 avisa que
     * saem do schema: nada aqui os produz.
     */
    #[DataProvider('combinacoesDeRetencao')]
    public function test_o_codigo_descreve_quais_contribuicoes_foram_retidas(
        bool $pis,
        bool $cofins,
        bool $csll,
        RetencaoDeContribuicoesSociais $esperado,
    ): void {
        $retencoes = $this->sobreMilReais()->retidasPeloTomador($pis, $cofins, $csll);

        $this->assertSame($esperado, $retencoes->tipoDeRetencao());

        // Os codigos 1 e 2 sao os antigos, que a NT 007 marcou para sair do
        // schema. Nenhuma combinacao pode cair neles.
        $this->assertNotContains($retencoes->tipoDeRetencao()->value, [1, 2]);
    }

    /**
     * @return array<string, array{bool, bool, bool, RetencaoDeContribuicoesSociais}>
     */
    public static function combinacoesDeRetencao(): array
    {
        return [
            'nenhuma' => [false, false, false, RetencaoDeContribuicoesSociais::NenhumaRetida],
            'as tres' => [true, true, true, RetencaoDeContribuicoesSociais::TodasRetidas],
            'pis e cofins' => [true, true, false, RetencaoDeContribuicoesSociais::PisCofinsRetidos],
            'so pis' => [true, false, false, RetencaoDeContribuicoesSociais::ApenasPisRetido],
            'so cofins' => [false, true, false, RetencaoDeContribuicoesSociais::ApenasCofinsRetido],
            'cofins e csll' => [false, true, true, RetencaoDeContribuicoesSociais::CofinsCsllRetidos],
            'so csll' => [false, false, true, RetencaoDeContribuicoesSociais::ApenasCsllRetida],
            'pis e csll' => [true, false, true, RetencaoDeContribuicoesSociais::PisCsllRetidos],
        ];
    }

    /**
     * IRRF e previdenciaria nao tem par devido/retido no leiaute: so existe o
     * campo do valor retido. Aliquota informada, portanto, ja e retencao, e nao
     * depende de marcacao nenhuma.
     */
    public function test_irrf_e_previdenciaria_saem_sem_precisar_de_marcacao(): void
    {
        $corpo = $this->sobreMilReais()->paraApi();

        $this->assertSame(15.0, $corpo['vRetIRRF']);
        $this->assertSame(110.0, $corpo['vRetCP']);
    }

    public function test_o_total_e_tudo_que_sai_do_liquido(): void
    {
        $retencoes = $this->sobreMilReais()->retidasPeloTomador(pis: true, cofins: true, csll: true);

        // 46,50 de contribuicoes sociais + 15,00 de IRRF + 110,00 de INSS.
        $this->assertSame(171.5, $retencoes->total()->emReais());
    }

    /**
     * A NT 007 fixou arredondamento bancario (half-even) para `vPis` e
     * `vCofins`. Meio centavo desce quando o centavo anterior e par, em vez de
     * subir sempre: 2,5 centavos viram 2, nao 3.
     *
     * A aliquota de 0,5% nao e a de nenhum tributo real; ela esta aqui porque
     * cai exatamente no meio, que e o unico ponto onde os dois arredondamentos
     * discordam.
     */
    public function test_pis_e_cofins_arredondam_pelo_metodo_bancario(): void
    {
        $corpo = RetencoesFederais::sobre(Dinheiro::deReais(5), Dinheiro::deReais(5))
            ->comPisCofins(Aliquota::deQuatroCasas(0.5), Aliquota::deQuatroCasas(0.5))
            ->paraApi();

        $this->assertSame(0.02, $corpo['vPis']);

        // O arredondamento comum, que o ISSQN continua usando, daria 0,03.
        $this->assertSame(
            0.03,
            Dinheiro::deReais(5)->multiplicarPor(Aliquota::deQuatroCasas(0.5))->emReais(),
        );
    }

    /**
     * Sem aliquota nenhuma nao ha o que declarar, e o grupo inteiro sai do
     * JSON: o `semVazios()` do ConstrutorDps poda o nulo.
     */
    public function test_sem_aliquota_nenhuma_o_grupo_esta_zerado(): void
    {
        $this->assertTrue(RetencoesFederais::sobre(Dinheiro::deReais(1000), Dinheiro::deReais(1000))->estaZerado());
        $this->assertFalse($this->sobreMilReais()->estaZerado());
    }

    /**
     * A base do PIS e da COFINS e a receita do servico. Deducao e beneficio
     * municipal reduzem a base do ISSQN, que e regra do municipio, e nao
     * alcancam tributo federal.
     */
    public function test_a_base_declarada_e_a_que_foi_recebida(): void
    {
        $corpo = $this->sobreMilReais()->paraApi();

        $this->assertSame(1000.0, $corpo['vBCPisCofins']);
    }
}

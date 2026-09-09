<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Domain\Enums\TributacaoIssqn;
use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Dinheiro;
use App\Fiscal\Dps\BeneficioMunicipal;
use App\Fiscal\Dps\ExigibilidadeSuspensa;
use App\Fiscal\Dps\TotaisAproximados;
use App\Fiscal\Dps\ValoresDoServico;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sobe o Laravel, ao contrario dos demais testes de `tests/Unit`: as mensagens
 * passam por `__()`, e o helper precisa do container.
 */
class ValoresDoServicoTest extends TestCase
{
    public function test_calcula_o_issqn_sobre_a_base_liquida(): void
    {
        $valores = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comDeducoes(Dinheiro::deReais(100))
            ->comDescontos(Dinheiro::deReais(50), Dinheiro::zero());

        $this->assertSame(850.0, $valores->baseDeCalculo()->emReais());
        $this->assertSame(42.5, $valores->issqnDevido()->emReais());
    }

    public function test_nao_ha_issqn_quando_a_operacao_nao_e_tributavel(): void
    {
        $valores = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->tributadosComo(TributacaoIssqn::Imunidade, RetencaoIssqn::NaoRetido);

        $this->assertTrue($valores->issqnDevido()->ehZero());
    }

    public function test_retencao_pelo_tomador_desconta_o_liquido(): void
    {
        $valores = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->tributadosComo(TributacaoIssqn::OperacaoTributavel, RetencaoIssqn::RetidoPeloTomador);

        $this->assertSame(950.0, $valores->valorLiquido()->emReais());
    }

    public function test_o_corpo_da_api_leva_os_atalhos_e_o_grupo_detalhado(): void
    {
        $corpo = ValoresDoServico::cobrados(Dinheiro::deReais(1500.5), Aliquota::deQuatroCasas(5))->paraApi();

        $this->assertSame(1500.5, $corpo['vServ']);
        $this->assertSame(5.0, $corpo['pAliq']);
        $this->assertSame(2, $corpo['iss_retido']);
        $this->assertSame(
            ['tribISSQN' => 1, 'tpRetISSQN' => 1, 'pAliq' => 5.0],
            $corpo['tribMun'],
        );
    }

    /**
     * Os atalhos de `valores` sao o que um provedor ABRASF le, o Padrao
     * Nacional usa `tribMun`. Sem assercao aqui, trocar `vDeducoes` por
     * `vDescCond` passava batido, e o erro so apareceria no municipio que
     * ninguem testa.
     */
    public function test_os_atalhos_abrasf_levam_cada_abatimento_no_seu_campo(): void
    {
        $corpo = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comDeducoes(Dinheiro::deReais(100))
            ->comDescontos(Dinheiro::deReais(50), Dinheiro::deReais(25))
            ->tributadosComo(TributacaoIssqn::ExportacaoDeServico, RetencaoIssqn::RetidoPeloTomador)
            ->paraApi();

        $this->assertSame(1000.0, $corpo['vServ']);
        $this->assertSame(100.0, $corpo['vDeducoes']);
        $this->assertSame(50.0, $corpo['vDescIncond']);
        $this->assertSame(25.0, $corpo['vDescCond']);
        $this->assertSame(TributacaoIssqn::ExportacaoDeServico->value, $corpo['tribISSQN']);
        $this->assertSame(1, $corpo['iss_retido'], 'Retido tem que sair como 1 no atalho ABRASF.');
    }

    /**
     * Sem retencao o liquido NAO desconta o ISSQN: quem recolhe e o prestador,
     * e ele recebe o valor cheio. Trocar isso muda o que o tomador paga.
     */
    public function test_sem_retencao_o_liquido_nao_desconta_o_issqn(): void
    {
        $valores = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comDescontos(Dinheiro::deReais(50), Dinheiro::zero());

        $this->assertSame(950.0, $valores->valorLiquido()->emReais());
        $this->assertSame(47.5, $valores->issqnDevido()->emReais());
    }

    /**
     * Os dois descontos saem do liquido; so o incondicionado sai da base. E o
     * `vLiq` do Padrao Nacional, e e o que separa "quanto o fisco cobra" de
     * "quanto o tomador paga".
     */
    public function test_os_dois_descontos_saem_do_liquido_e_so_um_sai_da_base(): void
    {
        $valores = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comDescontos(Dinheiro::deReais(50), Dinheiro::deReais(25));

        $this->assertSame(950.0, $valores->baseDeCalculo()->emReais(), 'O condicionado nao entra na base.');
        $this->assertSame(47.5, $valores->issqnDevido()->emReais());
        $this->assertSame(925.0, $valores->valorLiquido()->emReais());
    }

    /**
     * Com retencao o ISSQN tambem sai: quem recolhe e o tomador, e ele desconta
     * do que paga ao prestador.
     */
    public function test_com_retencao_o_liquido_perde_os_descontos_e_o_issqn(): void
    {
        $valores = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comDescontos(Dinheiro::deReais(50), Dinheiro::deReais(25))
            ->tributadosComo(TributacaoIssqn::OperacaoTributavel, RetencaoIssqn::RetidoPeloTomador);

        $this->assertSame(925.0 - 47.5, $valores->valorLiquido()->emReais());
    }

    public function test_acusa_quando_os_abatimentos_passam_do_servico(): void
    {
        $dentro = ValoresDoServico::cobrados(Dinheiro::deReais(100), Aliquota::deQuatroCasas(5))
            ->comDeducoes(Dinheiro::deReais(60))
            ->comDescontos(Dinheiro::deReais(40), Dinheiro::zero());

        $fora = ValoresDoServico::cobrados(Dinheiro::deReais(100), Aliquota::deQuatroCasas(5))
            ->comDeducoes(Dinheiro::deReais(60))
            ->comDescontos(Dinheiro::deReais(41), Dinheiro::zero());

        $this->assertFalse($dentro->abatimentosPassamDoServico(), 'Zerar a base e limite, nao excesso.');
        $this->assertTrue($fora->abatimentosPassamDoServico());
    }

    public function test_dinheiro_nao_arrasta_erro_de_ponto_flutuante(): void
    {
        $soma = Dinheiro::deReais(0.1)->somar(Dinheiro::deReais(0.2));

        $this->assertSame(30, $soma->centavos);
        $this->assertSame(0.3, $soma->emReais());
    }

    /**
     * O `tribMun` e o grupo que o Padrao Nacional le. Suspensao e beneficio
     * entram nele por spread, e um spread que se perde nao quebra nada aqui:
     * quebra na prefeitura, que recebe a nota sem o amparo legal da suspensao
     * ou sem a reducao que o contribuinte tem direito.
     */
    public function test_o_grupo_tribmun_carrega_a_suspensao_e_o_beneficio(): void
    {
        $tribMun = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comExigibilidadeSuspensa(ExigibilidadeSuspensa::por(
                TipoSuspensaoDeExigibilidade::DecisaoJudicial,
                '0001234-56.2026.8.19.0001',
            ))
            ->comBeneficioMunicipal(BeneficioMunicipal::concedido('BM-2026-1', Aliquota::deQuatroCasas(20)))
            ->paraApi()['tribMun'];

        $this->assertSame([
            'tribISSQN' => 1,
            'tpRetISSQN' => 1,
            'pAliq' => 5.0,
            'tpSusp' => 1,
            'nProcesso' => '0001234-56.2026.8.19.0001',
            'nBM' => 'BM-2026-1',
            'pRedBCBM' => 20.0,
        ], $tribMun);
    }

    public function test_o_grupo_de_totais_so_sai_quando_ha_algum(): void
    {
        $semTotais = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))->paraApi();

        $comTotais = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comTotaisAproximados(new TotaisAproximados(
                federais: Dinheiro::deReais(120),
                estaduais: Dinheiro::zero(),
                municipais: Dinheiro::deReais(50),
            ))
            ->paraApi();

        $this->assertNull($semTotais['totTrib']);
        $this->assertSame(
            ['vTotTribFed' => 120.0, 'vTotTribEst' => 0.0, 'vTotTribMun' => 50.0],
            $comTotais['totTrib'],
        );
    }

    /**
     * Um unico total preenchido ja e a Lei da Transparencia cumprida. Exigir os
     * tres para o grupo existir apagaria o dado que o contribuinte informou.
     */
    public function test_um_total_sozinho_ja_faz_o_grupo_existir(): void
    {
        $this->assertTrue(new TotaisAproximados(Dinheiro::zero(), Dinheiro::zero(), Dinheiro::zero())->estaZerado());

        $this->assertFalse(new TotaisAproximados(Dinheiro::deReais(1), Dinheiro::zero(), Dinheiro::zero())->estaZerado());
        $this->assertFalse(new TotaisAproximados(Dinheiro::zero(), Dinheiro::deReais(1), Dinheiro::zero())->estaZerado());
        $this->assertFalse(new TotaisAproximados(Dinheiro::zero(), Dinheiro::zero(), Dinheiro::deReais(1))->estaZerado());
    }

    public function test_o_dado_novo_substitui_o_anterior(): void
    {
        $tribMun = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comExigibilidadeSuspensa(ExigibilidadeSuspensa::por(TipoSuspensaoDeExigibilidade::DecisaoJudicial, 'PROC-1'))
            ->comBeneficioMunicipal(BeneficioMunicipal::concedido('BM-1', Aliquota::deQuatroCasas(10)))
            ->comExigibilidadeSuspensa(ExigibilidadeSuspensa::por(TipoSuspensaoDeExigibilidade::ProcessoAdministrativo, 'PROC-2'))
            ->comBeneficioMunicipal(BeneficioMunicipal::concedido('BM-2', Aliquota::deQuatroCasas(30)))
            ->paraApi()['tribMun'];

        $this->assertSame(2, $tribMun['tpSusp']);
        $this->assertSame('PROC-2', $tribMun['nProcesso']);
        $this->assertSame('BM-2', $tribMun['nBM']);
        $this->assertSame(30.0, $tribMun['pRedBCBM']);
    }

    public function test_os_totais_aproximados_tambem_aceitam_correcao(): void
    {
        $corpo = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->comTotaisAproximados(new TotaisAproximados(Dinheiro::deReais(1), Dinheiro::zero(), Dinheiro::zero()))
            ->comTotaisAproximados(new TotaisAproximados(Dinheiro::deReais(120), Dinheiro::zero(), Dinheiro::deReais(50)))
            ->paraApi();

        $this->assertSame(
            ['vTotTribFed' => 120.0, 'vTotTribEst' => 0.0, 'vTotTribMun' => 50.0],
            $corpo['totTrib'],
        );
    }

    /**
     * Suspensao e beneficio nascem aparados e nao existem pela metade: sem
     * numero de processo nao ha decisao que ampare, e sem numero do beneficio
     * nao ha o que a prefeitura concedeu.
     */
    public function test_suspensao_e_beneficio_exigem_o_identificador(): void
    {
        $this->assertSame(
            'PROC-1',
            ExigibilidadeSuspensa::por(TipoSuspensaoDeExigibilidade::DecisaoJudicial, '  PROC-1  ')->numeroDoProcesso,
        );
        $this->assertSame('BM-1', BeneficioMunicipal::concedido('  BM-1  ', Aliquota::deQuatroCasas(10))->numero);

        $this->expectException(InvalidArgumentException::class);

        ExigibilidadeSuspensa::por(TipoSuspensaoDeExigibilidade::DecisaoJudicial, '   ');
    }

    public function test_o_beneficio_sem_numero_nao_existe(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BeneficioMunicipal::concedido('   ', Aliquota::deQuatroCasas(10));
    }

    /**
     * O centavo do imposto se arredonda, nao se trunca nem se arredonda sempre
     * para cima. Em nota de R$ 333,33 a 5% a diferenca e um centavo, e um
     * centavo a menos no ISSQN e nota rejeitada pelo provedor.
     */
    public function test_o_issqn_arredonda_o_centavo_em_vez_de_truncar(): void
    {
        $paraCima = ValoresDoServico::cobrados(Dinheiro::deReais(333.33), Aliquota::deQuatroCasas(5));
        $paraBaixo = ValoresDoServico::cobrados(Dinheiro::deReais(100.10), Aliquota::deQuatroCasas(3));

        $this->assertSame(16.67, $paraCima->issqnDevido()->emReais());
        $this->assertSame(3.0, $paraBaixo->issqnDevido()->emReais());
    }

    /**
     * O grupo `totTrib` é uma escolha no leiaute, não uma soma: `vTotTrib` e
     * `pTotTrib` são formas alternativas da mesma declaração, e mandando as
     * duas a wrapper escreve só a segunda, em silêncio. Este projeto declara em
     * valor, que é o que a lei pede e o que o DANFSe imprime.
     */
    public function test_os_totais_saem_em_valor_e_nunca_nas_duas_formas(): void
    {
        $corpo = (new TotaisAproximados(
            federais: Dinheiro::deReais(134.50),
            estaduais: Dinheiro::zero(),
            municipais: Dinheiro::deReais(29.50),
        ))->paraApi();

        $this->assertSame(['vTotTribFed' => 134.5, 'vTotTribEst' => 0.0, 'vTotTribMun' => 29.5], $corpo);
        $this->assertArrayNotHasKey('pTotTribFed', $corpo);
    }

    /**
     * Quem está no Simples declara um percentual só, e nenhum dos três valores.
     * Separar a guia única em federal, estadual e municipal seria inventar um
     * rateio que a lei do Simples não faz: o DAS reúne IRPJ, CSLL, PIS, COFINS,
     * CPP e o próprio ISS.
     */
    public function test_o_simples_nacional_declara_um_percentual_so(): void
    {
        $corpo = TotaisAproximados::doSimplesNacional(Aliquota::deQuatroCasas(6.54))->paraApi();

        $this->assertSame(['pTotTribSN' => 6.54], $corpo);
        $this->assertArrayNotHasKey('vTotTribFed', $corpo);
    }

    /**
     * Alíquota zerada não declara nada, como os três valores zerados do outro
     * caminho: o grupo inteiro sai do JSON em vez de afirmar que não há tributo
     * embutido no preço.
     */
    public function test_aliquota_do_simples_zerada_nao_declara_nada(): void
    {
        $this->assertTrue(TotaisAproximados::doSimplesNacional(Aliquota::deQuatroCasas(0))->estaZerado());
        $this->assertFalse(TotaisAproximados::doSimplesNacional(Aliquota::deQuatroCasas(6.54))->estaZerado());
    }

    /**
     * Exportacao, nao incidencia e imunidade nao tem aliquota. O `issqnDevido()`
     * ja devolvia zero nas tres, mas o `pAliq` seguia saindo com o percentual do
     * cadastro: o XML de uma operacao imune declarava 5% de um imposto que nao
     * existe ali. O `semVazios()` do ConstrutorDps poda o nulo.
     */
    #[DataProvider('tributacoesSemImposto')]
    public function test_operacao_sem_issqn_nao_declara_aliquota(TributacaoIssqn $tributacao): void
    {
        $corpo = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))
            ->tributadosComo($tributacao, RetencaoIssqn::NaoRetido)
            ->paraApi();

        $this->assertNull($corpo['pAliq']);
        $this->assertNull($corpo['tribMun']['pAliq']);
    }

    /**
     * @return array<string, array{TributacaoIssqn}>
     */
    public static function tributacoesSemImposto(): array
    {
        return [
            'exportação' => [TributacaoIssqn::ExportacaoDeServico],
            'não incidência' => [TributacaoIssqn::NaoIncidencia],
            'imunidade' => [TributacaoIssqn::Imunidade],
        ];
    }

    public function test_operacao_tributavel_continua_declarando_a_aliquota(): void
    {
        $corpo = ValoresDoServico::cobrados(Dinheiro::deReais(1000), Aliquota::deQuatroCasas(5))->paraApi();

        $this->assertSame(5.0, $corpo['pAliq']);
        $this->assertSame(5.0, $corpo['tribMun']['pAliq']);
    }
}

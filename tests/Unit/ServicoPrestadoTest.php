<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Dps\ServicoPrestado;
use PHPUnit\Framework\TestCase;

/**
 * `infDPS.serv`. Sao cinco campos e nenhum deles e intercambiavel: `cServ` e o
 * codigo de tributacao nacional, `itemListaServico` e o da lista ABRASF, e o
 * provedor le um ou outro conforme o municipio. Trocar um pelo outro nao
 * quebra nada aqui, quebra na prefeitura.
 */
class ServicoPrestadoTest extends TestCase
{
    public function test_o_servico_leva_municipio_codigo_descricao_cnae_e_item(): void
    {
        $servico = ServicoPrestado::prestadoEm(
            CodigoIbge::deSeteDigitos('3304557'),
            '010701',
            'Desenvolvimento de software',
        )
            ->comCnae('6201501')
            ->comItemDaListaDeServico('01.04');

        $this->assertSame([
            'cMunPrestacao' => '3304557',
            'cServ' => '010701',
            'cTribMun' => '',
            'xDescServ' => 'Desenvolvimento de software',
            'codigoCnae' => '6201501',
            'itemListaServico' => '01.04',
            'cNBS' => '',
            'municipioIncidencia' => null,
        ], $servico->paraApi());
    }

    public function test_o_municipio_de_incidencia_do_issqn_vai_a_parte_do_da_prestacao(): void
    {
        $servico = ServicoPrestado::prestadoEm(CodigoIbge::deSeteDigitos('3550308'), '010701', 'Consultoria')
            ->comIssqnDevidoEm(CodigoIbge::deSeteDigitos('3518800'));

        $this->assertSame('3550308', $servico->paraApi()['cMunPrestacao']);
        $this->assertSame('3518800', $servico->paraApi()['municipioIncidencia']);
    }

    public function test_sem_cnae_nem_item_os_campos_ficam_vazios_para_serem_podados(): void
    {
        $servico = ServicoPrestado::prestadoEm(
            CodigoIbge::deSeteDigitos('3550308'),
            '010701',
            'Consultoria',
        );

        $this->assertSame('', $servico->paraApi()['codigoCnae']);
        $this->assertSame('', $servico->paraApi()['itemListaServico']);
        $this->assertSame('', $servico->paraApi()['cNBS']);
    }

    public function test_o_dado_novo_substitui_o_anterior(): void
    {
        $servico = ServicoPrestado::prestadoEm(CodigoIbge::deSeteDigitos('3304557'), '010701', 'Serviço')
            ->comCnae('6201501')
            ->comItemDaListaDeServico('01.04')
            ->comCnae('6202300')
            ->comItemDaListaDeServico('01.05');

        $this->assertSame('6202300', $servico->paraApi()['codigoCnae']);
        $this->assertSame('01.05', $servico->paraApi()['itemListaServico']);
    }
}

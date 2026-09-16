<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\TributacaoIssqn;
use App\Domain\Notas\LocalDeIncidenciaDoIssqn;
use App\Domain\ValueObjects\CodigoIbge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O art. 3º da LC 116/2003 com tres municipios diferentes, para cada desfecho
 * apontar um so: prestador, local da prestacao e tomador, cada um num
 * municipio.
 */
class LocalDeIncidenciaDoIssqnTest extends TestCase
{
    private const PRESTADOR = '3518800';

    private const PRESTACAO = '3509502';

    private const TOMADOR = '3550308';

    #[DataProvider('servicos')]
    public function test_o_subitem_decide_onde_o_issqn_e_devido(string $codigoDoServico, string $esperado): void
    {
        $this->assertSame($esperado, (string) $this->incidencia($codigoDoServico, TributacaoIssqn::OperacaoTributavel));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function servicos(): array
    {
        return [
            'software fica no prestador' => ['010701', self::PRESTADOR],
            'obra vai para a prestação' => ['070201', self::PRESTACAO],
            'guarda de veículo vai para a prestação' => ['110101', self::PRESTACAO],
            'item 12 inteiro vai para a prestação' => ['120101', self::PRESTACAO],
            'produção de evento, o 12.13, fica no prestador' => ['121301', self::PRESTADOR],
            'transporte municipal vai para a prestação' => ['160101', self::PRESTACAO],
            'feira e congresso vão para a prestação' => ['171001', self::PRESTACAO],
            'mão de obra vai para o tomador' => ['170501', self::TOMADOR],
            'porto vai para a prestação' => ['200101', self::PRESTACAO],
            'plano de saúde fica no prestador desde as ADI 5835 e 5862' => ['042201', self::PRESTADOR],
            'leasing fica no prestador desde as ADI 5835 e 5862' => ['150901', self::PRESTADOR],
        ];
    }

    /**
     * O ABRASF so pede o campo com o imposto exigivel. Exportacao, nao
     * incidencia e imunidade nao tem municipio onde o ISSQN seja devido.
     */
    #[DataProvider('tributacoesSemIssqn')]
    public function test_sem_issqn_devido_nao_ha_municipio_de_incidencia(TributacaoIssqn $tributacao): void
    {
        $this->assertNull($this->incidencia('010701', $tributacao));
    }

    /**
     * @return array<string, array{TributacaoIssqn}>
     */
    public static function tributacoesSemIssqn(): array
    {
        return [
            'exportação' => [TributacaoIssqn::ExportacaoDeServico],
            'não incidência' => [TributacaoIssqn::NaoIncidencia],
            'imunidade' => [TributacaoIssqn::Imunidade],
        ];
    }

    private function incidencia(string $codigoDoServico, TributacaoIssqn $tributacao): ?CodigoIbge
    {
        return LocalDeIncidenciaDoIssqn::doServico(
            $codigoDoServico,
            $tributacao,
            CodigoIbge::deSeteDigitos(self::PRESTADOR),
            CodigoIbge::deSeteDigitos(self::PRESTACAO),
            CodigoIbge::deSeteDigitos(self::TOMADOR),
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ValueObjects\Aliquota;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A aliquota do ISSQN. A API a recebe em percentual (`5` = 5%), com quatro
 * casas, e sao as quatro casas que decidem o imposto de uma nota grande.
 */
class AliquotaTest extends TestCase
{
    public function test_guarda_quatro_casas_decimais(): void
    {
        $this->assertSame(1.2346, Aliquota::deQuatroCasas('1.23456')->percentual);
        $this->assertSame(2.5, Aliquota::deQuatroCasas(2.5)->percentual);
    }

    /**
     * 0 e 100 sao aliquotas: a faixa e fechada nos dois lados. Recusar as
     * pontas tiraria do ar a operacao isenta e a que nao existe em municipio
     * nenhum, mas o cadastro nao e quem decide isso.
     */
    #[DataProvider('aliquotasNaFaixa')]
    public function test_aceita_as_pontas_da_faixa(float $percentual): void
    {
        $this->assertSame($percentual, Aliquota::deQuatroCasas($percentual)->percentual);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function aliquotasNaFaixa(): array
    {
        return [
            'zero' => [0.0],
            'cem' => [100.0],
            'usual' => [5.0],
        ];
    }

    #[DataProvider('aliquotasForaDaFaixa')]
    public function test_recusa_fora_da_faixa(float|string $percentual): void
    {
        $this->expectException(InvalidArgumentException::class);

        Aliquota::deQuatroCasas($percentual);
    }

    /**
     * @return array<string, array{float|string}>
     */
    public static function aliquotasForaDaFaixa(): array
    {
        return [
            'abaixo de zero' => [-0.0001],
            'acima de cem' => [100.0001],
            'texto' => ['cinco por cento'],
        ];
    }

    public function test_a_fracao_e_o_que_multiplica_a_base(): void
    {
        $this->assertSame(0.05, Aliquota::deQuatroCasas(5)->emFracao());
    }

    /**
     * `formatada()` e o que a tela mostra; `__toString()` e o que viaja. Um usa
     * virgula e por cento, o outro ponto e nada, e sao as duas metades da
     * mesma confusao quando se troca uma pela outra.
     */
    public function test_a_tela_le_com_virgula_e_a_api_com_ponto(): void
    {
        $aliquota = Aliquota::deQuatroCasas(12.5);

        $this->assertSame('12,50%', $aliquota->formatada());
        $this->assertSame('12.50', (string) $aliquota);
    }

    public function test_zero_se_reconhece(): void
    {
        $this->assertTrue(Aliquota::deQuatroCasas(0)->ehZero());
        $this->assertFalse(Aliquota::deQuatroCasas(0.0001)->ehZero());
    }
}

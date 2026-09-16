<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Consultas\EstadoDoFormulario;
use App\Domain\ValueObjects\Dinheiro;
use App\Filament\Schemas\Campos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mascara escreve "1.500,50"; o banco guarda "1500.50". As duas escritas
 * circulam no mesmo formulario, e ler uma como a outra troca o valor da nota
 * por mil vezes ele.
 */
class MascaraMonetariaTest extends TestCase
{
    #[DataProvider('escritas')]
    public function test_le_as_duas_escritas(string|float|int|null $entrada, float $esperado): void
    {
        $this->assertSame($esperado, Dinheiro::deTexto($entrada)->emReais());
    }

    /**
     * @return array<string, array{string|float|int|null, float}>
     */
    public static function escritas(): array
    {
        return [
            'mascarado com milhar' => ['1.500,50', 1500.50],
            'mascarado sem milhar' => ['1500,50', 1500.50],
            'milhao mascarado' => ['1.234.567,89', 1234567.89],
            'cru do banco' => ['1500.50', 1500.50],
            'milhar sem centavos' => ['1.500', 1500.0],
            'milhao sem centavos' => ['1.500.000', 1500000.0],
            'inteiro em texto' => ['1500', 1500.0],
            'float' => [1500.5, 1500.50],
            'inteiro' => [1500, 1500.0],
            'nulo' => [null, 0.0],
            'vazio' => ['', 0.0],
            'lixo' => ['abc', 0.0],
        ];
    }

    /**
     * Sem virgula, ponto e decimal, menos quando separa grupos de exatamente
     * tres digitos: essa forma so sai da mascara, nunca do banco. A validacao
     * do formulario ja aceitava "1.500"; era o parser que lia 1,50 e mandava
     * uma nota de mil e quinhentos reais para o provedor valendo um e meio.
     */
    public function test_o_ponto_e_decimal_menos_quando_separa_grupos_de_tres(): void
    {
        // "5.0000" vem do banco com quatro casas: nao e milhar.
        $this->assertSame(5.0, Dinheiro::deTexto('5.0000')->emReais());
        $this->assertSame(5000.0, Dinheiro::deTexto('5.000,00')->emReais());

        $this->assertSame(1500.0, Dinheiro::deTexto('1.500')->emReais());
        $this->assertSame(1.5, Dinheiro::deTexto('1.5')->emReais());
        $this->assertSame(1.05, Dinheiro::deTexto('1.05')->emReais());
    }

    public function test_o_estado_do_formulario_usa_o_mesmo_parser(): void
    {
        $estado = ['valor_servico' => '1.500,50', 'aliquota_iss' => '2,75'];

        $this->assertSame(1500.50, EstadoDoFormulario::numero($estado, 'valor_servico'));
        $this->assertSame(2.75, EstadoDoFormulario::numero($estado, 'aliquota_iss'));
    }

    /**
     * O outro sentido da máscara, e o que quebrou de verdade: preencher um
     * campo por código.
     *
     * `formatStateUsing` só roda na hidratação. Um `$set()` numérico chega cru
     * ao navegador, e daí quem manda é a máscara, que lê `0.65` como dígitos e
     * mostra `65`. Quem preenche por botão precisa mandar o texto já formatado,
     * e é isso que `comoPercentual()` e `comoMoeda()` produzem.
     */
    #[DataProvider('numerosQueAMascaraQuebrava')]
    public function test_o_texto_para_preencher_campo_mascarado(
        float|int $numero,
        string $percentual,
        string $moeda,
    ): void {
        $this->assertSame($percentual, Campos::comoPercentual($numero));
        $this->assertSame($moeda, Campos::comoMoeda($numero));
    }

    /**
     * @return array<string, array{float|int, string, string}>
     */
    public static function numerosQueAMascaraQuebrava(): array
    {
        return [
            'PIS de 0,65% virava 65%' => [0.65, '0,65', '0,65'],
            'IRRF de 1,5% virava 15%' => [1.5, '1,5', '1,50'],
            'uma casa decimal, que a máscara de dinheiro deslocava' => [150.5, '150,5', '150,50'],
            'com milhar: o percentual não usa ponto nem aí' => [1500.5, '1500,5', '1.500,50'],
            'inteiro passava ileso, e é por isso que 3, 1 e 11 pareciam certos' => [3, '3', '3,00'],
        ];
    }

    /**
     * O caminho de volta tem que fechar: o que o botão escreve, a gravação lê.
     * Sem isto o campo mostraria certo e salvaria errado, que é pior.
     */
    #[DataProvider('numerosQueAMascaraQuebrava')]
    public function test_o_que_o_botao_escreve_a_gravacao_le_de_volta(
        float|int $numero,
        string $percentual,
        string $moeda,
    ): void {
        $this->assertSame((float) $numero, Dinheiro::numeroDoTexto($percentual));
        $this->assertSame((float) $numero, Dinheiro::numeroDoTexto($moeda));
    }
}

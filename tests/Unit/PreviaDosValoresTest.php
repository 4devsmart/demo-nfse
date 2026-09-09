<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Consultas\PreviaDosValores;
use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\TributacaoIssqn;
use PHPUnit\Framework\TestCase;

class PreviaDosValoresTest extends TestCase
{
    public function test_calcula_o_que_o_formulario_mostra_enquanto_se_digita(): void
    {
        $previa = PreviaDosValores::doFormulario([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'deducoes' => 100,
            'desconto_incondicionado' => 50,
            'tributacao_issqn' => TributacaoIssqn::OperacaoTributavel->value,
            'retencao_issqn' => RetencaoIssqn::RetidoPeloTomador->value,
        ]);

        $this->assertSame(850.0, $previa->baseDeCalculo->emReais());
        $this->assertSame(42.5, $previa->issqn->emReais());
        $this->assertSame(907.5, $previa->liquido->emReais());
    }

    public function test_formulario_pela_metade_nao_e_erro(): void
    {
        $previa = PreviaDosValores::doFormulario(['valor_servico' => null, 'aliquota_iss' => '']);

        $this->assertTrue($previa->baseDeCalculo->ehZero());
        $this->assertTrue($previa->issqn->ehZero());
        $this->assertTrue($previa->liquido->ehZero());
    }

    public function test_valor_absurdo_nao_derruba_a_tela(): void
    {
        $previa = PreviaDosValores::doFormulario(['valor_servico' => 1000, 'aliquota_iss' => 999]);

        $this->assertTrue($previa->issqn->ehZero());
    }
}

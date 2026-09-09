<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Consultas\EstadoDoFormulario;
use App\Domain\Enums\Ambiente;
use App\Domain\Enums\TributacaoIssqn;
use PHPUnit\Framework\TestCase;

/**
 * O estado de um formulario Filament nao tem tipo fixo: o mesmo campo chega
 * como enum quando vem do default ou do banco, e como string crua quando vem
 * do que foi digitado.
 *
 * Quem le esse estado (a previa dos valores, a revisao da nota) faz conta com
 * ele. Ler `TributacaoIssqn::Imunidade` como zero, ou `'2,75'` como dois,
 * muda o imposto que a tela mostra antes de emitir.
 */
class EstadoDoFormularioTest extends TestCase
{
    public function test_o_inteiro_le_enum_texto_e_numero(): void
    {
        $estado = [
            'tributacao_issqn' => TributacaoIssqn::Imunidade,
            'retencao_issqn' => '2',
            'tipo_suspensao' => 1,
        ];

        $this->assertSame(4, EstadoDoFormulario::inteiro($estado, 'tributacao_issqn'));
        $this->assertSame(2, EstadoDoFormulario::inteiro($estado, 'retencao_issqn'));
        $this->assertSame(1, EstadoDoFormulario::inteiro($estado, 'tipo_suspensao'));
    }

    public function test_o_inteiro_ausente_cai_no_padrao_e_o_padrao_e_zero(): void
    {
        $this->assertSame(0, EstadoDoFormulario::inteiro([], 'tributacao_issqn'));
        $this->assertSame(1, EstadoDoFormulario::inteiro([], 'tributacao_issqn', 1));
        $this->assertSame(9, EstadoDoFormulario::inteiro(['x' => 'nao e numero'], 'x', 9));
    }

    /**
     * Campo em branco significa "nao respondido", nao zero. Quem pergunta com um
     * padrao proprio precisa recebe-lo de volta, e nao um zero que a tela
     * mostraria como valor.
     */
    public function test_o_numero_em_branco_devolve_o_padrao_e_nao_zero(): void
    {
        $this->assertSame(9.5, EstadoDoFormulario::numero(['valor' => '   '], 'valor', 9.5));
        $this->assertSame(9.5, EstadoDoFormulario::numero(['valor' => ''], 'valor', 9.5));
        $this->assertSame(9.5, EstadoDoFormulario::numero([], 'valor', 9.5));
        $this->assertSame(9.5, EstadoDoFormulario::numero(['valor' => ['nao', 'e', 'escalar']], 'valor', 9.5));
    }

    public function test_o_numero_aceita_float_e_inteiro_direto(): void
    {
        $this->assertSame(1500.5, EstadoDoFormulario::numero(['valor' => 1500.5], 'valor'));
        $this->assertSame(1500.0, EstadoDoFormulario::numero(['valor' => 1500], 'valor'));
    }

    public function test_o_texto_apara_o_que_foi_digitado_e_le_o_valor_do_enum(): void
    {
        $estado = [
            'descricao_servico' => '  Manutenção de ar-condicionado  ',
            'ambiente' => Ambiente::Producao,
            'numero' => 42,
            'lista' => ['nao', 'e', 'escalar'],
        ];

        $this->assertSame('Manutenção de ar-condicionado', EstadoDoFormulario::texto($estado, 'descricao_servico'));
        $this->assertSame('producao', EstadoDoFormulario::texto($estado, 'ambiente'));
        $this->assertSame('42', EstadoDoFormulario::texto($estado, 'numero'));
        $this->assertSame('', EstadoDoFormulario::texto($estado, 'lista'));
        $this->assertSame('', EstadoDoFormulario::texto($estado, 'ausente'));
    }
}

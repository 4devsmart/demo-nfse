<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Notas\TributacaoRespondida;
use PHPUnit\Framework\TestCase;

/**
 * Tres situacoes que o mesmo array precisa distinguir, e que custam caro se
 * forem confundidas: "o usuario disse nao", "o usuario disse sim" e "ninguem
 * perguntou nada".
 */
class TributacaoRespondidaTest extends TestCase
{
    public function test_nao_apaga_as_colunas_que_a_pergunta_governa(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_suspensao' => false,
            'tipo_suspensao' => 1,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
        ]);

        $this->assertNull($dados['tipo_suspensao']);
        $this->assertNull($dados['numero_processo_suspensao']);
    }

    /**
     * O outro lado, e o que mais importa: um "sim" nao pode apagar nada. Sem
     * este teste, tirar a guarda que protege esse ramo passaria despercebido,
     * e toda nota com suspensao perderia a suspensao ao ser salva.
     */
    public function test_sim_preserva_o_que_foi_preenchido(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_suspensao' => true,
            'tipo_suspensao' => 1,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
            'tem_beneficio' => true,
            'numero_beneficio_municipal' => 'BM-2026-77',
            'percentual_reducao_base' => 20,
        ]);

        $this->assertSame(1, $dados['tipo_suspensao']);
        $this->assertSame('0001234-56.2026.8.19.0001', $dados['numero_processo_suspensao']);
        $this->assertSame('BM-2026-77', $dados['numero_beneficio_municipal']);
        $this->assertSame(20, $dados['percentual_reducao_base']);
    }

    /**
     * Atualizacao parcial (de um job, de um comando) nao responde pergunta
     * nenhuma, e nao pode apagar o que nao mencionou.
     */
    public function test_pergunta_ausente_nao_mexe_em_nada(): void
    {
        $dados = TributacaoRespondida::aplicadaEm(['descricao_servico' => 'Outra coisa']);

        $this->assertSame(['descricao_servico' => 'Outra coisa'], $dados);
    }

    public function test_a_retencao_volta_para_nao_retido_em_vez_de_zerar(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_retencao' => false,
            'retencao_issqn' => RetencaoIssqn::RetidoPeloTomador->value,
        ]);

        $this->assertSame(RetencaoIssqn::NaoRetido->value, $dados['retencao_issqn']);
    }

    public function test_as_perguntas_nao_seguem_para_o_model(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_retencao' => false,
            'tem_suspensao' => false,
            'tem_beneficio' => false,
            'tem_totais' => false,
            'descricao_servico' => 'Consultoria',
        ]);

        foreach (['tem_retencao', 'tem_suspensao', 'tem_beneficio', 'tem_totais'] as $pergunta) {
            $this->assertArrayNotHasKey($pergunta, $dados);
        }

        $this->assertSame('Consultoria', $dados['descricao_servico']);
    }

    public function test_os_totais_aproximados_somem_de_uma_vez(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_totais' => false,
            'total_tributos_federais' => 75.01,
            'total_tributos_estaduais' => 1.0,
            'total_tributos_municipais' => 75.03,
        ]);

        $this->assertNull($dados['total_tributos_federais']);
        $this->assertNull($dados['total_tributos_estaduais']);
        $this->assertNull($dados['total_tributos_municipais']);
    }

    /**
     * As aliquotas de retencao federal nasceram `NOT NULL` com padrao zero.
     * Apaga-las com `null`, como as demais colunas, seria erro de banco: o
     * "Nao" delas zera.
     */
    public function test_nao_zera_as_aliquotas_federais_em_vez_de_anula_las(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_retencao_federal' => false,
            'cst_pis_cofins' => '01',
            'aliquota_pis' => 0.65,
            'aliquota_cofins' => 3,
            'aliquota_csll' => 1,
            'aliquota_irrf' => 1.5,
            'aliquota_previdenciaria' => 11,
        ]);

        $this->assertNull($dados['cst_pis_cofins']);

        foreach (['pis', 'cofins', 'csll', 'irrf', 'previdenciaria'] as $tributo) {
            $this->assertSame(0, $dados["aliquota_{$tributo}"]);
        }
    }

    /**
     * A lista marcada na tela vira as tres colunas booleanas. E delas que sai o
     * `tpRetPisCofins` e a soma do `vRetCSLL`.
     */
    public function test_a_lista_marcada_vira_as_tres_colunas(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_retencao_federal' => true,
            'contribuicoes_retidas' => ['pis', 'csll'],
        ]);

        $this->assertTrue($dados['retem_pis']);
        $this->assertFalse($dados['retem_cofins']);
        $this->assertTrue($dados['retem_csll']);
        $this->assertArrayNotHasKey('contribuicoes_retidas', $dados);
    }

    /**
     * O "Nao" tem a ultima palavra sobre a lista. Campo escondido nao chega ao
     * `$data`, entao sem esta regra a nota manteria a retencao que o usuario
     * acabou de tirar, e a DPS seguinte sairia com um `vRetCSLL` que ninguem
     * mandou.
     */
    public function test_nao_desmarca_as_contribuicoes_mesmo_sem_a_lista_no_data(): void
    {
        $dados = TributacaoRespondida::aplicadaEm(['tem_retencao_federal' => false]);

        $this->assertFalse($dados['retem_pis']);
        $this->assertFalse($dados['retem_cofins']);
        $this->assertFalse($dados['retem_csll']);
    }

    /**
     * O "não" nos totais aproximados apaga os dois caminhos, e não só o que
     * estava visível: o formulário mostra os três valores OU a alíquota do
     * Simples, conforme o regime do emitente, e trocar de emitente depois de
     * responder deixaria o outro caminho gravado.
     */
    public function test_nao_apaga_os_dois_caminhos_dos_totais_aproximados(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_totais' => false,
            'total_tributos_federais' => 134.50,
            'percentual_simples_nacional' => 6.54,
        ]);

        $this->assertNull($dados['total_tributos_federais']);
        $this->assertNull($dados['percentual_simples_nacional']);
    }

    public function test_nao_apaga_o_grupo_inteiro_de_ibs_cbs(): void
    {
        $dados = TributacaoRespondida::aplicadaEm([
            'tem_ibs_cbs' => false,
            'cst_ibs_cbs' => '000',
            'indicador_de_operacao' => '020201',
            'classificacao_tributaria' => '000001',
            'codigo_credito_presumido' => '000010',
        ]);

        $this->assertNull($dados['cst_ibs_cbs']);
        $this->assertNull($dados['indicador_de_operacao']);
        $this->assertNull($dados['classificacao_tributaria']);
        $this->assertNull($dados['codigo_credito_presumido']);
    }

    public function test_pergunta_ausente_nao_mexe_em_retencao_federal_nenhuma(): void
    {
        $dados = TributacaoRespondida::aplicadaEm(['aliquota_pis' => 0.65, 'retem_pis' => true]);

        $this->assertSame(0.65, $dados['aliquota_pis']);
        $this->assertTrue($dados['retem_pis']);
    }
}

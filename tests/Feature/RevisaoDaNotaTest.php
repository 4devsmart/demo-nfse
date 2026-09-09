<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servicos\ImportarCodigosDeTributacaoNacional;
use App\Actions\Servicos\ImportarListaDeServicos;
use App\Consultas\RevisaoDaNota;
use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Domain\Enums\TributacaoIssqn;
use App\Models\Cidade;
use App\Models\Cliente;
use App\Models\Empresa;
use Database\Factories\CidadeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A última etapa antes de emitir: é onde quem emite confere o que vai para a
 * prefeitura, com o imposto já calculado, e onde decide apertar o botão. Um
 * rótulo trocado aqui não quebra nada, faz alguém confirmar uma nota que não é
 * a que leu.
 *
 * Por isso os testes conferem o texto exato, e não só a presença da chave.
 */
class RevisaoDaNotaTest extends TestCase
{
    use RefreshDatabase;

    private RevisaoDaNota $revisao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->revisao = app(RevisaoDaNota::class);
    }

    public function test_o_prestador_aparece_com_documento_inscricao_e_municipio(): void
    {
        $empresa = Empresa::factory()->create([
            'razao_social' => 'Estúdio de Software LTDA',
            'cnpj' => '19131243000197',
            'inscricao_municipal' => '1234567',
        ]);

        $prestador = $this->revisao->pessoas(['empresa_id' => $empresa->getKey()])[0];

        $this->assertSame('Prestador', $prestador['rotulo']);
        $this->assertSame('Estúdio de Software LTDA', $prestador['valor']);
        $this->assertSame('19.131.243/0001-97 · IM 1234567 · Rio de Janeiro/RJ', $prestador['apoio']);
    }

    public function test_o_prestador_sem_inscricao_municipal_nao_mostra_o_rotulo_vazio(): void
    {
        $empresa = Empresa::factory()->create([
            'cnpj' => '19131243000197',
            'inscricao_municipal' => null,
        ]);

        $prestador = $this->revisao->pessoas(['empresa_id' => $empresa->getKey()])[0];

        $this->assertSame('19.131.243/0001-97 · Rio de Janeiro/RJ', $prestador['apoio']);
    }

    /**
     * O tomador nao leva inscricao municipal, mesmo quando o cadastro tem uma:
     * quem a declara na DPS e o prestador.
     */
    public function test_o_tomador_aparece_com_documento_e_municipio(): void
    {
        $cliente = Cliente::factory()->create([
            'razao_social' => 'Comércio Exemplo S.A.',
            'cpf_cnpj' => '45543915000181',
            'inscricao_municipal' => '7654321',
        ]);

        $tomador = $this->revisao->pessoas(['cliente_id' => $cliente->getKey()])[1];

        $this->assertSame('Tomador', $tomador['rotulo']);
        $this->assertSame('Comércio Exemplo S.A.', $tomador['valor']);
        $this->assertSame('45.543.915/0001-81 · Rio de Janeiro/RJ', $tomador['apoio']);
    }

    /**
     * O assistente monta a revisao a cada passo, inclusive antes de escolherem
     * as partes. Faltando, o bloco continua na tela dizendo que falta.
     */
    public function test_sem_as_partes_escolhidas_os_dois_blocos_continuam_na_tela(): void
    {
        $blocos = $this->revisao->pessoas([]);

        $this->assertCount(2, $blocos);
        $this->assertSame('—', $blocos[0]['valor']);
        $this->assertSame('', $blocos[0]['apoio']);
        $this->assertSame('—', $blocos[1]['valor']);
        $this->assertSame('', $blocos[1]['apoio']);
    }

    public function test_o_servico_separa_a_grade_da_discriminacao(): void
    {
        $servico = $this->revisao->servico([
            'competencia' => '2026-09-15',
            'cidade_prestacao_id' => CidadeFactory::rio()->getKey(),
            'codigo_servico' => '010701',
            'cnae' => '6201501',
            'item_lista_servico' => '01.07',
            'descricao_servico' => 'Desenvolvimento de software sob encomenda',
        ]);

        $this->assertSame([
            'Competência' => '09/2026',
            'Município da prestação' => 'Rio de Janeiro/RJ',
            'Código do serviço' => '010701',
            'CNAE' => '6201501',
            'Item da lista (ABRASF)' => '01.07',
        ], $servico['campos']);

        $this->assertSame('Desenvolvimento de software sob encomenda', $servico['discriminacao']);
    }

    /**
     * Na revisao os vizinhos do cartao, indicador da operacao e classificacao,
     * ja apareciam como "codigo · descricao". O codigo do servico aparecia como
     * numero seco, e e o mais importante dos tres na ultima tela antes de
     * emitir: seis digitos nao dizem que servico e aquele.
     *
     * A descricao vem cortada curta de proposito: a grade e de colunas de 9rem,
     * e o corte do seletor renderia seis linhas numa celula.
     */
    public function test_o_codigo_do_servico_aparece_com_a_descricao(): void
    {
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();

        $campos = $this->revisao->servico(['codigo_servico' => '010701'])['campos'];

        $this->assertSame('010701 · Suporte técnico em informática, inclusiv...', $campos['Código do serviço']);
    }

    /**
     * Codigo que a tabela nao conhece cai no texto cru: e o que esta gravado, e
     * esconde-lo seria pior que mostra-lo sem explicacao.
     */
    public function test_o_codigo_desconhecido_aparece_como_esta_gravado(): void
    {
        $campos = $this->revisao->servico(['codigo_servico' => '04.02'])['campos'];

        $this->assertSame('04.02', $campos['Código do serviço']);
    }

    /**
     * O rotulo da busca de cidades vem "Rio de Janeiro/RJ, 3304557". O codigo
     * IBGE serve para escolher; na conferencia ele so ocupa espaco.
     */
    public function test_o_municipio_da_prestacao_perde_o_codigo_ibge_grudado(): void
    {
        $curitiba = Cidade::factory()->create(['nome' => 'Curitiba', 'uf' => 'PR', 'codigo_ibge' => '4106902']);

        $campos = $this->revisao->servico(['cidade_prestacao_id' => $curitiba->getKey()])['campos'];

        $this->assertSame('Curitiba/PR', $campos['Município da prestação']);
    }

    /**
     * Competencia e municipio nunca somem: sao obrigatorios, e a linha vazia e
     * o que avisa que faltam. CNAE e item da lista somem, sao opcionais, e
     * "—" repetido so afasta o olho do que importa.
     */
    public function test_campo_opcional_vazio_some_e_obrigatorio_vira_travessao(): void
    {
        $campos = $this->revisao->servico(['codigo_servico' => '010701'])['campos'];

        $this->assertSame([
            'Competência' => '—',
            'Município da prestação' => '—',
            'Código do serviço' => '010701',
        ], $campos);
    }

    public function test_a_tributacao_mostra_so_o_que_foi_escolhido(): void
    {
        $linhas = $this->revisao->tributacao([
            'tributacao_issqn' => TributacaoIssqn::OperacaoTributavel->value,
            'retencao_issqn' => RetencaoIssqn::NaoRetido->value,
        ]);

        $this->assertSame([
            'Tributação do ISSQN' => 'Operação tributável',
            'Retenção' => 'Não retido',
        ], $linhas);
    }

    public function test_a_suspensao_aparece_com_o_tipo_e_o_processo(): void
    {
        $linhas = $this->revisao->tributacao([
            'tributacao_issqn' => TributacaoIssqn::OperacaoTributavel->value,
            'retencao_issqn' => RetencaoIssqn::RetidoPeloTomador->value,
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::DecisaoJudicial->value,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
        ]);

        $this->assertSame('Retido pelo tomador', $linhas['Retenção']);
        $this->assertSame(
            'Suspensa por decisão judicial · processo 0001234-56.2026.8.19.0001',
            $linhas['Exigibilidade'],
        );
    }

    public function test_o_beneficio_aparece_com_o_numero_e_a_reducao(): void
    {
        $linhas = $this->revisao->tributacao([
            'numero_beneficio_municipal' => 'BM-2026-77',
            'percentual_reducao_base' => 20,
        ]);

        $this->assertSame('BM-2026-77 · reduz 20,00% da base', $linhas['Benefício municipal']);
    }

    public function test_tributacao_e_retencao_desconhecidas_viram_travessao(): void
    {
        $linhas = $this->revisao->tributacao([]);

        $this->assertSame('—', $linhas['Tributação do ISSQN']);
        $this->assertSame('—', $linhas['Retenção']);
    }

    /**
     * A conta na ordem em que ela e feita, com o papel de cada linha: e o papel
     * que a view usa para destacar base, imposto e liquido.
     */
    public function test_a_previa_lista_a_conta_inteira_na_ordem(): void
    {
        $linhas = $this->revisao->previa([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'deducoes' => 100,
            'desconto_incondicionado' => 50,
            'desconto_condicionado' => 25,
        ]);

        $this->assertSame([
            ['rotulo' => 'Valor do serviço', 'valor' => 'R$ 1.000,00', 'papel' => ''],
            ['rotulo' => '− Deduções', 'valor' => 'R$ 100,00', 'papel' => ''],
            ['rotulo' => '− Desconto incondicionado', 'valor' => 'R$ 50,00', 'papel' => ''],
            ['rotulo' => 'Base de cálculo', 'valor' => 'R$ 850,00', 'papel' => 'destaque'],
            ['rotulo' => 'Alíquota aplicada', 'valor' => '5,00%', 'papel' => ''],
            ['rotulo' => 'ISSQN', 'valor' => 'R$ 42,50', 'papel' => 'destaque'],
            ['rotulo' => '− Desconto condicionado', 'valor' => 'R$ 25,00', 'papel' => ''],
            ['rotulo' => 'Valor líquido da NFS-e', 'valor' => 'R$ 925,00', 'papel' => 'total'],
        ], $linhas);
    }

    /**
     * Abatimento zerado nao vira linha: "R$ 0,00" repetido tres vezes so afasta
     * o olho da base e do imposto, que e o que se confere aqui.
     */
    public function test_abatimento_zerado_nao_vira_linha(): void
    {
        $linhas = $this->revisao->previa([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'deducoes' => 0,
            'desconto_incondicionado' => 0,
            'desconto_condicionado' => 0,
        ]);

        $this->assertSame(
            ['Valor do serviço', 'Base de cálculo', 'Alíquota aplicada', 'ISSQN', 'Valor líquido da NFS-e'],
            array_column($linhas, 'rotulo'),
        );
    }

    /**
     * O desconto condicionado NAO sai da base, so o incondicionado sai. Trocar
     * um pelo outro muda o imposto devido sem mudar nada na aparencia da tela.
     */
    /**
     * O condicionado é o único abatimento que aparece nas duas pontas com
     * pesos diferentes: sai do líquido, porque é dinheiro que o tomador não
     * paga, e não sai da base, porque não muda o imposto devido. Trocar um pelo
     * outro dá nota com ISSQN errado ou com líquido errado, e os dois erros
     * são invisíveis até alguém conferir contra o XML autorizado.
     */
    public function test_o_desconto_condicionado_sai_do_liquido_mas_nao_da_base(): void
    {
        $linhas = $this->revisao->previa([
            'valor_servico' => 1000,
            'aliquota_iss' => 10,
            'desconto_condicionado' => 400,
        ]);

        $porRotulo = array_column($linhas, 'valor', 'rotulo');

        $this->assertSame('R$ 1.000,00', $porRotulo['Base de cálculo']);
        $this->assertSame('R$ 100,00', $porRotulo['ISSQN']);
        $this->assertSame('R$ 600,00', $porRotulo['Valor líquido da NFS-e']);

        // A posição na lista é a explicação: acima da base, o abatimento diria
        // que reduz o imposto, e não reduz.
        $this->assertSame(
            ['Valor do serviço', 'Base de cálculo', 'Alíquota aplicada', 'ISSQN', '− Desconto condicionado', 'Valor líquido da NFS-e'],
            array_column($linhas, 'rotulo'),
        );
    }

    /**
     * Com retenção o ISSQN aparece duas vezes: como imposto apurado e como
     * abatimento do líquido. É a única forma de a conta fechar de cima para
     * baixo. Sem a segunda linha, o leitor vê R$ 50,00 de imposto logo acima de
     * um líquido R$ 50,00 menor e não sabe se é o mesmo dinheiro.
     */
    public function test_a_retencao_desconta_o_issqn_do_liquido(): void
    {
        $linhas = $this->revisao->previa([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'retencao_issqn' => RetencaoIssqn::RetidoPeloTomador->value,
        ]);

        $this->assertSame([
            ['rotulo' => 'Valor do serviço', 'valor' => 'R$ 1.000,00', 'papel' => ''],
            ['rotulo' => 'Base de cálculo', 'valor' => 'R$ 1.000,00', 'papel' => 'destaque'],
            ['rotulo' => 'Alíquota aplicada', 'valor' => '5,00%', 'papel' => ''],
            ['rotulo' => 'ISSQN', 'valor' => 'R$ 50,00', 'papel' => 'destaque'],
            ['rotulo' => '− ISSQN retido pelo tomador', 'valor' => 'R$ 50,00', 'papel' => ''],
            ['rotulo' => 'Valor líquido da NFS-e', 'valor' => 'R$ 950,00', 'papel' => 'total'],
        ], $linhas);
    }

    public function test_o_intermediario_e_nomeado_na_linha_da_retencao(): void
    {
        $linhas = $this->revisao->previa([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'retencao_issqn' => RetencaoIssqn::RetidoPeloIntermediario->value,
        ]);

        $this->assertContains('− ISSQN retido pelo intermediário', array_column($linhas, 'rotulo'));
    }

    /**
     * Sem retenção não há linha nenhuma: quem recolhe é o prestador, e ele
     * recebe o valor cheio. A ausência é o que diz isso.
     */
    public function test_sem_retencao_o_issqn_nao_vira_abatimento(): void
    {
        $linhas = $this->revisao->previa(['valor_servico' => 1000, 'aliquota_iss' => 5]);

        $this->assertSame(
            ['Valor do serviço', 'Base de cálculo', 'Alíquota aplicada', 'ISSQN', 'Valor líquido da NFS-e'],
            array_column($linhas, 'rotulo'),
        );
        $this->assertSame('R$ 1.000,00', array_column($linhas, 'valor', 'rotulo')['Valor líquido da NFS-e']);
    }

    /**
     * O formulario entrega o que foi digitado com a mascara brasileira, e a
     * revisao le isso sem passar pelo banco.
     */
    public function test_a_previa_le_o_valor_mascarado_do_formulario(): void
    {
        $linhas = $this->revisao->previa(['valor_servico' => '1.500,50', 'aliquota_iss' => '2,75']);

        $porRotulo = array_column($linhas, 'valor', 'rotulo');

        $this->assertSame('R$ 1.500,50', $porRotulo['Valor do serviço']);
        $this->assertSame('2,75%', $porRotulo['Alíquota aplicada']);
        $this->assertSame('R$ 41,26', $porRotulo['ISSQN']);
    }

    /**
     * As retenções federais aparecem uma a uma, e não como o total somado que a
     * DPS envia. Na DPS, PIS, COFINS e CSLL retidos vão juntos em `vRetCSLL`
     * por exigência da NT 007; na tela isso seria uma linha que ninguém
     * confere, porque não bate com nenhuma alíquota que a pessoa digitou.
     */
    public function test_cada_retencao_federal_vira_uma_linha_da_conta(): void
    {
        $linhas = $this->revisao->previa([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'cst_pis_cofins' => '01',
            'aliquota_pis' => 0.65,
            'aliquota_cofins' => 3,
            'aliquota_csll' => 1,
            'aliquota_irrf' => 1.5,
            'aliquota_previdenciaria' => 0,
            'contribuicoes_retidas' => ['pis', 'cofins', 'csll'],
        ]);

        $this->assertSame([
            ['rotulo' => 'Valor do serviço', 'valor' => 'R$ 1.000,00', 'papel' => ''],
            ['rotulo' => 'Base de cálculo', 'valor' => 'R$ 1.000,00', 'papel' => 'destaque'],
            ['rotulo' => 'Alíquota aplicada', 'valor' => '5,00%', 'papel' => ''],
            ['rotulo' => 'ISSQN', 'valor' => 'R$ 50,00', 'papel' => 'destaque'],
            ['rotulo' => '− PIS retido', 'valor' => 'R$ 6,50', 'papel' => ''],
            ['rotulo' => '− COFINS retido', 'valor' => 'R$ 30,00', 'papel' => ''],
            ['rotulo' => '− CSLL retida', 'valor' => 'R$ 10,00', 'papel' => ''],
            ['rotulo' => '− IRRF retido', 'valor' => 'R$ 15,00', 'papel' => ''],
            ['rotulo' => 'Valor líquido da NFS-e', 'valor' => 'R$ 938,50', 'papel' => 'total'],
        ], $linhas);
    }

    /**
     * Alíquota preenchida não é retenção: o cadastro do emitente traz os
     * percentuais, e é a nota que diz o que o tomador reteve. Sem nada marcado,
     * PIS, COFINS e CSLL continuam devidos pelo prestador e não saem do líquido.
     */
    public function test_contribuicao_nao_marcada_nao_sai_do_liquido(): void
    {
        $linhas = $this->revisao->previa([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'cst_pis_cofins' => '01',
            'aliquota_pis' => 0.65,
            'aliquota_cofins' => 3,
            'aliquota_csll' => 1,
            'contribuicoes_retidas' => [],
        ]);

        $rotulos = array_column($linhas, 'rotulo');

        $this->assertNotContains('− PIS retido', $rotulos);
        $this->assertContains(
            ['rotulo' => 'Valor líquido da NFS-e', 'valor' => 'R$ 1.000,00', 'papel' => 'total'],
            $linhas,
        );
    }

    /**
     * O código do `tpRetPisCofins` por extenso, que é o que diz quais das três
     * contribuições compõem o `vRetCSLL` somado.
     */
    public function test_a_tributacao_nomeia_a_combinacao_de_retencao_federal(): void
    {
        $linhas = $this->revisao->tributacao([
            'tributacao_issqn' => TributacaoIssqn::OperacaoTributavel->value,
            'retencao_issqn' => RetencaoIssqn::NaoRetido->value,
            'cst_pis_cofins' => '01',
            'aliquota_pis' => 0.65,
            'aliquota_cofins' => 3,
            'contribuicoes_retidas' => ['pis', 'cofins'],
        ]);

        $this->assertSame('01 · Operação tributável com alíquota básica', $linhas['CST do PIS/COFINS']);
        $this->assertSame('PIS/COFINS retidos, CSLL não retido', $linhas['Retenção federal']);
    }

    /**
     * Sem retenção federal nenhuma as duas linhas somem: repetir "não retido"
     * não informa nada, e a ausência já diz o que precisa ser dito.
     */
    public function test_sem_retencao_federal_as_linhas_nao_aparecem(): void
    {
        $linhas = $this->revisao->tributacao([
            'tributacao_issqn' => TributacaoIssqn::OperacaoTributavel->value,
            'retencao_issqn' => RetencaoIssqn::NaoRetido->value,
        ]);

        $this->assertArrayNotHasKey('CST do PIS/COFINS', $linhas);
        $this->assertArrayNotHasKey('Retenção federal', $linhas);
    }

    /**
     * O campo escondido não perde o valor: com "não" na pergunta, as alíquotas
     * continuam no estado do formulário, herdadas do cadastro do emitente. A
     * prévia tem que enxergar a resposta, e não só os campos.
     *
     * Sem esta guarda a tela descontava um IRRF de 1,5% que
     * `TributacaoRespondida` zera na gravação: o líquido mostrado não era o
     * líquido da nota.
     */
    public function test_nao_na_retencao_federal_ignora_as_aliquotas_herdadas(): void
    {
        $estado = [
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'aliquota_irrf' => 1.5,
            'aliquota_pis' => 0.65,
            'aliquota_cofins' => 3,
            'contribuicoes_retidas' => [],
        ];

        $comResposta = $this->revisao->previa([...$estado, 'tem_retencao_federal' => false]);
        $semPergunta = $this->revisao->previa($estado);

        $this->assertContains(
            ['rotulo' => 'Valor líquido da NFS-e', 'valor' => 'R$ 1.000,00', 'papel' => 'total'],
            $comResposta,
        );

        // Pergunta ausente é atualização parcial, não "não": aí as alíquotas
        // valem, como em qualquer outro caminho que não passa pelo formulário.
        $this->assertContains(
            ['rotulo' => '− IRRF retido', 'valor' => 'R$ 15,00', 'papel' => ''],
            $semPergunta,
        );
    }

    /**
     * Mesma armadilha do outro lado da tela: com "não" no IBS/CBS, o CST e a
     * classificação continuam no estado, vindos do emitente, e a revisão
     * mostraria uma declaração que a nota não vai carregar.
     */
    public function test_nao_no_ibs_cbs_esconde_a_classificacao_herdada(): void
    {
        $estado = [
            'tributacao_issqn' => TributacaoIssqn::OperacaoTributavel->value,
            'retencao_issqn' => RetencaoIssqn::NaoRetido->value,
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => '100301',
        ];

        $linhas = $this->revisao->tributacao([...$estado, 'tem_ibs_cbs' => false]);

        $this->assertArrayNotHasKey('IBS/CBS', $linhas);
        $this->assertArrayNotHasKey('Indicador da operação', $linhas);
    }
}

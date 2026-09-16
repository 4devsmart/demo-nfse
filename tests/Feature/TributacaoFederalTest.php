<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\PreverXmlDaDps;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Domain\Enums\StatusNota;
use App\Domain\Enums\TipoPessoa;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Traducao\MontadorDaDps;
use App\Models\Cidade;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * O que a nota grava vira `valores.trib.tribFed` e `infDPS.ibscbs` no corpo que
 * a API fiscal recebe.
 *
 * A build de 7/9/2026 da wrapper-api (commit e941b53) passou a escrever o grupo
 * `tribFed` no XML, aninhando `piscofins` como o leiaute manda. Ate a build de
 * 24/8 ela aceitava o campo no JSON e o descartava na geracao, e por isso este
 * arquivo existia sem cobrir o outro lado da fronteira.
 *
 * Estes testes continuam cobrindo so o nosso lado, que e o que a suite alcanca
 * sem depender de container no ar. Do outro lado, a conferencia e ler o XML.
 */
class TributacaoFederalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function valoresDaDps(array $atributos): array
    {
        $nota = Nota::factory()->create([...['valor_servico' => 1000, 'aliquota_iss' => 5], ...$atributos]);

        return app(MontadorDaDps::class)->montar($nota)->paraApi()['infDPS'];
    }

    /**
     * @return array<string, mixed>
     */
    private function comRetencoesCheias(): array
    {
        return [
            'cst_pis_cofins' => '01',
            'aliquota_pis' => 0.65,
            'aliquota_cofins' => 3,
            'aliquota_csll' => 1,
            'aliquota_irrf' => 1.5,
            'aliquota_previdenciaria' => 11,
            'retem_pis' => true,
            'retem_cofins' => true,
            'retem_csll' => true,
        ];
    }

    public function test_o_grupo_federal_vai_no_corpo_com_os_campos_da_nt_007(): void
    {
        $infDps = $this->valoresDaDps($this->comRetencoesCheias());

        $this->assertSame([
            'CST' => '01',
            'vBCPisCofins' => 1000.0,
            'pAliqPis' => 0.65,
            'pAliqCofins' => 3.0,
            'vPis' => 6.5,
            'vCofins' => 30.0,
            'tpRetPisCofins' => 3,
            'vRetCP' => 110.0,
            'vRetIRRF' => 15.0,
            'vRetCSLL' => 46.5,
        ], $infDps['valores']['tribFed']);
    }

    /**
     * A nota comum nao tem retencao federal nenhuma, e ai o grupo inteiro sai
     * do JSON. Mandar `tribFed` com dez zeros seria declarar o que nao existe.
     */
    public function test_sem_aliquota_nenhuma_o_grupo_federal_nao_vai(): void
    {
        $infDps = $this->valoresDaDps([]);

        $this->assertArrayNotHasKey('tribFed', $infDps['valores']);
    }

    public function test_as_retencoes_federais_saem_do_liquido(): void
    {
        $nota = Nota::factory()->create([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            ...$this->comRetencoesCheias(),
        ]);

        // 1000 − 46,50 de contribuicoes sociais − 15 de IRRF − 110 de INSS.
        $this->assertSame(828.5, $nota->valoresDoServico()->valorLiquido()->emReais());
    }

    public function test_a_classificacao_de_ibs_cbs_vai_no_grupo_proprio(): void
    {
        $infDps = $this->valoresDaDps([
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => '020201',
        ]);

        $this->assertSame([
            'cIndOp' => '020201',
            'indFinal' => '0',
            'gIBSCBS' => [
                'CST' => '000',
                'cClassTrib' => '000001',
            ],
        ], $infDps['ibscbs']);
    }

    /**
     * O `cLocalidadeIncid` vai no lado da NFS-e do grupo, que e onde o gravador
     * do GISS 2.04 o procura para escrever dentro do RPS.
     */
    public function test_a_localidade_de_incidencia_vai_no_lado_da_nfse(): void
    {
        $guarulhos = Cidade::factory()->create(['nome' => 'Guarulhos', 'uf' => 'SP', 'codigo_ibge' => '3518800']);

        $infDps = $this->valoresDaDps([
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => '020201',
            'cidade_incidencia_ibs_cbs_id' => $guarulhos->getKey(),
        ]);

        $this->assertSame(
            ['cLocalidadeIncid' => '3518800', 'xLocalidadeIncid' => 'Guarulhos'],
            $infDps['ibscbs']['nfse'],
        );
    }

    /**
     * O grupo IBS/CBS e tudo ou nada, e a razao e o XML que a wrapper gera:
     * faltando o `cIndOp`, ela escreve `<cIndOp></cIndOp>` vazio em vez de
     * omitir o elemento. Elemento vazio e pior que grupo ausente, porque a
     * DPS passa a afirmar algo que ninguem declarou.
     */
    public function test_classificacao_incompleta_nao_declara_o_grupo(): void
    {
        $infDps = $this->valoresDaDps([
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => null,
        ]);

        $this->assertArrayNotHasKey('ibscbs', $infDps);
    }

    /**
     * O par dos testes de `TributacaoMunicipalTest`: responder "não" numa nota
     * que já tem retenção gravada precisa limpar as colunas, ou a DPS seguinte
     * sai com a retenção que o usuário acabou de tirar, e retenção federal a
     * mais é dinheiro que o tomador segura sem dever.
     */
    public function test_responder_nao_apaga_as_retencoes_federais_gravadas(): void
    {
        $this->actingAs(User::factory()->create());
        $nota = Nota::factory()->create([...$this->comRetencoesCheias(), 'valor_servico' => 1000, 'aliquota_iss' => 5]);

        $this->assertNotNull($nota->retencoesFederais());

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['tem_retencao_federal' => true])
            // Responder "sim" de novo não pode mexer no que já está lá: a
            // limpeza é da resposta "não", e só dela.
            ->fillForm(['tem_retencao_federal' => 1])
            ->assertFormSet(['cst_pis_cofins' => '01'])
            ->fillForm(['tem_retencao_federal' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $nota->refresh();
        $this->assertNull($nota->cst_pis_cofins);
        $this->assertNull($nota->retencoesFederais());
        $this->assertSame(1000.0, $nota->valoresDoServico()->valorLiquido()->emReais());
        $this->assertArrayNotHasKey('tribFed', app(MontadorDaDps::class)->montar($nota)->paraApi()['infDPS']['valores']);
    }

    /**
     * O mesmo do outro grupo. Aqui o preço de não limpar é diferente: a
     * classificação é tudo ou nada, então sobrar um dos três campos faria a
     * nota seguinte declarar um grupo pela metade.
     */
    public function test_responder_nao_apaga_a_classificacao_de_ibs_cbs_gravada(): void
    {
        $this->actingAs(User::factory()->create());
        $nota = Nota::factory()->create([
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => '020201',
        ]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['tem_ibs_cbs' => true])
            ->fillForm(['tem_ibs_cbs' => 1])
            ->assertFormSet(['classificacao_tributaria' => '000001'])
            ->fillForm(['tem_ibs_cbs' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $nota->refresh();
        $this->assertNull($nota->cst_ibs_cbs);
        $this->assertNull($nota->classificacao_tributaria);
        $this->assertNull($nota->indicador_de_operacao);
        $this->assertArrayNotHasKey('ibscbs', app(MontadorDaDps::class)->montar($nota)->paraApi()['infDPS']);
    }

    public function test_nota_sem_reforma_tributaria_nao_leva_o_grupo(): void
    {
        $this->assertArrayNotHasKey('ibscbs', $this->valoresDaDps([]));
    }

    /**
     * `indFinal` vai declarado, e não omitido, porque a wrapper preenche o
     * campo sozinha quando ele não vem, e o padrão dela é `1`. Numa nota a
     * pessoa jurídica isso rotularia a operação como consumidor final sem
     * ninguém ter dito nada.
     */
    public function test_o_tipo_do_tomador_decide_o_consumidor_final(): void
    {
        $reforma = [
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => '100301',
        ];

        $paraEmpresa = Cliente::factory()->create(['tipo_pessoa' => TipoPessoa::Juridica]);
        $paraPessoa = Cliente::factory()->create(['tipo_pessoa' => TipoPessoa::Fisica]);

        $this->assertSame('0', $this->valoresDaDps([...$reforma, 'cliente_id' => $paraEmpresa->getKey()])['ibscbs']['indFinal']);
        $this->assertSame('1', $this->valoresDaDps([...$reforma, 'cliente_id' => $paraPessoa->getKey()])['ibscbs']['indFinal']);
    }

    /**
     * A prévia do XML existe para conferir o documento antes de qualquer coisa
     * sair, e o que ela não pode fazer é justamente sair: o rascunho continua
     * rascunho, sem `id_dps` e sem XML gravado.
     *
     * Sem esta garantia a janela viraria uma emissão disfarçada de consulta.
     */
    public function test_a_previa_do_xml_nao_muda_o_rascunho(): void
    {
        $this->app->instance(GatewayFiscal::class, new GatewayFiscalFalso);

        $nota = Nota::factory()->create(['status' => StatusNota::Rascunho]);

        $xml = app(PreverXmlDaDps::class)->executar($nota);

        $this->assertStringContainsString('<DPS/>', $xml);

        $nota->refresh();

        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertNull($nota->id_dps);
        $this->assertNull($nota->xml_dps);
    }

    /**
     * O XML vem da API numa linha só, que é o certo para assinar e ilegível
     * para conferir. A prévia indenta, e é essa a diferença entre ela e o
     * download.
     */
    public function test_a_previa_devolve_o_xml_indentado(): void
    {
        $this->app->instance(GatewayFiscal::class, new GatewayFiscalFalso);

        $xml = app(PreverXmlDaDps::class)->executar(Nota::factory()->create());

        $this->assertStringStartsWith('<?xml', $xml);
        $this->assertStringEndsWith("\n", $xml);
    }

    /**
     * Emitente do Simples declara a alíquota efetiva em `pTotTribSN`, e não os
     * três valores. A tabela do IBPT estima a carga de quem apura tributo a
     * tributo, e essa empresa não apura: ela recolhe por guia única.
     *
     * O campo só chega ao XML quando o prestador vai declarado como optante, e
     * a wrapper o ignora quando não vai: o mesmo acoplamento que este lado faz
     * em `declaraPeloSimplesNacional()`. Declarar a alíquota de quem não está
     * no Simples não produz documento errado, produz documento sem o campo.
     */
    public function test_emitente_do_simples_declara_a_aliquota_efetiva(): void
    {
        $simples = Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::OptanteMicroEmpresa,
        ]);

        $infDps = $this->valoresDaDps([
            'empresa_id' => $simples->getKey(),
            'percentual_simples_nacional' => 6.54,
            'total_tributos_federais' => 134.50,
        ]);

        $this->assertSame(['pTotTribSN' => 6.54], $infDps['valores']['totTrib']);
    }

    /**
     * Responder "sim" para os totais e deixar a alíquota zerada não declara
     * nada: `TotaisAproximados` trata o grupo como vazio e ele sai da DPS, e a
     * nota vai sem o destaque que a Lei da Transparência manda. A validação é o
     * que separa "não quis declarar" de "achou que declarou".
     */
    public function test_optante_que_pede_totais_sem_aliquota_e_recusado_com_a_mensagem(): void
    {
        $this->actingAs(User::factory()->create());

        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->create([
                'regime_simples_nacional' => RegimeSimplesNacional::OptanteMicroEmpresa,
            ]),
            'percentual_simples_nacional' => 6.54,
        ]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['tem_totais' => true])
            ->fillForm(['percentual_simples_nacional' => '0'])
            ->call('save')
            // A mensagem vai por closure, e não como texto: o Livewire corta
            // o esperado no primeiro ":" para tratá-lo como `regra:parametro`,
            // e esta mensagem tem dois-pontos no meio.
            ->assertHasFormErrors([
                'percentual_simples_nacional' => fn (array $regras, array $mensagens): bool => in_array(
                    'Informe a alíquota efetiva do Simples, ou responda "Não": zerada, o grupo não sai na nota.',
                    $mensagens,
                    true,
                ),
            ]);
    }

    /**
     * Não optante com o campo preenchido continua declarando em valor: a
     * alíquota do Simples não se aplica a quem não está nele, e deixá-la vencer
     * faria a nota declarar uma guia única que a empresa não recolhe.
     */
    public function test_nao_optante_ignora_a_aliquota_do_simples(): void
    {
        $normal = Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::NaoOptante,
        ]);

        $infDps = $this->valoresDaDps([
            'empresa_id' => $normal->getKey(),
            'percentual_simples_nacional' => 6.54,
            'total_tributos_federais' => 134.50,
        ]);

        $this->assertSame(
            ['vTotTribFed' => 134.5, 'vTotTribEst' => 0.0, 'vTotTribMun' => 0.0],
            $infDps['valores']['totTrib'],
        );
    }

    /**
     * Optante que não informou a alíquota não cai num `pTotTribSN` zerado, que
     * afirmaria não haver tributo embutido: os três valores continuam valendo,
     * se houver algum.
     */
    public function test_optante_sem_aliquota_cai_nos_tres_valores(): void
    {
        $simples = Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::OptanteMei,
        ]);

        $infDps = $this->valoresDaDps([
            'empresa_id' => $simples->getKey(),
            'total_tributos_federais' => 134.50,
        ]);

        $this->assertArrayHasKey('vTotTribFed', $infDps['valores']['totTrib']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Classificacoes\ImportarClassificacoesTributarias;
use App\Actions\Classificacoes\PortalDaSvrs;
use App\Actions\Indicadores\ImportarIndicadoresDeOperacao;
use App\Consultas\BuscaDeClassificacoes;
use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Models\ClassificacaoTributaria;
use App\Models\Empresa;
use App\Models\IndicadorDeOperacao;
use App\Models\Nota;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as ClienteHttp;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * As duas tabelas que este sistema nao inventa: a classificacao tributaria do
 * IBS e da CBS (`cClassTrib`, publicada pela SVRS) e os indicadores da operacao
 * (`cIndOp`, o Anexo VII da NT 007).
 */
class TabelasOficiaisTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_arquivo_local_carrega_as_classificacoes_validas_para_nfse(): void
    {
        $total = app(ImportarClassificacoesTributarias::class)->executar();

        // Sao 164 codigos na tabela inteira; os validos para NFS-e vem
        // marcados `IndNfse` na origem.
        $this->assertSame(71, $total);
        $this->assertSame(71, ClassificacaoTributaria::query()->count());

        $tributacaoIntegral = ClassificacaoTributaria::query()->where('codigo', '000001')->sole();

        $this->assertSame('000', $tributacaoIntegral->cst);
        $this->assertStringContainsString('integralmente', $tributacaoIntegral->descricao);
    }

    public function test_o_arquivo_local_carrega_os_indicadores_da_operacao(): void
    {
        $total = app(ImportarIndicadoresDeOperacao::class)->executar();

        $this->assertSame(36, $total);

        $servicoSobreImovel = IndicadorDeOperacao::query()->where('codigo', '020201')->sole();

        $this->assertSame('Localidade do imóvel', $servicoSobreImovel->local_do_fornecimento);
        $this->assertStringContainsString('Art. 11', $servicoSobreImovel->dispositivo_legal);
    }

    /**
     * O recorte do vetor conta colchetes em vez de casar expressao regular, e
     * este teste e o motivo: a descricao abaixo tem `];` no meio do texto, e um
     * `/\[.*?\];/s` cortaria ali, deixando JSON pela metade. Na pagina real sao
     * 3,8 MB de texto livre onde isso e questao de tempo.
     */
    public function test_a_leitura_do_portal_nao_para_num_colchete_dentro_do_texto(): void
    {
        Http::fake(['exemplo.test/*' => Http::response($this->paginaDaSvrs('Reduzida [ver nota]; conforme anexo'))]);

        $classificacoes = $this->portal()->classificacoes();

        $this->assertCount(2, $classificacoes);
        $this->assertSame('Reduzida [ver nota]; conforme anexo', $classificacoes[1]['descricao']);
    }

    public function test_a_leitura_do_portal_descarta_o_que_nao_vale_para_nfse(): void
    {
        Http::fake(['exemplo.test/*' => Http::response($this->paginaDaSvrs())]);

        $codigos = array_column($this->portal()->classificacoes(), 'codigo');

        // O terceiro registro da pagina falsa vem com `IndNfse` falso.
        $this->assertSame(['000001', '200001'], $codigos);
    }

    public function test_pagina_sem_o_vetor_conhecido_falha_dizendo_o_que_mudou(): void
    {
        Http::fake(['exemplo.test/*' => Http::response('<html><body>Portal em manutenção</body></html>')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('var dadosOriginais');

        $this->portal()->classificacoes();
    }

    /**
     * A gravacao e `upsert`, e nao apaga-e-recria: codigo revogado some da
     * origem, e apagar a linha deixaria sem explicacao a nota que o declarou.
     * O que ele perde e o direito de aparecer para escolher.
     */
    public function test_classificacao_revogada_fica_na_tabela_mas_sai_da_selecao(): void
    {
        ClassificacaoTributaria::factory()->revogada()->create(['codigo' => '999999', 'cst' => '000']);

        app(ImportarClassificacoesTributarias::class)->executar();

        $this->assertDatabaseHas('classificacoes_tributarias', ['codigo' => '999999']);
        $this->assertArrayNotHasKey('999999', app(BuscaDeClassificacoes::class)->paraSelecao('000'));
    }

    /**
     * A revogada continua sendo explicavel: e a nota antiga que a referencia
     * que depende disso.
     */
    public function test_a_revogada_ainda_tem_rotulo(): void
    {
        ClassificacaoTributaria::factory()->revogada()->create(['codigo' => '999999']);

        $this->assertStringContainsString('999999', (string) app(BuscaDeClassificacoes::class)->rotuloDe('999999'));
    }

    /**
     * O campo do credito presumido aparece so na classificacao que o permite, e
     * ela e a 410014. O defeito que este teste tranca e mudo e quase total:
     * chave numerica de array PHP vira inteiro, entao "410014" volta do
     * formulario como 410014, e a leitura do estado recusava inteiro. O campo
     * nao aparecia para nenhuma das 71 classificacoes, nem para a unica que o
     * permite, e o texto de ajuda continuava prometendo que sim.
     */
    public function test_o_campo_do_credito_presumido_aparece_na_classificacao_que_o_permite(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarClassificacoesTributarias::class)->executar();
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm(['tem_ibs_cbs' => true, 'cst_ibs_cbs' => '410'])
            ->fillForm(['classificacao_tributaria' => '410014'])
            ->assertSee('Código do crédito presumido')
            ->fillForm(['classificacao_tributaria' => '410001'])
            ->assertDontSee('Código do crédito presumido');
    }

    /**
     * Mesma raiz: 14 dos 36 indicadores nao tem zero a esquerda, e para esses a
     * explicacao caia sempre no texto generico. E o local do fornecimento que
     * diz a quem cabe o IBS, entao o que sumia era justamente o que a escolha
     * significa.
     */
    public function test_o_indicador_sem_zero_a_esquerda_explica_o_local_do_fornecimento(): void
    {
        $this->actingAs(User::factory()->create());
        app(ImportarIndicadoresDeOperacao::class)->executar();
        app(ImportarClassificacoesTributarias::class)->executar();
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm(['tem_ibs_cbs' => true])
            ->fillForm(['indicador_de_operacao' => '100101'])
            ->assertSee('Local do domicílio principal do adquirente')
            ->assertDontSee('Onde a operação se considera ocorrida.');
    }

    private function portal(): PortalDaSvrs
    {
        return new PortalDaSvrs(app(ClienteHttp::class), 'https://exemplo.test/tabela');
    }

    /**
     * A pagina da SVRS monta a tabela no navegador a partir deste vetor. Os
     * botoes de CSV, Excel e JSON exportam o mesmo conteudo.
     */
    private function paginaDaSvrs(string $descricao = 'Reduzida em 60%'): string
    {
        $tabela = json_encode([
            [
                'Cst' => '000',
                'NomeCst' => 'Tributação integral',
                'IndExigeTrib' => true,
                'ClassificacoesTributarias' => [
                    [
                        'CodClassTrib' => '000001',
                        'NomeReduzido' => 'Tributada integralmente',
                        'PercRedIbs' => 0,
                        'PercRedCbs' => 0,
                        'IndPermiteCredPres' => false,
                        'IndTribRegular' => false,
                        'DthIniVig' => '2025-05-05T00:00:00',
                        'DthFimVig' => null,
                        'TexUrlLegislacao' => 'https://exemplo.test/lc214',
                        'IndNfse' => true,
                    ],
                    [
                        'CodClassTrib' => '900001',
                        'NomeReduzido' => 'Só vale para NF-e',
                        'IndNfse' => false,
                    ],
                ],
            ],
            [
                'Cst' => '200',
                'NomeCst' => 'Alíquota reduzida',
                'IndExigeTrib' => true,
                'ClassificacoesTributarias' => [
                    [
                        'CodClassTrib' => '200001',
                        'NomeReduzido' => $descricao,
                        'PercRedIbs' => 60,
                        'PercRedCbs' => 60,
                        'IndNfse' => true,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        return "<html><body><script>var dadosOriginais = {$tabela};</script></body></html>";
    }

    /**
     * O botão que preenche as cinco alíquotas de uma vez. O que ele evita é o
     * operador ter que saber de cor que a CSRF de 4,65% se divide em 1% + 3% +
     * 0,65%, e em qual campo cada pedaço vai.
     *
     * O estado esperado é TEXTO no formato brasileiro, e não número, e é aí que
     * mora o defeito que este teste tranca: `formatStateUsing` só roda na
     * hidratação, então um `$set()` numérico chega cru ao navegador e a máscara
     * lê `0.65` como dígitos, mostrando `65`. Um percentual de PIS quarenta e
     * cinco vezes maior, sem nada na tela dizendo isso.
     */
    public function test_o_botao_preenche_as_aliquotas_de_lei_no_emitente(): void
    {
        $this->actingAs(User::factory()->create());
        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->callAction(TestAction::make('usarPercentuaisDeLei')->schemaComponent('retencoes-federais'))
            ->assertSchemaStateSet([
                'aliquota_pis_padrao' => '0,65',
                'aliquota_cofins_padrao' => '3',
                'aliquota_csll_padrao' => '1',
                'aliquota_irrf_padrao' => '1,5',
                'aliquota_previdenciaria_padrao' => '11',
            ]);
    }

    /**
     * Das 71 classificações válidas para NFS-e, uma permite crédito presumido.
     * O campo dizia isso no texto de ajuda e fazia o contrário: aparecia nas
     * setenta em que a escolha já tornou o código impossível.
     */
    public function test_so_uma_classificacao_permite_credito_presumido(): void
    {
        app(ImportarClassificacoesTributarias::class)->executar();

        $consulta = app(BuscaDeClassificacoes::class);

        $this->assertTrue($consulta->permiteCreditoPresumido('410014'));
        $this->assertFalse($consulta->permiteCreditoPresumido('000001'));
        $this->assertFalse($consulta->permiteCreditoPresumido(null));

        $this->assertSame(
            1,
            ClassificacaoTributaria::query()->vigente()->where('permite_credito_presumido', true)->count(),
        );
    }

    /**
     * A nota nova já abre classificando IBS/CBS quando o emitente classifica no
     * cadastro. Os três campos andam juntos, então a resposta só vem "sim" com
     * os três preenchidos: sem eles, "sim" abriria três campos obrigatórios e
     * vazios em toda nota, que é pior que a pergunta.
     */
    public function test_nota_nova_classifica_ibs_cbs_quando_o_emitente_classifica(): void
    {
        $this->actingAs(User::factory()->create());

        Empresa::factory()->create([
            'cst_ibs_cbs_padrao' => '000',
            'classificacao_tributaria_padrao' => '000001',
            'indicador_de_operacao_padrao' => '100301',
        ]);

        Livewire::test(CreateNota::class)->assertSchemaStateSet([
            'tem_ibs_cbs' => true,
            'cst_ibs_cbs' => '000',
            'classificacao_tributaria' => '000001',
            'indicador_de_operacao' => '100301',
        ]);
    }

    /**
     * O código do indicador não diz nada a quem escolhe. O que aparece embaixo
     * do seletor é o que ele significa: é o local do fornecimento que decide a
     * quem cabe o IBS.
     */
    public function test_o_indicador_escolhido_explica_o_que_significa(): void
    {
        $this->actingAs(User::factory()->create());

        IndicadorDeOperacao::factory()->create([
            'codigo' => '020201',
            'caracteristica' => 'Serviço prestado fisicamente sobre bem imóvel',
            'local_do_fornecimento' => 'Localidade do imóvel',
            'dispositivo_legal' => 'Art. 11, II e §2º',
        ]);

        Livewire::test(EditEmpresa::class, ['record' => Empresa::factory()->create()->getKey()])
            ->fillForm(['indicador_de_operacao_padrao' => '020201'])
            ->assertSee('Localidade do imóvel')
            ->assertSee('Art. 11, II e §2º');
    }

    public function test_emitente_sem_os_tres_padroes_nao_classifica_por_inercia(): void
    {
        $this->actingAs(User::factory()->create());

        // O indicador da operação falta: sem ele o XML sairia com `<cIndOp>`
        // vazio, e é justamente o que a resposta "não" evita.
        Empresa::factory()->create([
            'cst_ibs_cbs_padrao' => '000',
            'classificacao_tributaria_padrao' => '000001',
        ]);

        Livewire::test(CreateNota::class)->assertSchemaStateSet(['tem_ibs_cbs' => false]);
    }

    /**
     * Numa nota que já existe quem manda é o que está gravado nela, e não o
     * padrão da pergunta. Sem essa distinção, abrir uma nota antiga que não
     * classificava a faria classificar sozinha ao ser salva.
     */
    public function test_nota_existente_responde_pelo_que_esta_gravado(): void
    {
        $this->actingAs(User::factory()->create());

        Empresa::factory()->create([
            'cst_ibs_cbs_padrao' => '000',
            'classificacao_tributaria_padrao' => '000001',
            'indicador_de_operacao_padrao' => '100301',
        ]);

        $antiga = Nota::factory()->create(['classificacao_tributaria' => null]);

        Livewire::test(EditNota::class, ['record' => $antiga->getKey()])
            ->assertSchemaStateSet(['tem_ibs_cbs' => false]);
    }
}

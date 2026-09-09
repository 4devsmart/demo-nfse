<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\StatusNota;
use App\Domain\Enums\TipoPessoa;
use App\Filament\Resources\Cidades\Pages\ListCidades;
use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Empresas\Pages\CreateEmpresa;
use App\Filament\Resources\Notas\NotaResource;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Filament\Resources\Notas\Pages\ListNotas;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Filament\Widgets\PrimeirosPassos;
use App\Filament\Widgets\ResumoDoPainel;
use App\Filament\Widgets\UltimasNotas;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Traducao\MontadorDaDps;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Database\Factories\CidadeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

class PainelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(GatewayFiscal::class, new GatewayFiscalFalso);
        $this->actingAs(User::factory()->create());
    }

    #[DataProvider('paginasDoPainel')]
    public function test_as_paginas_do_painel_abrem(string $caminho): void
    {
        Nota::factory()->create();

        $this->get($caminho)->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paginasDoPainel(): array
    {
        return [
            'painel' => ['/admin'],
            'cidades' => ['/admin/cidades'],
            'classificacoes tributarias' => ['/admin/classificacoes-tributarias'],
            'indicadores de operacao' => ['/admin/indicadores-de-operacao'],
            'cargas tributarias' => ['/admin/cargas-tributarias'],
            'codigos de tributacao nacional' => ['/admin/codigos-de-tributacao-nacional'],
            'empresas' => ['/admin/empresas'],
            'novo emitente' => ['/admin/empresas/create'],
            'clientes' => ['/admin/clientes'],
            'notas' => ['/admin/notas'],
            'nova nota' => ['/admin/notas/nova'],
        ];
    }

    /**
     * O caminho feliz do diagnóstico. A página captura Throwable, então uma
     * classe quebrada passa a suíte inteira mostrando "a API não respondeu" se
     * só o caminho de erro for testado.
     */
    public function test_o_diagnostico_mostra_a_build_e_as_capacidades(): void
    {
        $this->app->forgetInstance(GatewayFiscal::class);

        Http::fake([
            '*/v1/ping' => Http::response(['status' => 'ok', 'versao' => [
                'commit_curto' => 'abc1234',
                'build' => '2026-08-24T16:21:06Z',
            ]]),
            '*/v1/capacidades' => Http::response([
                'base' => '/v1',
                'modulos' => ['nfse' => ['xml', 'transmissao', 'pdf']],
            ]),
        ]);

        $this->get('/admin/diagnostico')
            ->assertOk()
            ->assertSee('abc1234')
            ->assertSee('2026-08-24T16:21:06Z')
            ->assertSee('transmissao')
            ->assertDontSee('A API fiscal não respondeu');
    }

    public function test_o_diagnostico_mostra_o_erro_quando_a_api_esta_fora(): void
    {
        $this->app->forgetInstance(GatewayFiscal::class);
        Http::fake(fn () => throw new ConnectionException('sem rota para o host'));

        $this->get('/admin/diagnostico')
            ->assertOk()
            ->assertSee('A API fiscal não respondeu');
    }

    public function test_criar_nota_pelo_formulario_reserva_numero_e_grava_rascunho(): void
    {
        $empresa = Empresa::factory()->create(['proximo_numero_dps' => 10]);
        $cliente = Cliente::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm([
                'empresa_id' => $empresa->getKey(),
                'cliente_id' => $cliente->getKey(),
                'cidade_prestacao_id' => $empresa->cidade_id,
                'competencia' => now()->startOfMonth()->toDateString(),
                'descricao_servico' => 'Desenvolvimento de software',
                'codigo_servico' => '010701',
                'valor_servico' => 2500,
                'aliquota_iss' => 5,
                'deducoes' => 0,
                'desconto_incondicionado' => 0,
                'desconto_condicionado' => 0,
                'tributacao_issqn' => 1,
                'retencao_issqn' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $nota = Nota::query()->sole();
        $this->assertSame(10, (int) $nota->numero);
        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertSame(11, $empresa->refresh()->proximo_numero_dps);
    }

    /**
     * O `loadTable()` aparece aqui e nos outros testes de tabela porque as
     * tabelas usam `deferLoading()`: a primeira resposta traz a tela sem as
     * linhas, e elas chegam na requisição seguinte. Sem a chamada, o teste
     * afirmaria sobre uma tabela que ainda não consultou nada.
     */
    public function test_a_listagem_traz_as_notas(): void
    {
        $notas = Nota::factory()->count(3)->create();

        Livewire::test(ListNotas::class)->loadTable()->assertCanSeeTableRecords($notas);
    }

    public function test_o_painel_orienta_quem_ainda_nao_configurou(): void
    {
        Livewire::test(PrimeirosPassos::class)
            ->assertSee('Antes de emitir')
            ->assertSee('Empresa emitente')
            ->assertSee('Certificado A1');
    }

    public function test_a_lista_de_primeiros_passos_some_quando_tudo_esta_pronto(): void
    {
        Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->assertFalse(PrimeirosPassos::canView());
    }

    public function test_os_numeros_do_painel_saem_das_consultas(): void
    {
        Nota::factory()->create(['status' => StatusNota::Autorizada, 'valor_servico' => 2500]);
        Nota::factory()->create(['status' => StatusNota::Indeterminada]);

        Livewire::test(ResumoDoPainel::class)
            ->assertSee('R$ 2.500,00')
            ->assertSee('Pedem ação');
    }

    public function test_o_painel_lista_as_notas_recentes(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(UltimasNotas::class)->loadTable()->assertCanSeeTableRecords([$nota]);
    }

    public function test_as_abas_da_listagem_filtram_por_situacao(): void
    {
        $rascunho = Nota::factory()->create();
        $autorizada = Nota::factory()->create(['status' => StatusNota::Autorizada]);

        Livewire::test(ListNotas::class)
            ->set('activeTab', StatusNota::Autorizada->value)
            ->loadTable()
            ->assertCanSeeTableRecords([$autorizada])
            ->assertCanNotSeeTableRecords([$rascunho]);
    }

    public function test_documento_digitado_com_mascara_chega_ao_banco_so_com_digitos(): void
    {
        $cidade = CidadeFactory::rio();

        Livewire::test(CreateEmpresa::class)
            ->fillForm([
                'razao_social' => 'Estúdio Demonstração LTDA',
                'cnpj' => '19.131.243/0001-97',
                'cidade_id' => $cidade->getKey(),
                'cep' => '20040-020',
                'logradouro' => 'Rua da Assembleia',
                'numero' => '10',
                'bairro' => 'Centro',
                'telefone' => '(21) 3333-4444',
                'regime_simples_nacional' => 1,
                'regime_especial' => 0,
                'ambiente' => 'homologacao',
                'serie_dps' => '1',
                'proximo_numero_dps' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $empresa = Empresa::query()->sole();
        $this->assertSame('19131243000197', $empresa->cnpj);
        $this->assertSame('20040020', $empresa->cep);
        $this->assertSame('2133334444', $empresa->telefone);
    }

    /**
     * O caminho de volta, que e o que quebrava: a coluna guarda so digito, e
     * "45543915000181" tem os mesmos 14 caracteres de um CPF ja mascarado. A
     * mascara media caracteres, escrevia o CNPJ gravado no molde de CPF e
     * cortava os dois digitos verificadores; abrir o cadastro e salvar sem
     * tocar em nada era recusado com "Informe um CPF ou CNPJ valido".
     */
    #[DataProvider('documentosGravados')]
    public function test_o_documento_gravado_volta_mascarado_para_a_tela(
        TipoPessoa $tipo,
        string $gravado,
        string $mascarado,
    ): void {
        $cliente = Cliente::factory()->create(['tipo_pessoa' => $tipo, 'cpf_cnpj' => $gravado]);

        Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
            ->assertFormSet(['cpf_cnpj' => $mascarado])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($gravado, $cliente->refresh()->cpf_cnpj);
    }

    /**
     * @return array<string, array{TipoPessoa, string, string}>
     */
    public static function documentosGravados(): array
    {
        return [
            'CNPJ' => [TipoPessoa::Juridica, '45543915000181', '45.543.915/0001-81'],
            'CPF' => [TipoPessoa::Fisica, '52998224725', '529.982.247-25'],
        ];
    }

    public function test_o_cadastro_de_tomador_tem_pagina_propria_e_grava(): void
    {
        $cidade = CidadeFactory::rio();

        Livewire::test(CreateCliente::class)
            ->fillForm([
                'tipo_pessoa' => TipoPessoa::Fisica->value,
                'cpf_cnpj' => '529.982.247-25',
                'razao_social' => 'Fulano de Tal',
                'cidade_id' => $cidade->getKey(),
                'cep' => '20031-170',
                'logradouro' => 'Avenida Rio Branco',
                'numero' => '100',
                'bairro' => 'Centro',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cliente = Cliente::query()->sole();
        $this->assertSame('52998224725', $cliente->cpf_cnpj);
        $this->assertSame('20031170', $cliente->cep);
        $this->assertSame(TipoPessoa::Fisica, $cliente->tipo_pessoa);
    }

    /**
     * O `unique` do Filament compara o estado do campo com a coluna, e o estado
     * é o texto mascarado: "45.543.915/0001-81" nunca batia com os dígitos
     * gravados. O documento repetido passava pela validação e ia estourar no
     * índice do banco, que vira erro de servidor em vez de mensagem no campo.
     */
    public function test_documento_repetido_e_recusado_no_campo_e_nao_no_indice_do_banco(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '45543915000181']);

        Livewire::test(CreateCliente::class)
            ->fillForm(['cpf_cnpj' => '45.543.915/0001-81'])
            ->call('create')
            ->assertHasFormErrors(['cpf_cnpj' => 'unique']);

        $this->assertSame(1, Cliente::query()->count());
    }

    public function test_cnpj_repetido_do_emitente_tambem_e_recusado_no_campo(): void
    {
        Empresa::factory()->create(['cnpj' => '19131243000197']);

        Livewire::test(CreateEmpresa::class)
            ->fillForm(['cnpj' => '19.131.243/0001-97'])
            ->call('create')
            ->assertHasFormErrors(['cnpj' => 'unique']);

        $this->assertSame(1, Empresa::query()->count());
    }

    public function test_inscricao_municipal_so_aparece_para_pessoa_juridica(): void
    {
        Livewire::test(CreateCliente::class)
            ->fillForm(['tipo_pessoa' => TipoPessoa::Fisica->value])
            ->assertFormFieldHidden('inscricao_municipal')
            ->fillForm(['tipo_pessoa' => TipoPessoa::Juridica->value])
            ->assertFormFieldVisible('inscricao_municipal');
    }

    public function test_alterar_uma_nota_com_dps_montada_descarta_a_dps(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create();

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['valor_servico' => 9999])
            ->call('save')
            ->assertHasNoFormErrors();

        $nota->refresh();
        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertNull($nota->id_dps, 'a DPS antiga descreve a nota anterior');
        $this->assertNull($nota->xml_dps);
        $this->assertSame('9999.00', $nota->valor_servico);
    }

    /**
     * O piso do valor do serviço era `->minValue(0.01)`, que não valia nada
     * aqui: o campo é mascarado, sem `->numeric()`, e nesse caso o
     * `min` do Laravel mede comprimento de texto. "0,00" tem quatro caracteres,
     * passava, e a nota nascia valendo zero. `ConstrutorDps` também não pegava:
     * ele recusa base negativa, e zero menos zero é zero.
     *
     * @param  string|int  $valor  as duas escritas que circulam no formulário: a
     *                             mascarada e a crua que vem do banco
     */
    #[DataProvider('valoresZerados')]
    public function test_valor_do_servico_zerado_e_recusado_no_formulario(string|int $valor): void
    {
        $nota = Nota::factory()->create(['valor_servico' => 1000]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['valor_servico' => $valor])
            ->call('save')
            ->assertHasFormErrors(['valor_servico']);

        $this->assertSame('1000.00', $nota->refresh()->valor_servico, 'a nota não pode ter sido gravada');
    }

    /**
     * @return array<string, array{string|int}>
     */
    public static function valoresZerados(): array
    {
        return [
            'mascarado' => ['0,00'],
            'cru' => ['0.00'],
            'inteiro' => [0],
            'só o zero' => ['0'],
        ];
    }

    public function test_nota_autorizada_nao_pode_ser_editada(): void
    {
        $nota = Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);

        $this->assertFalse(NotaResource::canEdit($nota));

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionHidden('edit');
    }

    /**
     * Emitir faz os dois passos; gerar e transmitir sao os passos separados.
     * Oferecer os tres ao mesmo tempo faria o usuario escolher entre a mesma
     * coisa, entao cada estado mostra so o que cabe nele.
     */
    public function test_sem_dps_montada_a_tela_oferece_emitir_ou_so_gerar(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('emitir')
            ->assertActionVisible('gerarDps')
            ->assertActionHidden('transmitir');
    }

    public function test_com_a_dps_montada_sobra_transmitir(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('transmitir')
            ->assertActionHidden('emitir')
            ->assertActionHidden('gerarDps');
    }

    /**
     * Gerar a DPS nao pede certificado: e o caminho que funciona antes de o
     * emitente ter um.
     */
    public function test_so_gerar_a_dps_funciona_sem_certificado(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionEnabled('gerarDps')
            ->assertActionDisabled('emitir');
    }

    /**
     * A quarta etapa espelha o "Emitir NFS-e" do Emissor Nacional: conferir o
     * que foi preenchido, com o imposto calculado, antes de qualquer coisa sair.
     */
    public function test_o_assistente_termina_numa_revisao_com_o_imposto_calculado(): void
    {
        $empresa = Empresa::factory()->comCertificado()->create();
        $cliente = Cliente::factory()->create(['razao_social' => 'Comércio Exemplo S.A.']);

        Livewire::test(CreateNota::class)
            ->fillForm([
                'empresa_id' => $empresa->getKey(),
                'cliente_id' => $cliente->getKey(),
                'cidade_prestacao_id' => $empresa->cidade_id,
                'competencia' => now()->startOfMonth()->toDateString(),
                'descricao_servico' => 'Desenvolvimento de software',
                'codigo_servico' => '010701',
                'valor_servico' => 1000,
                'aliquota_iss' => 5,
                'deducoes' => 100,
                'desconto_incondicionado' => 0,
                'desconto_condicionado' => 0,
                'tributacao_issqn' => 1,
                'retencao_issqn' => 1,
            ])
            ->assertSee('Prévia dos valores da NFS-e')
            ->assertSee($empresa->razao_social)
            ->assertSee('Comércio Exemplo S.A.')
            ->assertSee('R$ 900,00')   // base de calculo
            ->assertSee('R$ 45,00')    // issqn
            ->assertSee('Operação tributável');
    }

    /**
     * `afterStateUpdated` nao dispara no valor default, entao o formulario
     * abria vazio mostrando placeholders que pareciam valores preenchidos.
     */
    public function test_a_nova_nota_ja_abre_com_os_padroes_do_emitente(): void
    {
        $empresa = Empresa::factory()->create([
            'codigo_servico_padrao' => '010701',
            'cnae_padrao' => '6201501',
            'item_lista_servico_padrao' => '01.07',
            'aliquota_iss_padrao' => 5,
        ]);

        Livewire::test(CreateNota::class)->assertFormSet([
            'empresa_id' => $empresa->getKey(),
            'cidade_prestacao_id' => $empresa->cidade_id,
            'codigo_servico' => '010701',
            'cnae' => '6201501',
            'item_lista_servico' => '01.07',
            'aliquota_iss' => '5',
        ]);
    }

    public function test_trocar_de_emitente_troca_os_padroes(): void
    {
        Empresa::factory()->create(['codigo_servico_padrao' => '010701', 'aliquota_iss_padrao' => 5]);
        $outra = Empresa::factory()->create(['codigo_servico_padrao' => '170101', 'aliquota_iss_padrao' => 2]);

        Livewire::test(CreateNota::class)
            ->assertFormSet(['codigo_servico' => '010701'])
            ->fillForm(['empresa_id' => $outra->getKey()])
            ->assertFormSet(['codigo_servico' => '170101']);
    }

    public function test_consultar_no_provedor_so_existe_com_chave(): void
    {
        $semChave = Nota::factory()->create();
        $comChave = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);

        Livewire::test(ViewNota::class, ['record' => $semChave->getKey()])
            ->assertActionHidden('consultarNoProvedor');

        Livewire::test(ViewNota::class, ['record' => $comChave->getKey()])
            ->assertActionVisible('consultarNoProvedor')
            ->callAction('consultarNoProvedor')
            ->assertNotified();
    }

    public function test_gerar_dps_pela_tela_grava_o_identificador(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('gerarDps')
            ->assertHasNoActionErrors();

        $this->assertSame(StatusNota::DpsGerada, $nota->refresh()->status);
        $this->assertNotEmpty($nota->id_dps);
    }

    public function test_a_tela_mostra_o_json_que_iria_para_a_api_sem_enviar_nada(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->mountAction('verPayload')
            ->assertActionMounted('verPayload');

        $this->assertStringContainsString('infDPS', app(MontadorDaDps::class)->montar($nota)->emJson());
        $this->assertSame(StatusNota::Rascunho, $nota->refresh()->status);
    }

    /**
     * Rascunho que ainda não monta não é erro de tela: a janela mostra o motivo
     * no lugar do documento, e quem lê descobre o que falta corrigir.
     *
     * Deduções maiores que o serviço deixariam a base de cálculo negativa, e o
     * `ConstrutorDps` recusa montar antes de qualquer coisa sair daqui.
     */
    public function test_rascunho_que_nao_monta_mostra_o_motivo_nas_duas_janelas(): void
    {
        $nota = Nota::factory()->create(['valor_servico' => 100, 'deducoes' => 500]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->mountAction('verPayload')
            ->assertMountedActionModalSee([
                'A DPS ainda não pode ser montada',
                'a base de cálculo ficaria negativa',
            ]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->mountAction('verXml')
            ->assertMountedActionModalSee('O XML ainda não pode ser montado');
    }

    /**
     * A prévia do XML fala com a API, ao contrário da do JSON, porque é a API
     * quem monta o documento. O que ela não faz é gravar: o rascunho continua
     * rascunho depois de a janela abrir.
     */
    public function test_a_tela_mostra_o_xml_montado_sem_gerar_a_dps(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->mountAction('verXml')
            ->assertActionMounted('verXml');

        $nota->refresh();

        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertNull($nota->xml_dps);
    }

    /**
     * Responder "sim" aos totais aproximados e deixar os três zerados não
     * declara nada: `TotaisAproximados` trata o grupo como vazio e ele some do
     * JSON. Sem a regra, a resposta e o documento discordariam em silêncio.
     */
    public function test_totais_aproximados_zerados_sao_recusados_no_formulario(): void
    {
        $empresa = Empresa::factory()->create();
        $cliente = Cliente::factory()->create();

        Livewire::test(CreateNota::class)
            ->fillForm([
                'empresa_id' => $empresa->getKey(),
                'cliente_id' => $cliente->getKey(),
                'cidade_prestacao_id' => $empresa->cidade_id,
                'competencia' => now()->startOfMonth()->toDateString(),
                'descricao_servico' => 'Desenvolvimento de software',
                'codigo_servico' => '010701',
                'valor_servico' => 2500,
                'aliquota_iss' => 5,
                'tributacao_issqn' => 1,
                'retencao_issqn' => 1,
                'tem_totais' => true,
                'total_tributos_federais' => 0,
                'total_tributos_estaduais' => 0,
                'total_tributos_municipais' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['total_tributos_federais']);

        $this->assertSame(0, Nota::query()->count());
    }

    /**
     * Acao que some deixa quem opera procurando o que nao ha. Sem certificado o
     * botao continua na tela, desabilitado, dizendo o que falta.
     */
    public function test_sem_certificado_o_botao_emitir_fica_visivel_e_desabilitado(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('emitir')
            ->assertActionDisabled('emitir');

        $this->assertStringContainsString('certificado A1', (string) $nota->impedimentos()->paraEmitir());
    }

    public function test_com_certificado_o_botao_emitir_funciona(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('emitir')
            ->assertActionEnabled('emitir');

        $this->assertNull($nota->impedimentos()->paraEmitir());
    }

    public function test_o_danfse_fica_visivel_e_explica_por_que_ainda_nao_da(): void
    {
        $rascunho = Nota::factory()->create();

        Livewire::test(ViewNota::class, ['record' => $rascunho->getKey()])
            ->assertActionVisible('danfse')
            ->assertActionDisabled('danfse');

        $this->assertStringContainsString(
            'só existe depois que o provedor autoriza',
            (string) $rascunho->impedimentos()->paraImprimir(),
        );

        $autorizada = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);

        Livewire::test(ViewNota::class, ['record' => $autorizada->getKey()])
            ->assertActionEnabled('danfse');
    }

    public function test_a_tela_leva_ao_certificado_quando_ele_e_o_que_falta(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertSee('certificado A1');
    }

    public function test_cancelar_exige_motivo_com_tamanho_minimo(): void
    {
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
        ]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('cancelar', ['motivo' => 'curto'])
            ->assertHasActionErrors(['motivo']);

        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
    }

    /**
     * O menu avisa quando ha nota rejeitada ou com desfecho indeterminado: sao
     * as unicas que dependem de alguem agir. Sem nada pendente o badge some:
     * numero que fica sempre aceso para de ser aviso.
     */
    public function test_o_menu_avisa_so_o_que_depende_de_alguem_agir(): void
    {
        $this->assertNull(NotaResource::getNavigationBadge());

        Nota::factory()->create(['status' => StatusNota::Autorizada]);
        Nota::factory()->create(['status' => StatusNota::Rascunho]);

        $this->assertNull(NotaResource::getNavigationBadge(), 'Autorizada e rascunho nao pedem acao.');

        Nota::factory()->create(['status' => StatusNota::Rejeitada]);
        Nota::factory()->create(['status' => StatusNota::Indeterminada]);

        $this->assertSame('2', NotaResource::getNavigationBadge());
        $this->assertSame('danger', NotaResource::getNavigationBadgeColor());
    }

    /**
     * A busca global mostra a nota pelo numero que ela ja tem, o da NFS-e
     * quando o provedor numerou, o da DPS enquanto nao, e diz de quem e.
     */
    public function test_a_busca_global_identifica_a_nota_e_o_tomador(): void
    {
        $nota = Nota::factory()->create([
            'cliente_id' => Cliente::factory()->create(['razao_social' => 'Exemplo S.A.']),
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
        ]);

        $this->assertSame('NFS-e 202600000001', NotaResource::getGlobalSearchResultTitle($nota));
        $this->assertSame(
            ['Tomador' => 'Exemplo S.A.', 'Situação' => 'Autorizada'],
            NotaResource::getGlobalSearchResultDetails($nota),
        );
        $this->assertContains('chave', NotaResource::getGloballySearchableAttributes());
    }

    public function test_editar_so_e_oferecido_enquanto_nada_saiu_com_sucesso(): void
    {
        $this->assertTrue(NotaResource::canEdit(Nota::factory()->create(['status' => StatusNota::Rejeitada])));
        $this->assertFalse(NotaResource::canEdit(Nota::factory()->create(['status' => StatusNota::Autorizada])));
    }

    /**
     * A listagem de municipios diz quantos ha porque o numero e a resposta para
     * "a carga do IBGE rodou?", a tabela cheia parece igual com 10 ou 5.571.
     */
    public function test_a_listagem_de_municipios_diz_quantos_estao_carregados(): void
    {
        CidadeFactory::rio();

        Livewire::test(ListCidades::class)->assertSee('1 municípios carregados');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cidades\AtualizarCidadesPeloIbge;
use App\Domain\Enums\TipoPessoa;
use App\Filament\Resources\Cidades\Pages\ListCidades;
use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Empresas\Pages\CreateEmpresa;
use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Filament\Resources\Empresas\Pages\ListEmpresas;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Models\Cidade;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Database\Factories\CidadeFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use Tests\Apoio\CadastroDaReceita;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * As ações das telas de cadastro. Nenhuma delas emite nota, e é por isso mesmo
 * que elas passam despercebidas: quando o certificado não sobe ou o CEP não
 * preenche, o problema só aparece na emissão, longe da causa.
 */
class AcoesDosCadastrosTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_DO_PFX = 'senha-do-pfx';

    private const TITULAR = 'DEMO LTDA:19131243000197';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(GatewayFiscal::class, new GatewayFiscalFalso);
        $this->actingAs(User::factory()->create());
    }

    /**
     * O caminho que quem cadastra realmente percorre: o botão, o modal, o
     * arquivo e a senha.
     *
     * O tipo do arquivo é a parte que engana: um A1 de verdade chega como
     * `application/pkcs12`, o tipo da RFC 7292. Faltando ele na lista de tipos
     * aceitos, o certificado é recusado na validação do formulário, antes de a
     * senha ser conferida, com uma mensagem que fala de tipo de arquivo.
     */
    public function test_o_certificado_sobe_pela_tela_da_empresa(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->callAction('certificado', [
                'arquivo' => UploadedFile::fake()->createWithContent('certificado.pfx', $this->pfx()),
                'senha' => self::SENHA_DO_PFX,
            ])
            ->assertHasNoActionErrors();

        $empresa->refresh();
        $this->assertTrue($empresa->temCertificado());
        $this->assertSame(self::TITULAR, $empresa->certificado_titular);

        Notification::assertNotified('Certificado guardado');
    }

    /**
     * A tela do emitente diz o estado do certificado sem ninguém abrir nada. A
     * diferença entre "válido até" e "VENCIDO" é o que decide se quem cadastra
     * precisa renovar o A1 antes de tentar emitir.
     */
    public function test_a_tela_do_emitente_diz_o_estado_do_certificado(): void
    {
        $emDia = Empresa::factory()->comCertificado()->create();
        $vencido = Empresa::factory()->comCertificado()->create(['certificado_valido_ate' => now()->subDay()]);
        $sem = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $emDia->getKey()])
            ->assertSee(self::TITULAR.' — válido até');

        Livewire::test(EditEmpresa::class, ['record' => $vencido->getKey()])
            ->assertSee(self::TITULAR.' — VENCIDO em');

        Livewire::test(EditEmpresa::class, ['record' => $sem->getKey()])
            ->assertSee('Nenhum certificado enviado.');
    }

    /**
     * A janela de envio abre dizendo qual certificado já está guardado, e a
     * diferença entre "válido até" e "VENCIDO em" é o que decide se quem abriu
     * precisa renovar o A1 antes de tentar emitir.
     */
    public function test_a_janela_do_certificado_diz_o_que_ja_esta_guardado(): void
    {
        $emDia = Empresa::factory()->comCertificado()->create();
        $vencido = Empresa::factory()->comCertificado()->create(['certificado_valido_ate' => now()->subDay()]);
        $sem = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $emDia->getKey()])
            ->mountAction('certificado')
            ->assertMountedActionModalSee('Atual: '.self::TITULAR.' — válido até');

        Livewire::test(EditEmpresa::class, ['record' => $vencido->getKey()])
            ->mountAction('certificado')
            ->assertMountedActionModalSee('Atual: '.self::TITULAR.' — VENCIDO em');

        Livewire::test(EditEmpresa::class, ['record' => $sem->getKey()])
            ->mountAction('certificado')
            ->assertMountedActionModalSee('Ainda não há certificado.');
    }

    public function test_senha_errada_vira_aviso_e_nao_grava_nada(): void
    {
        Storage::fake('local');
        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->callAction('certificado', [
                'arquivo' => UploadedFile::fake()->createWithContent('certificado.pfx', $this->pfx()),
                'senha' => 'errada',
            ])
            ->assertHasNoActionErrors();

        $this->assertFalse($empresa->refresh()->temCertificado());

        Notification::assertNotified('Certificado recusado');
    }

    /**
     * Quem decide o layout é o município, pelo código IBGE. Perguntar antes de
     * montar qualquer coisa é mais barato que descobrir na transmissão.
     */
    public function test_verificar_municipio_diz_quem_atende_o_emitente(): void
    {
        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->callAction('verificarMunicipio')
            ->assertHasNoActionErrors();

        Notification::assertNotified(
            Notification::make()
                ->status('success')
                ->title('Rio de Janeiro/RJ')
                ->body('PadraoNacional (padrao_nacional)'),
        );
    }

    public function test_api_fora_do_ar_na_verificacao_vira_aviso(): void
    {
        $this->app->forgetInstance(GatewayFiscal::class);
        Cache::flush();
        Http::fake(fn () => throw new ConnectionException('sem rota até a API'));

        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->callAction('verificarMunicipio')
            ->assertHasNoActionErrors();

        Notification::assertNotified('Não deu para consultar');
    }

    /**
     * A carga do dia a dia sai do arquivo local; esta ação existe para quando
     * há rede e o IBGE mudou alguma coisa.
     */
    public function test_atualizar_municipios_pelo_ibge_sincroniza_a_tabela(): void
    {
        CidadeFactory::rio();

        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([
            [
                'id' => 3304557,
                'nome' => 'Rio de Janeiro',
                'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'RJ']]],
            ],
            [
                'id' => 3550308,
                'nome' => 'São Paulo',
                'regiao-imediata' => ['regiao-intermediaria' => ['UF' => ['sigla' => 'SP']]],
            ],
            // Sem UF em nenhuma das duas hierarquias: não dá para saber de que
            // estado é, então fica de fora em vez de entrar sem UF.
            ['id' => 9999999, 'nome' => 'Sem Estado'],
        ])]);

        Livewire::test(ListCidades::class)
            ->callAction('atualizarPeloIbge')
            ->assertHasNoActionErrors();

        $this->assertSame(2, Cidade::query()->count());
        $this->assertSame('SP', Cidade::query()->where('codigo_ibge', '3550308')->value('uf'));

        Notification::assertNotified('2 municípios sincronizados');
    }

    public function test_ibge_fora_do_ar_vira_aviso_e_nao_apaga_a_tabela(): void
    {
        CidadeFactory::rio();
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([], 503)]);

        Livewire::test(ListCidades::class)
            ->callAction('atualizarPeloIbge')
            ->assertHasNoActionErrors();

        $this->assertSame(1, Cidade::query()->count());

        Notification::assertNotified(
            Notification::make()
                ->danger()
                ->title('Não deu para atualizar')
                ->body('O IBGE respondeu 503.'),
        );
    }

    public function test_o_ibge_sem_municipio_nenhum_e_recusado_antes_de_gravar(): void
    {
        CidadeFactory::rio();
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([])]);

        $this->expectExceptionMessage('A fonte não devolveu nenhum município.');

        app(AtualizarCidadesPeloIbge::class)->executar();
    }

    /**
     * O Filament não desidrata componente escondido, então trocar um tomador de
     * PJ para PF deixava a inscrição municipal antiga na coluna. Ela seguia
     * saindo no grupo `toma` da DPS, e IM em documento de CPF é rejeição na
     * prefeitura. É o mesmo mecanismo que `TributacaoRespondida` fecha na nota.
     */
    public function test_trocar_o_tomador_para_pessoa_fisica_apaga_a_inscricao_municipal(): void
    {
        $cliente = Cliente::factory()->create(['inscricao_municipal' => '7654321']);

        Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
            ->fillForm([
                'tipo_pessoa' => TipoPessoa::Fisica->value,
                'cpf_cnpj' => '52998224725',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($cliente->refresh()->inscricao_municipal);
    }

    /**
     * A guarda no model, para o caso de a coluna já estar suja por um cadastro
     * antigo: a DPS é montada a partir daqui, e é o último lugar onde dá para
     * impedir a inscrição de sair.
     */
    public function test_o_tomador_pessoa_fisica_nao_leva_inscricao_municipal_para_a_dps(): void
    {
        $cliente = Cliente::factory()->create([
            'tipo_pessoa' => TipoPessoa::Fisica,
            'cpf_cnpj' => '52998224725',
            'inscricao_municipal' => '7654321',
        ]);

        $this->assertSame('', $cliente->comoTomador()->paraApi()['IM'], 'o campo vazio e podado ao montar a DPS');
    }

    /**
     * O CEP preenche logradouro, bairro e, o que importa, o município, pelo
     * código IBGE, que é o mesmo que decide o provedor de NFS-e.
     */
    public function test_o_botao_do_cep_preenche_o_endereco_e_o_municipio(): void
    {
        $rio = CidadeFactory::rio();

        Http::fake(['brasilapi.com.br/*' => Http::response([
            'street' => 'Rua da Assembleia',
            'neighborhood' => 'Centro',
            'city' => 'Rio de Janeiro',
            'state' => 'RJ',
            'ibge' => ['city' => '3304557', 'state' => '33'],
        ])]);

        Livewire::test(CreateCliente::class)
            ->fillForm(['cep' => '20040-020'])
            ->callAction(TestAction::make('buscarCep')->schemaComponent('cep'))
            ->assertFormSet([
                'logradouro' => 'Rua da Assembleia',
                'bairro' => 'Centro',
                'cidade_id' => $rio->getKey(),
            ]);

        Notification::assertNotified('Endereço preenchido');
    }

    /**
     * Busca de CEP é conveniência de cadastro, não regra fiscal: fora do ar, o
     * formulário continua preenchível à mão. Por isso a falha vira aviso e
     * nunca bloqueio.
     */
    public function test_cep_que_nao_existe_avisa_sem_travar_o_cadastro(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(['name' => 'CepPromiseError'], 404)]);

        Livewire::test(CreateCliente::class)
            ->fillForm(['cep' => '00000-000'])
            ->callAction(TestAction::make('buscarCep')->schemaComponent('cep'))
            ->assertFormSet(['logradouro' => null]);

        Notification::assertNotified('CEP não preenchido');
    }

    /**
     * O CNPJ preenche o tomador pessoa jurídica de uma vez: razão social,
     * contato e o endereço inteiro, com o município pelo código do IBGE, que é
     * o mesmo caminho da busca de CEP.
     */
    public function test_o_botao_do_cnpj_preenche_o_tomador_pessoa_juridica(): void
    {
        $barueri = Cidade::factory()->create(['codigo_ibge' => '3505708', 'nome' => 'Barueri', 'uf' => 'SP']);
        Http::fake(['brasilapi.com.br/*' => Http::response(CadastroDaReceita::corpo())]);

        Livewire::test(CreateCliente::class)
            ->fillForm(['tipo_pessoa' => TipoPessoa::Juridica->value, 'cpf_cnpj' => '45.543.915/0001-81'])
            ->callAction(TestAction::make('buscarCnpj')->schemaComponent('cpf_cnpj'))
            ->assertFormSet([
                'razao_social' => 'CARREFOUR COMERCIO E INDUSTRIA LTDA',
                'cep' => '06460020',
                'logradouro' => 'AVENIDA TUCUNARE',
                'numero' => '125',
                'complemento' => 'BLOCO C SALA 1 C101',
                'bairro' => 'TAMBORE',
                'cidade_id' => $barueri->getKey(),
                'telefone' => '1199999999',
                'email' => 'contas@exemplo.test',
            ]);

        Notification::assertNotified('Cadastro preenchido');
    }

    /**
     * Pessoa física não tem cadastro público para consultar, e a lupa some. O
     * campo é o mesmo; o que muda é o que dá para perguntar sobre ele.
     */
    public function test_a_lupa_do_cnpj_nao_aparece_para_pessoa_fisica(): void
    {
        $lupa = TestAction::make('buscarCnpj')->schemaComponent('cpf_cnpj');

        Livewire::test(CreateCliente::class)
            ->fillForm(['tipo_pessoa' => TipoPessoa::Fisica->value])
            ->assertActionHidden($lupa)
            ->fillForm(['tipo_pessoa' => TipoPessoa::Juridica->value])
            ->assertActionVisible($lupa);
    }

    /**
     * No emitente a mesma consulta traz também o nome fantasia e o CNAE
     * principal, que fica em outra seção do formulário: quem cadastra digita o
     * CNPJ uma vez e não vai procurar o CNAE em outro lugar.
     */
    public function test_o_botao_do_cnpj_preenche_o_emitente_com_o_cnae(): void
    {
        Cidade::factory()->create(['codigo_ibge' => '3505708', 'nome' => 'Barueri', 'uf' => 'SP']);
        Http::fake(['brasilapi.com.br/*' => Http::response(CadastroDaReceita::corpo())]);

        Livewire::test(CreateEmpresa::class)
            ->fillForm(['cnpj' => '45.543.915/0001-81'])
            ->callAction(TestAction::make('buscarCnpj')->schemaComponent('cnpj'))
            ->assertFormSet([
                'razao_social' => 'CARREFOUR COMERCIO E INDUSTRIA LTDA',
                'nome_fantasia' => 'CARREFOUR',
                'cnae_padrao' => '4711301',
                'logradouro' => 'AVENIDA TUCUNARE',
            ]);
    }

    /**
     * Cadastro baixado, suspenso ou inapto preenche do mesmo jeito, com aviso:
     * a nota sairia em nome de quem a Receita não considera regular.
     */
    public function test_cnpj_fora_de_atividade_preenche_com_ressalva(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(CadastroDaReceita::corpo(['descricao_situacao_cadastral' => 'BAIXADA']))]);

        Livewire::test(CreateEmpresa::class)
            ->fillForm(['cnpj' => '45.543.915/0001-81'])
            ->callAction(TestAction::make('buscarCnpj')->schemaComponent('cnpj'))
            ->assertFormSet(['razao_social' => 'CARREFOUR COMERCIO E INDUSTRIA LTDA']);

        Notification::assertNotified('Cadastro preenchido, com ressalva');
    }

    /**
     * Consulta de CNPJ é conveniência, como a de CEP: sem resposta, o aviso
     * aparece e o cadastro segue preenchível à mão.
     */
    public function test_cnpj_que_nao_existe_avisa_sem_travar_o_cadastro(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response(['message' => 'CNPJ não encontrado'], 404)]);

        Livewire::test(CreateCliente::class)
            ->fillForm(['cpf_cnpj' => '45.543.915/0001-81'])
            ->callAction(TestAction::make('buscarCnpj')->schemaComponent('cpf_cnpj'))
            ->assertFormSet(['razao_social' => null]);

        Notification::assertNotified('Cadastro não preenchido');
    }

    /**
     * Um A1 de mentira, gerado na hora: o teste não depende de arquivo no repo.
     */
    private function pfx(): string
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pedido = openssl_csr_new(['commonName' => self::TITULAR], $chave, ['digest_alg' => 'sha256']);
        $this->assertInstanceOf(OpenSSLCertificateSigningRequest::class, $pedido);

        $certificado = openssl_csr_sign($pedido, null, $chave, 365, ['digest_alg' => 'sha256']);
        $this->assertInstanceOf(OpenSSLCertificate::class, $certificado);

        openssl_pkcs12_export($certificado, $exportado, $chave, self::SENHA_DO_PFX);

        return $exportado;
    }

    /**
     * A coluna do certificado e um aviso com prazo: verde enquanto ha folga,
     * amarelo no ultimo mes, vermelho depois de vencer. Quem opera descobre
     * pela cor, na listagem, antes de a nota nao sair.
     */
    public function test_a_listagem_de_emitentes_avisa_o_prazo_do_certificado(): void
    {
        Empresa::factory()->create(['razao_social' => 'Sem Certificado LTDA']);
        Empresa::factory()->comCertificado()->create(['razao_social' => 'Em Dia LTDA']);
        Empresa::factory()->comCertificado()->create([
            'razao_social' => 'Vencendo LTDA',
            'certificado_valido_ate' => now()->addDays(10),
        ]);
        Empresa::factory()->comCertificado()->create([
            'razao_social' => 'Vencida LTDA',
            'certificado_valido_ate' => now()->subDay(),
        ]);

        Livewire::test(ListEmpresas::class)
            ->loadTable()
            ->assertSee('não enviado')
            ->assertSee('até '.now()->addDays(10)->format('d/m/Y'))
            ->assertSee('vencido em '.now()->subDay()->format('d/m/Y'));
    }

    /**
     * As FKs de `notas` são `constrained()` sem `onDelete`, e o SQLite as
     * respeita. Apagar um cadastro com nota emitida virava página de erro em
     * vez de aviso, e era a única ação do painel que respondia assim.
     *
     * Cascatear estava fora de questão: apagar o emitente levaria junto a nota,
     * o XML autorizado e o protocolo, dos quais não há segunda via.
     */
    public function test_cadastro_com_nota_nao_pode_ser_apagado(): void
    {
        $nota = Nota::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $nota->empresa->getKey()])
            ->assertActionDisabled('delete')
            ->callAction('delete');

        Livewire::test(EditCliente::class, ['record' => $nota->cliente->getKey()])
            ->assertActionDisabled('delete')
            ->callAction('delete');

        $this->assertDatabaseHas('empresas', ['id' => $nota->empresa->getKey()]);
        $this->assertDatabaseHas('clientes', ['id' => $nota->cliente->getKey()]);
    }

    public function test_cadastro_sem_nota_continua_apagavel(): void
    {
        $empresa = Empresa::factory()->create();
        $cliente = Cliente::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->assertActionEnabled('delete')
            ->callAction('delete');

        Livewire::test(EditCliente::class, ['record' => $cliente->getKey()])
            ->assertActionEnabled('delete')
            ->callAction('delete');

        $this->assertDatabaseMissing('empresas', ['id' => $empresa->getKey()]);
        $this->assertDatabaseMissing('clientes', ['id' => $cliente->getKey()]);
    }
}

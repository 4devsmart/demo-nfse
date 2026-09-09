<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Municipios\ConsultarSuporteDoMunicipio;
use App\Actions\Municipios\PreverProvedorDoMunicipio;
use App\Domain\ValueObjects\CodigoIbge;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\MunicipioAtendido;
use App\Models\Cidade;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * Quem recebe a DPS sai do municipio do emitente, e nao do municipio da
 * prestacao. Antes disto so se descobria na transmissao, com a nota inteira
 * preenchida, e a recusa vinha em codigo de erro do provedor.
 */
class ProvedorDoEmitenteTest extends TestCase
{
    use RefreshDatabase;

    private GatewayFiscalFalso $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $this->gateway);
        $this->actingAs(User::factory()->create());
    }

    public function test_o_formulario_diz_quem_atende_o_municipio_do_emitente(): void
    {
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->assertSee('Rio de Janeiro/RJ')
            ->assertSee('PadraoNacional (padrao_nacional)')
            ->assertSee('Vem do município do emitente, e não do município da prestação.');
    }

    /**
     * A previsao segue o emitente escolhido, e nao o primeiro da lista: e o que
     * a torna util enquanto se digita, e nao so na abertura do formulario.
     */
    public function test_trocar_de_emitente_troca_o_municipio_previsto(): void
    {
        Empresa::factory()->create();
        $curitiba = Cidade::factory()->create(['nome' => 'Curitiba', 'uf' => 'PR', 'codigo_ibge' => '4106902']);
        $outra = Empresa::factory()->create(['cidade_id' => $curitiba->getKey()]);

        Livewire::test(CreateNota::class)
            ->assertSee('Rio de Janeiro/RJ')
            ->fillForm(['empresa_id' => $outra->getKey()])
            ->assertSee('Curitiba/PR');
    }

    public function test_municipio_sem_provedor_avisa_que_a_transmissao_vai_recusar(): void
    {
        $this->gateway->responderMunicipioCom(
            new MunicipioAtendido(CodigoIbge::deSeteDigitos('3304557'), '', '', false),
        );
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->assertSee('sem provedor de NFS-e conhecido')
            ->assertSee('A transmissão vai recusar.');
    }

    /**
     * O defeito que este teste tranca e o pior possivel neste campo: rede fora
     * do ar virando "este municipio nao tem provedor", que e afirmacao fiscal
     * saida de uma falha de infraestrutura. Sao tres estados, e nao dois.
     */
    public function test_api_fora_do_ar_nao_vira_municipio_sem_provedor(): void
    {
        $this->gateway->falharNoMunicipioCom(new ConnectionException('sem rota para o host'));
        Empresa::factory()->create();

        Livewire::test(CreateNota::class)
            ->assertSee('não deu para consultar agora')
            ->assertDontSee('sem provedor de NFS-e conhecido')
            ->assertSee('Não impede preencher nem gravar');
    }

    /**
     * Sem guardar a falha, o formulario tentaria a rede a cada desenho, e o
     * aviso viraria espera de tres segundos por tecla.
     */
    public function test_a_falha_nao_e_perguntada_de_novo_a_cada_desenho(): void
    {
        $this->gateway->falharNoMunicipioCom(new ConnectionException('sem rota para o host'));
        $empresa = Empresa::factory()->create();
        $prever = app(PreverProvedorDoMunicipio::class);

        $prever->executar($empresa->municipio());
        $prever->executar($empresa->municipio());
        $prever->executar($empresa->municipio());

        $this->assertSame(1, $this->gateway->municipiosConsultados);
    }

    public function test_a_resposta_boa_tambem_e_perguntada_uma_vez_so(): void
    {
        $empresa = Empresa::factory()->create();
        $prever = app(PreverProvedorDoMunicipio::class);

        $this->assertTrue($prever->executar($empresa->municipio())->atendido());
        $this->assertTrue($prever->executar($empresa->municipio())->atendido());

        $this->assertSame(1, $this->gateway->municipiosConsultados);
    }

    /**
     * A falha guardada vale minutos, e nesse intervalo alguem pode ter clicado
     * "Verificar município" no cadastro e enchido o cache com a resposta certa.
     * Insistir no "nao deu para consultar" seria esconder uma resposta que esta
     * ali, e dizer que nao se sabe o que ja se sabe.
     */
    public function test_resposta_boa_que_chegou_depois_vence_a_falha_guardada(): void
    {
        $empresa = Empresa::factory()->create();
        $prever = app(PreverProvedorDoMunicipio::class);

        $this->gateway->falharNoMunicipioCom(new ConnectionException('sem rota para o host'));
        $this->assertFalse($prever->executar($empresa->municipio())->consultado());

        // A rede volta, e o botão do cadastro pergunta de novo.
        $this->gateway->falharNoMunicipioCom(null);
        app(ConsultarSuporteDoMunicipio::class)->executar($empresa->municipio());

        $this->assertTrue($prever->executar($empresa->municipio())->atendido());
    }

    /**
     * O cache de banco nao desserializa objeto: `serializable_classes` vem
     * `false` no `config/cache.php`, que e o padrao do Laravel, e com ele o
     * `unserialize` recusa toda classe. Guardando o objeto, a primeira chamada
     * devolvia o recem-criado e a segunda devolvia `__PHP_Incomplete_Class`, que
     * estourava no tipo de retorno.
     *
     * O `PreverProvedorDoMunicipio` engolia o estouro e a tela dizia "não deu
     * para consultar agora" para sempre, com a API no ar. So apareceu abrindo a
     * pagina: em teste o cache e `array`, que guarda em memoria e nunca
     * serializa, entao a suite inteira passava por cima.
     *
     * Este teste roda no driver de banco de proposito. Sem isso ele nao prova
     * nada.
     */
    public function test_a_resposta_sobrevive_a_ida_e_volta_do_cache_de_banco(): void
    {
        config(['cache.default' => 'database']);

        $empresa = Empresa::factory()->create();
        $consultar = app(ConsultarSuporteDoMunicipio::class);

        $this->assertTrue($consultar->executar($empresa->municipio())->suportado);
        $this->assertTrue($consultar->executar($empresa->municipio())->suportado);
        $this->assertTrue($consultar->jaConsultado($empresa->municipio())?->suportado);

        // Uma chamada de rede so: da segunda em diante quem responde e o cache.
        $this->assertSame(1, $this->gateway->municipiosConsultados);

        $this->assertTrue(app(PreverProvedorDoMunicipio::class)->executar($empresa->municipio())->atendido());
    }

    /**
     * A caixa marca o estado, e nao so o descreve: cor e icone dizem "atendido"
     * ou "vai recusar" antes de alguem ler a frase. Sao tres estados, e o
     * modificador da classe e o que os separa no CSS.
     */
    #[DataProvider('estadosDoProvedor')]
    public function test_a_caixa_do_provedor_marca_o_estado(string $desfecho, string $classe): void
    {
        if ($desfecho === 'sem-provedor') {
            $this->gateway->responderMunicipioCom(
                new MunicipioAtendido(CodigoIbge::deSeteDigitos('3304557'), '', '', false),
            );
        }

        if ($desfecho === 'indisponivel') {
            $this->gateway->falharNoMunicipioCom(new ConnectionException('sem rota para o host'));
        }

        Empresa::factory()->create();

        Livewire::test(CreateNota::class)->assertSee("nfse-provedor--{$classe}", escape: false);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function estadosDoProvedor(): array
    {
        return [
            'atendido' => ['atendido', 'atendido'],
            'município sem provedor' => ['sem-provedor', 'sem-provedor'],
            'API fora do ar' => ['indisponivel', 'indisponivel'],
        ];
    }

    public function test_sem_emitente_cadastrado_o_campo_pede_o_emitente(): void
    {
        Livewire::test(CreateNota::class)
            ->assertSee('É o município do emitente que decide para onde a DPS vai.');

        $this->assertSame(0, $this->gateway->municipiosConsultados);
    }
}

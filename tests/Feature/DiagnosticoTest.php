<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Municipios\ConsultarSuporteDoMunicipio;
use App\Domain\ValueObjects\CodigoIbge;
use App\Filament\Pages\Diagnostico;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * A ponte com a API fiscal, visível. É a página que responde as três perguntas
 * que se faz antes de culpar o próprio código: qual build está rodando, o que
 * ela sabe fazer, e quem atende este município.
 *
 * A página captura `Throwable`, porque diagnóstico que quebra não diagnostica
 * nada. O preço disso é que um erro nosso vira "a API não respondeu", então o
 * caminho feliz precisa ser testado tanto quanto o de erro.
 */
class DiagnosticoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->app->instance(GatewayFiscal::class, new GatewayFiscalFalso);
        $this->actingAs(User::factory()->create());
    }

    public function test_a_consulta_de_municipio_mostra_o_provedor_que_atende(): void
    {
        Livewire::test(Diagnostico::class)
            ->set('codigoDoMunicipio', '3304557')
            ->call('consultarMunicipio')
            ->assertSet('municipioConsultado', [
                'codigo' => '3304557',
                'provedor' => 'PadraoNacional',
                'layout' => 'padrao_nacional',
                'suportado' => true,
            ]);
    }

    public function test_codigo_com_tamanho_errado_nem_chega_a_sair(): void
    {
        Http::fake();

        Livewire::test(Diagnostico::class)
            ->set('codigoDoMunicipio', '330')
            ->call('consultarMunicipio')
            ->assertSet('municipioConsultado', null);

        Notification::assertNotified('O código IBGE tem 7 dígitos');
        Http::assertNothingSent();
    }

    /**
     * A consulta anterior não pode ficar na tela quando a nova falha: quem
     * lê veria o provedor de um município e o erro de outro ao mesmo tempo.
     */
    public function test_a_falha_limpa_o_resultado_anterior(): void
    {
        $this->app->forgetInstance(GatewayFiscal::class);
        Http::fake(fn () => throw new ConnectionException('sem rota até a API'));

        Livewire::test(Diagnostico::class)
            ->set('codigoDoMunicipio', '3304557')
            ->call('consultarMunicipio')
            ->assertSet('municipioConsultado', null);

        Notification::assertNotified('Não deu para consultar');
    }

    /**
     * A tabela de provedores muda com versão da biblioteca, não com o dia:
     * cachear é barato, e a segunda pergunta sobre o mesmo município não sai.
     */
    public function test_a_segunda_consulta_ao_mesmo_municipio_sai_do_cache(): void
    {
        $this->app->forgetInstance(GatewayFiscal::class);

        Http::fake(['*/v1/nfse/municipios/*' => Http::response([
            'codigo' => '3304557',
            'provedor' => 'PadraoNacional',
            'layout' => 'padrao_nacional',
            'suportado' => true,
        ])]);

        $consultar = app(ConsultarSuporteDoMunicipio::class);
        $codigo = CodigoIbge::deSeteDigitos('3304557');

        $this->assertSame('PadraoNacional', $consultar->executar($codigo)->provedor);
        $this->assertSame('PadraoNacional', $consultar->executar($codigo)->provedor);

        $this->assertCount(
            1,
            Http::recorded(fn ($requisicao): bool => str_contains($requisicao->url(), '/municipios/')),
        );
    }
}

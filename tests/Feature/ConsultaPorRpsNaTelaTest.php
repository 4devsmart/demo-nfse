<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\StatusNota;
use App\Domain\ValueObjects\CodigoIbge;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\MunicipioAtendido;
use App\Models\Cidade;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * A consulta por RPS e o par serie/numero do mundo ABRASF. No Padrao Nacional o
 * caminho equivalente e a chave da DPS, e o pedido volta com "Chave da DPS não
 * informada" (X126) sem sequer montar envelope.
 *
 * Antes disto a acao aparecia igual as outras duas e so a rejeicao explicava, e
 * o campo do codigo de verificacao nao dizia o que era: o codigo longo que se
 * tem a mao numa nota do Padrao Nacional e a chave de acesso, e colar a chave
 * ali era o engano natural.
 */
class ConsultaPorRpsNaTelaTest extends TestCase
{
    use RefreshDatabase;

    private GatewayFiscalFalso $gateway;

    private ?Nota $nota = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $this->gateway);
        $this->actingAs(User::factory()->create());
    }

    public function test_o_padrao_nacional_avisa_que_nao_responde_por_rps(): void
    {
        $aviso = $this->descricaoDoModal();

        $this->assertStringContainsString('não responde por RPS', $aviso);
        $this->assertStringContainsString('Consultar DPS', $aviso);
    }

    /**
     * Provedor ABRASF responde por RPS: ali o aviso seria mentira.
     */
    public function test_provedor_abrasf_nao_recebe_o_aviso(): void
    {
        $this->gateway->responderMunicipioCom(
            new MunicipioAtendido(CodigoIbge::deSeteDigitos('2927408'), 'ISSSalvador', 'abrasf', true),
        );

        $this->assertStringNotContainsString('não responde por RPS', $this->descricaoDoModal());
    }

    /**
     * O campo precisa dizer o que e. Sem isso o operador cola a chave de acesso,
     * que e o unico codigo longo que ele tem, e a consulta sai errada.
     */
    public function test_o_campo_do_codigo_de_verificacao_diz_o_que_e(): void
    {
        $this->assertStringContainsString(
            'Não é a chave de acesso',
            $this->textoDeApoioDoCampo('codigo_verificacao'),
        );
    }

    /**
     * A descricao que o modal mostra antes do clique. Lida da propria acao, e nao
     * do HTML: o corpo do modal do Filament nao vem na primeira resposta.
     */
    private function descricaoDoModal(): string
    {
        return $this->texto($this->modalMontado()->getMountedAction()?->getModalDescription());
    }

    /**
     * O que o campo explica embaixo. O `helperText()` nao tem getter: ele vira um
     * `Text` no container `below_content` do campo, e e de la que se le.
     */
    private function textoDeApoioDoCampo(string $campo): string
    {
        $pagina = $this->modalMontado();
        $schema = $pagina->getSchema((string) $pagina->getMountedActionSchemaName());

        $this->assertNotNull($schema, 'A ação de consulta por RPS não expõe schema montado.');

        $componente = $schema->getFlatComponents(withHidden: true)[$campo] ?? null;

        $this->assertInstanceOf(Component::class, $componente, "O modal não tem o campo {$campo}.");

        $apoio = '';

        foreach ($componente->getChildComponentContainers(withHidden: true) as $container) {
            foreach ($container->getFlatComponents(withHidden: true) as $filho) {
                $apoio .= $filho instanceof Text ? $this->texto($filho->getContent()).' ' : '';
            }
        }

        return $apoio;
    }

    private function texto(string|Htmlable|null $conteudo): string
    {
        return $conteudo instanceof Htmlable ? $conteudo->toHtml() : (string) $conteudo;
    }

    private function modalMontado(): ViewNota
    {
        $pagina = Livewire::test(ViewNota::class, ['record' => $this->notaAutorizada()->getKey()])
            ->mountAction(TestAction::make('consultarPorRps'))
            ->instance();

        assert($pagina instanceof ViewNota);

        return $pagina;
    }

    private function notaAutorizada(): Nota
    {
        if ($this->nota instanceof Nota) {
            return $this->nota;
        }

        $portoAlegre = Cidade::factory()->create(['nome' => 'Porto Alegre', 'uf' => 'RS', 'codigo_ibge' => '4314902']);

        return $this->nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado()->create(['cidade_id' => $portoAlegre->getKey()]),
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '18',
            'chave' => '43149022203780307000140000000000001826094750081966',
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);
    }
}

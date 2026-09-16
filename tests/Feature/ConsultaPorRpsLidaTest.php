<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\BuscarXmlDoEvento;
use App\Consultas\ConferenciaComOProvedor;
use App\Domain\Enums\StatusNota;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\NfseConsultada;
use App\Fiscal\Respostas\RespostaCrua;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\Apoio\RetornoDoGiss;
use Tests\TestCase;

/**
 * A consulta por RPS lida, em vez de 400 caracteres de INI num aviso. Foi por
 * ali que uma NFS-e cancelada no GISS pareceu autorizada: o cancelamento estava no
 * fim do XML, depois do corte, e a nota aqui dizia outra coisa.
 */
class ConsultaPorRpsLidaTest extends TestCase
{
    use RefreshDatabase;

    private GatewayFiscalFalso $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $this->gateway);
    }

    public function test_le_numero_verificacao_emissao_e_o_cancelamento_do_xml(): void
    {
        $consultada = NfseConsultada::doRetorno(new RespostaCrua(0, RetornoDoGiss::nfseCancelada(), ''));

        $this->assertTrue($consultada->foiEncontrada());
        $this->assertSame('86', $consultada->numero);
        $this->assertSame('F4XBC8RJD', $consultada->codigoDeVerificacao);
        $this->assertSame('16/09/2026 10:54:20', $consultada->emitidaEm);
        $this->assertTrue($consultada->cancelada);
        $this->assertSame('16/09/2026 10:57:25', $consultada->canceladaEm, 'A hora é a do fuso que o provedor mandou.');
        $this->assertTrue($consultada->temDocumentoDeEvento());
    }

    /**
     * O namespace padrão está na raiz da resposta. Recortado como texto, o
     * `<CompNfse>` sairia sem ele, e o XML guardado deixaria de ser GISS.
     */
    public function test_o_documento_guardado_leva_o_namespace_do_provedor(): void
    {
        $documento = NfseConsultada::doRetorno(new RespostaCrua(0, RetornoDoGiss::nfseCancelada(), ''))->documento;

        $xml = new DOMDocument;
        $this->assertTrue($xml->loadXML($documento));
        $this->assertSame('CompNfse', $xml->documentElement?->localName);
        $this->assertSame('http://www.giss.com.br/tipos-v2_04.xsd', $xml->documentElement->namespaceURI);
        $this->assertSame(1, $xml->getElementsByTagNameNS('*', 'NfseCancelamento')->length);
    }

    public function test_rps_sem_nota_nao_inventa_numero_nem_data(): void
    {
        $consultada = NfseConsultada::doRetorno(new RespostaCrua(0, RetornoDoGiss::semNota(), ''));

        $this->assertFalse($consultada->foiEncontrada());
        $this->assertSame('', $consultada->emitidaEm, 'A data zerada da biblioteca não é data.');
        $this->assertFalse($consultada->temDocumentoDeEvento());
        $this->assertStringContainsString('X203 Não foi retornado nenhuma NFSe.', $consultada->mensagens->emLinhas());
    }

    public function test_aponta_o_cancelamento_que_a_nota_daqui_nao_tem(): void
    {
        $nota = $this->notaGiss(StatusNota::Autorizada);
        $cancelada = NfseConsultada::doRetorno(new RespostaCrua(0, RetornoDoGiss::nfseCancelada(), ''));
        $conferencia = app(ConferenciaComOProvedor::class);

        $this->assertSame(
            ['O provedor registrou o cancelamento em 16/09/2026 10:57:25, e aqui a nota está Autorizada.'],
            $conferencia->divergencias($nota, $cancelada, '1', '1'),
        );

        $nota->status = StatusNota::Cancelada;
        $this->assertSame([], $conferencia->divergencias($nota, $cancelada, '1', '1'), 'Cancelada lá e aqui confere.');
        $this->assertSame([], $conferencia->divergencias($nota, $cancelada, '2', '1'), 'Outro RPS não se compara com esta nota.');
    }

    public function test_a_tela_mostra_o_estado_os_dados_e_a_divergencia(): void
    {
        $this->actingAs(User::factory()->create());
        $this->gateway->responderRpsCom(RetornoDoGiss::nfseCancelada());
        $nota = $this->notaGiss(StatusNota::Autorizada);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            // A resposta abre no lugar do modal do pedido, que tinha formulário;
            // o de resultado não tem, e `assertHasNoActionErrors` procuraria o
            // formulário que já saiu de cena.
            ->callAction('consultarPorRps', ['numero' => '1', 'serie' => '1'])
            ->assertHasNoErrors()
            ->assertActionMounted('resultadoDaConsultaPorRps')
            ->assertMountedActionModalSee('NFS-e 86 cancelada')
            ->assertMountedActionModalSee('Cancelamento registrado em 16/09/2026 10:57:25.')
            ->assertMountedActionModalSee('F4XBC8RJD')
            ->assertMountedActionModalSee('Não confere com esta nota')
            ->assertMountedActionModalSee('e aqui a nota está Autorizada.');
    }

    /**
     * O ABRASF não tem fila DF-e nem chave: o registro do cancelamento vem da
     * consulta pelo RPS, e é o `<CompNfse>` com ele que fica guardado.
     */
    public function test_buscar_o_evento_de_nota_abrasf_guarda_o_registro_do_provedor(): void
    {
        $this->gateway->responderRpsCom(RetornoDoGiss::nfseCancelada());
        $nota = $this->notaGiss(StatusNota::Cancelada);

        $this->assertNull($nota->impedimentos()->paraBuscarOEvento(), 'Sem chave, e mesmo assim há como buscar.');
        $this->assertTrue(app(BuscarXmlDoEvento::class)->executar($nota));

        $this->assertSame(['1/1'], $this->gateway->rpsConsultados);
        $this->assertSame([], $this->gateway->nsuPedidos, 'Nota ABRASF não percorre a fila DF-e.');
        $this->assertStringContainsString('<NfseCancelamento>', (string) base64_decode((string) $nota->refresh()->xml_evento, true));
    }

    public function test_sem_registro_no_provedor_nada_e_guardado(): void
    {
        $this->gateway->responderRpsCom(RetornoDoGiss::nfseAutorizada());
        $nota = $this->notaGiss(StatusNota::Cancelada);

        $this->assertFalse(app(BuscarXmlDoEvento::class)->executar($nota));
        $this->assertNull($nota->refresh()->xml_evento);
    }

    private function notaGiss(StatusNota $status): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => $status,
            'provedor' => 'Giss (abrasf)',
            'serie' => '1',
            'numero' => 1,
            'numero_nfse' => '86',
            'codigo_verificacao' => 'F4XBC8RJD',
            'chave' => null,
            'xml_autorizado' => base64_encode('<CompNfse/>'),
        ]);
    }
}

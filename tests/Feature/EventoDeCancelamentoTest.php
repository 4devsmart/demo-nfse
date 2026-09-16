<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\BuscarXmlDoEvento;
use App\Actions\Notas\CancelarNota;
use App\Domain\Enums\StatusNota;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Respostas\DocumentoDistribuido;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Respostas\Mensagens;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * O cancelamento tem documento proprio, e ele vem uma vez so.
 *
 * A resposta do evento traz o XML dele em `xml_b64`, e a nota ficava Cancelada
 * sem guardar nada disso: consultar a chave depois devolve a NFS-e como foi
 * autorizada, sem o evento, e o DANFSE, desenhado desse mesmo XML, continua
 * imprimindo "NFS-e Gerada" em SITUAÇÃO DA NFS-E. Sem o XML do evento nao
 * sobrava prova nenhuma do cancelamento.
 */
class EventoDeCancelamentoTest extends TestCase
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

    /**
     * No Padrao Nacional o pedido identifica a nota so pela chave, como sempre
     * identificou. No ABRASF, pelo numero e pelo codigo de verificacao.
     */
    public function test_o_leiaute_do_provedor_decide_como_a_nota_e_identificada(): void
    {
        $nacional = $this->notaAutorizada();
        $giss = $this->notaAutorizada();
        $giss->forceFill([
            'provedor' => 'Giss (abrasf)',
            'chave' => null,
            'numero_nfse' => '1234',
            'codigo_verificacao' => 'ABC123',
        ])->save();

        app(CancelarNota::class)->executar($nacional, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));
        $this->assertSame((string) $nacional->chave, $this->gateway->ultimaNotaCancelada?->chave);
        $this->assertSame([], $this->gateway->ultimaNotaCancelada->paraEvento());

        app(CancelarNota::class)->executar($giss, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));
        $this->assertSame(['numero' => '1234', 'codigo_verificacao' => 'ABC123'], $this->gateway->ultimaNotaCancelada?->paraEvento());
        $this->assertSame([], $this->gateway->ultimaNotaCancelada->paraApi());
        $this->assertSame(StatusNota::Cancelada, $giss->refresh()->status);
    }

    public function test_o_cancelamento_guarda_o_xml_do_evento_e_o_protocolo(): void
    {
        $nota = $this->notaAutorizada();

        app(CancelarNota::class)->executar($nota, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));

        $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertSame('<evento/>', base64_decode((string) $nota->xml_evento, true));
        $this->assertSame('PROTO-CANC', $nota->protocolo);
    }

    /**
     * Evento recusado nao cancela e nao inventa documento: o que houve fica nas
     * mensagens, e a nota segue autorizada.
     */
    public function test_evento_recusado_nao_guarda_xml(): void
    {
        $this->gateway->responderCancelamentoCom(new EventoRegistrado(
            tipo: 'cancelamento',
            status: 'rejeitado',
            chave: str_repeat('3', 50),
            protocolo: '',
            dataHora: now()->toIso8601String(),
            xmlEmBase64: base64_encode('<evento/>'),
            mensagens: Mensagens::daLista([['codigo' => 'E999', 'descricao' => 'prazo esgotado']]),
        ));

        $nota = $this->notaAutorizada();

        app(CancelarNota::class)->executar($nota, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));

        $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertNull($nota->xml_evento);
    }

    /**
     * Medido contra a API: o cancelamento no Padrao Nacional volta concluido,
     * com protocolo vazio e com "Indice informado nao encontrado" no lugar do
     * documento. A nota cancela do mesmo jeito, e a coluna fica vazia em vez de
     * guardar a frase.
     */
    public function test_evento_sem_documento_nao_vira_arquivo(): void
    {
        $this->gateway->responderCancelamentoCom(new EventoRegistrado(
            tipo: 'cancelamento',
            status: 'concluido',
            chave: str_repeat('3', 50),
            protocolo: '',
            dataHora: now()->toIso8601String(),
            xmlEmBase64: base64_encode('Indice informado não encontrado'),
            mensagens: Mensagens::vazia(),
        ));

        $nota = $this->notaAutorizada();

        app(CancelarNota::class)->executar($nota, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));

        $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertNull($nota->xml_evento);

        $this->get(route('notas.xml', ['nota' => $nota, 'documento' => 'evento']))
            ->assertNotFound();
    }

    public function test_baixa_o_xml_do_evento_de_cancelamento(): void
    {
        $nota = $this->notaAutorizada();
        app(CancelarNota::class)->executar($nota, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));

        $this->get(route('notas.xml', ['nota' => $nota, 'documento' => 'evento']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="evento-'.str_repeat('3', 50).'.xml"')
            ->assertSee('<evento/>', escape: false);
    }

    public function test_nota_sem_evento_nao_tem_o_que_baixar(): void
    {
        $this->get(route('notas.xml', ['nota' => $this->notaAutorizada(), 'documento' => 'evento']))
            ->assertNotFound();
    }

    /**
     * O download so aparece quando ha o que baixar, e o DANFSE avisa que nao
     * imprime o cancelamento: ele sai do XML autorizado, que e anterior ao
     * evento.
     */
    public function test_a_tela_so_oferece_o_evento_depois_que_ele_existe(): void
    {
        $nota = $this->notaAutorizada();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionHidden('xmlDaevento');

        app(CancelarNota::class)->executar($nota, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('xmlDaevento');
    }

    /**
     * O que a resposta do cancelamento nao entrega, a fila DF-e entrega: o
     * evento assinado esta la, sob a mesma chave da nota, e o passeio pelo NSU
     * e o unico caminho ate ele.
     */
    public function test_o_documento_do_evento_vem_da_fila_dfe(): void
    {
        $nota = $this->cancelada();
        $this->encherAFila($nota);

        $this->assertTrue(app(BuscarXmlDoEvento::class)->executar($nota));

        $this->assertSame('<evento/>', base64_decode((string) $nota->refresh()->xml_evento, true));
        $this->assertSame([0, 2, 4], $this->gateway->nsuPedidos, 'O cursor precisa andar: sem isso a busca repete a primeira página.');
    }

    public function test_evento_que_nao_esta_na_fila_nao_inventa_documento(): void
    {
        $nota = $this->cancelada();
        $this->gateway->encherAFilaDfeCom([
            new DocumentoDistribuido(1, str_repeat('7', 50), 'EVENTO', '<evento/>'),
            new DocumentoDistribuido(2, (string) $nota->chave, 'NFSE', '<NFSe/>'),
        ]);

        $this->assertFalse(app(BuscarXmlDoEvento::class)->executar($nota));
        $this->assertNull($nota->refresh()->xml_evento);
    }

    public function test_a_busca_so_aparece_enquanto_falta_o_documento(): void
    {
        $nota = $this->cancelada();
        $this->encherAFila($nota);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('buscarXmlDoEvento')
            ->callAction('buscarXmlDoEvento')
            ->assertHasNoActionErrors();

        $this->assertNotNull($nota->refresh()->xml_evento);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionHidden('buscarXmlDoEvento');
    }

    /**
     * Nota autorizada nao tem evento nenhum para buscar.
     */
    public function test_nota_sem_evento_nao_oferece_a_busca(): void
    {
        Livewire::test(ViewNota::class, ['record' => $this->notaAutorizada()->getKey()])
            ->assertActionHidden('buscarXmlDoEvento');
    }

    private function encherAFila(Nota $nota): void
    {
        $this->gateway->encherAFilaDfeCom([
            new DocumentoDistribuido(1, str_repeat('7', 50), 'NFSE', '<NFSe/>'),
            new DocumentoDistribuido(2, str_repeat('7', 50), 'EVENTO', '<evento/>'),
            new DocumentoDistribuido(3, (string) $nota->chave, 'NFSE', '<NFSe/>'),
            new DocumentoDistribuido(4, str_repeat('8', 50), 'NFSE', '<NFSe/>'),
            new DocumentoDistribuido(5, (string) $nota->chave, 'EVENTO', '<evento/>'),
        ]);
    }

    private function cancelada(): Nota
    {
        $this->gateway->responderCancelamentoCom(new EventoRegistrado(
            tipo: 'cancelamento',
            status: 'concluido',
            chave: str_repeat('3', 50),
            protocolo: '',
            dataHora: now()->toIso8601String(),
            xmlEmBase64: base64_encode('Indice informado não encontrado'),
            mensagens: Mensagens::vazia(),
        ));

        $nota = $this->notaAutorizada();
        app(CancelarNota::class)->executar($nota, MotivoDoCancelamento::descrito('Emitida com valor incorreto'));

        return $nota->refresh();
    }

    private function notaAutorizada(): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
            'chave' => str_repeat('3', 50),
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\ConsultarLoteDaNota;
use App\Actions\Notas\TransmitirNota;
use App\Domain\Enums\StatusNota;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Respostas\Mensagens;
use App\Fiscal\Respostas\NotaTransmitida;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * O GISS 2.04 recebe por lote assincrono: a transmissao so entrega o lote, e a
 * nota nasce, ou e recusada, depois. Lida como resposta sincrona, a nota ficava
 * "Autorizada" so com o protocolo, sem numero, sem XML e sem DANFSE, enquanto o
 * lote era processado com erro.
 */
class LoteAssincronoTest extends TestCase
{
    use RefreshDatabase;

    private GatewayFiscalFalso $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $this->gateway);
    }

    /**
     * O XML que chega junto e o do RPS enviado. Gravado como autorizado, ele
     * liberava o DANFSE de uma nota que nao existe.
     */
    public function test_lote_recebido_fica_em_processamento_e_sem_xml(): void
    {
        $this->gateway->responderTransmissaoCom($this->resposta('processando', xml: base64_encode('<Rps/>')));
        $nota = $this->notaComDpsMontada();

        app(TransmitirNota::class)->executar($nota);

        $nota->refresh();
        $this->assertSame(StatusNota::EmProcessamento, $nota->status);
        $this->assertSame('2993258', $nota->protocolo);
        $this->assertNull($nota->xml_autorizado);
        $this->assertNotNull($nota->transmitida_em);
        $this->assertNotNull($nota->impedimentos()->paraImprimir());
        $this->assertNotNull($nota->impedimentos()->paraTransmitir(), 'Reenviar com o lote no provedor duplicaria o RPS.');
    }

    public function test_lote_recusado_conclui_a_nota_como_rejeitada_com_o_motivo(): void
    {
        $nota = $this->notaEmProcessamento();
        $transmitidaEm = $nota->transmitida_em;
        $this->gateway->responderLoteCom($this->resposta(
            'rejeitado',
            erros: Mensagens::daLista([['codigo' => 'E202', 'descricao' => 'Código de tributação não informado']]),
        ));

        app(ConsultarLoteDaNota::class)->executar($nota);

        $nota->refresh();
        $this->assertSame(['2993258'], $this->gateway->protocolosConsultados);
        $this->assertSame(StatusNota::Rejeitada, $nota->status);
        $this->assertSame('E202 Código de tributação não informado', $nota->mensagensDoProvedor()->emLinhas());
        $this->assertTrue($nota->status->permiteTransmitir(), 'Corrigido o motivo, a nota sai de novo.');
        $this->assertEquals($transmitidaEm, $nota->transmitida_em, 'Consultar o lote não é transmitir.');
    }

    public function test_lote_autorizado_guarda_numero_codigo_e_o_xml_da_nfse(): void
    {
        $nota = $this->notaEmProcessamento();
        $this->gateway->responderLoteCom(new NotaTransmitida(
            status: 'autorizado',
            numero: '202600000123',
            chave: '',
            codigoDeVerificacao: 'ABC123',
            protocolo: '2993258',
            situacao: '',
            xmlEmBase64: base64_encode('<CompNfse/>'),
            erros: Mensagens::vazia(),
            alertas: Mensagens::vazia(),
        ));

        app(ConsultarLoteDaNota::class)->executar($nota);

        $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('202600000123', $nota->numero_nfse);
        $this->assertSame('ABC123', $nota->codigo_verificacao);
        $this->assertSame('<CompNfse/>', base64_decode((string) $nota->xml_autorizado, true));
        $this->assertNull($nota->impedimentos()->paraImprimir());
    }

    public function test_lote_ainda_sem_decisao_continua_em_processamento(): void
    {
        $nota = $this->notaEmProcessamento();

        app(ConsultarLoteDaNota::class)->executar($nota);

        $this->assertSame(StatusNota::EmProcessamento, $nota->refresh()->status);
        $this->assertSame('2993258', $nota->protocolo);
    }

    /**
     * Sem o protocolo a nota ficava presa: em processamento nao se reenvia, e
     * sem protocolo nao se consulta.
     */
    public function test_consulta_que_volta_sem_protocolo_nao_apaga_o_gravado(): void
    {
        $nota = $this->notaEmProcessamento();
        $this->gateway->responderLoteCom($this->resposta('processando', protocolo: ''));

        app(ConsultarLoteDaNota::class)->executar($nota);

        $nota->refresh();
        $this->assertSame(StatusNota::EmProcessamento, $nota->status);
        $this->assertSame('2993258', $nota->protocolo);
        $this->assertNull($nota->impedimentos()->paraConsultarOLote());
    }

    /**
     * Rejeitado o lote, a nota e corrigida e sai de novo. O protocolo antigo e
     * de outro envio, e ficar gravado nao pode.
     */
    public function test_nova_transmissao_nao_herda_o_protocolo_do_lote_anterior(): void
    {
        $nota = $this->notaComDpsMontada();
        $nota->forceFill(['protocolo' => '2993258'])->save();
        $this->gateway->responderTransmissaoCom($this->resposta('autorizado', protocolo: ''));

        app(TransmitirNota::class)->executar($nota);

        $this->assertNull($nota->refresh()->protocolo);
    }

    public function test_a_tela_troca_transmitir_por_consultar_lote(): void
    {
        $this->actingAs(User::factory()->create());
        $nota = $this->notaEmProcessamento();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('consultarLote')
            ->assertActionEnabled('consultarLote')
            ->assertActionHidden('transmitir')
            ->assertActionHidden('emitir')
            ->assertActionHidden('cancelar');
    }

    private function notaComDpsMontada(): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::DpsGerada,
            'provedor' => 'Giss (abrasf)',
            'xml_dps' => base64_encode('<Rps/>'),
        ]);
    }

    private function notaEmProcessamento(): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::EmProcessamento,
            'provedor' => 'Giss (abrasf)',
            'xml_dps' => base64_encode('<Rps/>'),
            'protocolo' => '2993258',
            'transmitida_em' => Carbon::parse('2026-09-16 10:06:39'),
        ]);
    }

    private function resposta(string $status, string $xml = '', ?Mensagens $erros = null, string $protocolo = '2993258'): NotaTransmitida
    {
        return new NotaTransmitida(
            status: $status,
            numero: '',
            chave: '',
            codigoDeVerificacao: '',
            protocolo: $protocolo,
            situacao: '',
            xmlEmBase64: $xml,
            erros: $erros ?? Mensagens::vazia(),
            alertas: Mensagens::vazia(),
        );
    }
}

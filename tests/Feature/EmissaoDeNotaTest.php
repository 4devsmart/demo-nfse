<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\AbrirNota;
use App\Actions\Notas\CancelarNota;
use App\Actions\Notas\EmitirNota;
use App\Actions\Notas\TransmitirNota;
use App\Domain\Enums\StatusNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Fiscal\Respostas\Mensagens;
use App\Fiscal\Respostas\NotaTransmitida;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

class EmissaoDeNotaTest extends TestCase
{
    use RefreshDatabase;

    private GatewayFiscalFalso $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $this->gateway);
    }

    public function test_abrir_nota_reserva_a_numeracao_do_emitente(): void
    {
        $empresa = Empresa::factory()->create(['proximo_numero_dps' => 7, 'serie_dps' => '2']);
        $cliente = Cliente::factory()->create();

        $nota = app(AbrirNota::class)->executar($this->dadosDoFormulario($empresa, $cliente));

        $this->assertSame(7, (int) $nota->numero);
        $this->assertSame('2', $nota->serie);
        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertNotEmpty($nota->referencia);
        $this->assertSame(8, $empresa->refresh()->proximo_numero_dps);
    }

    public function test_dois_rascunhos_nunca_recebem_o_mesmo_numero(): void
    {
        $empresa = Empresa::factory()->create(['proximo_numero_dps' => 1]);
        $cliente = Cliente::factory()->create();
        $abrir = app(AbrirNota::class);

        $primeira = $abrir->executar($this->dadosDoFormulario($empresa, $cliente));
        $segunda = $abrir->executar($this->dadosDoFormulario($empresa, $cliente));

        $this->assertNotSame((int) $primeira->numero, (int) $segunda->numero);
    }

    public function test_emissao_guarda_o_id_da_dps_e_o_xml_autorizado(): void
    {
        $nota = $this->notaComCertificado();

        app(EmitirNota::class)->executar($nota);

        $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('DPS330455721913124300019700001000000000000001', $nota->id_dps);
        $this->assertSame('202600000001', $nota->numero_nfse);
        $this->assertSame(str_repeat('3', 50), $nota->chave);
        $this->assertSame('ABC123', $nota->codigo_verificacao);
        $this->assertSame('PROTO-1', $nota->protocolo);
        $this->assertSame(base64_encode('<NFSe/>'), $nota->xml_autorizado);
        $this->assertNotNull($nota->transmitida_em);
    }

    public function test_a_dps_montada_carrega_o_prestador_o_tomador_e_o_valor(): void
    {
        app(EmitirNota::class)->executar($this->notaComCertificado());

        $this->assertNotNull($this->gateway->ultimaDpsMontada);
        $dps = $this->gateway->ultimaDpsMontada->paraApi()['infDPS'];

        $this->assertSame('19131243000197', $dps['prest']['CNPJ']);
        $this->assertSame('45543915000181', $dps['toma']['CNPJ']);
        $this->assertSame('3304557', $dps['cLocEmi']);
        $this->assertSame(1500.5, $dps['valores']['vServ']);
    }

    public function test_rejeicao_do_provedor_guarda_o_motivo_e_nao_autoriza(): void
    {
        $nota = $this->notaComCertificado();

        $this->gateway->responderTransmissaoCom(new NotaTransmitida(
            status: 'rejeitado',
            numero: '', chave: '', codigoDeVerificacao: '', protocolo: '', situacao: 'rejeitada',
            xmlEmBase64: '',
            erros: Mensagens::daLista([['codigo' => 'E123', 'descricao' => 'Alíquota inválida']]),
            alertas: Mensagens::vazia(),
        ));

        app(EmitirNota::class)->executar($nota);

        $nota->refresh();
        $this->assertSame(StatusNota::Rejeitada, $nota->status);
        $this->assertSame([['codigo' => 'E123', 'descricao' => 'Alíquota inválida']], $nota->mensagens);
    }

    public function test_desfecho_indeterminado_marca_a_nota_e_repropaga_a_falha(): void
    {
        $nota = $this->notaComCertificado();

        $this->gateway->falharNaTransmissaoCom(new DesfechoIndeterminado(
            DesfechoIndeterminado::CODIGO,
            'a chamada pode ter sido transmitida',
        ));

        try {
            app(EmitirNota::class)->executar($nota);
            $this->fail('A falha precisa chegar a quem chamou: repetir duplicaria o documento.');
        } catch (DesfechoIndeterminado) {
            // esperado
        }

        $nota->refresh();
        $this->assertSame(StatusNota::Indeterminada, $nota->status);
        $this->assertNotNull($nota->id_dps, 'sem o id_dps não há como consultar a nota depois');
    }

    /**
     * A guarda mora na Action, nao no botao. Um job, um comando ou um teste
     * chegam aqui sem tela nenhuma, e a recusa tem que valer do mesmo jeito.
     */
    public function test_transmitir_recusa_nota_sem_dps_montada(): void
    {
        $nota = $this->notaComCertificado();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Gere a DPS primeiro');

        try {
            app(TransmitirNota::class)->executar($nota);
        } finally {
            $this->assertNull($this->gateway->ultimoXmlTransmitido, 'Nada pode ter saído.');
        }
    }

    /**
     * O que ficou gravado no desfecho indeterminado e a unica pista de quem
     * abrir a nota depois. Sem ele a tela diz "indeterminada" e mais nada.
     */
    public function test_o_desfecho_indeterminado_guarda_o_codigo_e_o_motivo(): void
    {
        $nota = $this->notaComCertificado();

        $this->gateway->falharNaTransmissaoCom(new DesfechoIndeterminado(
            DesfechoIndeterminado::CODIGO,
            'a chamada pode ter sido transmitida',
        ));

        try {
            app(EmitirNota::class)->executar($nota);
        } catch (DesfechoIndeterminado) {
            // esperado
        }

        $this->assertSame(
            [['codigo' => 'desfecho_indeterminado', 'descricao' => 'a chamada pode ter sido transmitida']],
            $nota->refresh()->mensagens,
        );
        $this->assertNotNull($nota->transmitida_em);
    }

    public function test_cancelamento_exige_nota_autorizada(): void
    {
        $nota = $this->notaComCertificado();
        app(EmitirNota::class)->executar($nota);

        app(CancelarNota::class)->executar(
            $nota->refresh(),
            MotivoDoCancelamento::descrito('Emitida com valor incorreto'),
        );

        $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertSame('Emitida com valor incorreto', $nota->motivo_cancelamento);
        $this->assertNotNull($nota->cancelada_em);
    }

    private function notaComCertificado(): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado()->create(['cnpj' => '19131243000197']),
            'cliente_id' => Cliente::factory()->create(['cpf_cnpj' => '45543915000181']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosDoFormulario(Empresa $empresa, Cliente $cliente): array
    {
        return [
            'empresa_id' => $empresa->getKey(),
            'cliente_id' => $cliente->getKey(),
            'cidade_prestacao_id' => $empresa->cidade_id,
            'competencia' => now()->startOfMonth()->toDateString(),
            'descricao_servico' => 'Consultoria técnica',
            'codigo_servico' => '010701',
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'deducoes' => 0,
            'desconto_incondicionado' => 0,
            'desconto_condicionado' => 0,
            'tributacao_issqn' => 1,
            'retencao_issqn' => 1,
        ];
    }
}

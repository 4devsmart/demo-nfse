<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\ConsultarPorRps;
use App\Actions\Notas\SubstituirNota;
use App\Domain\Enums\StatusNota;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

class SubstituicaoESeConsultaPorRpsTest extends TestCase
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

    public function test_a_consulta_por_rps_exige_numero_e_serie(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('número e série');

        ConsultaPorRps::sobreORps('', '1');
    }

    public function test_a_consulta_por_rps_omite_o_codigo_de_verificacao_quando_nao_ha(): void
    {
        $this->assertSame(
            ['numero' => '7', 'serie' => '1', 'tipo' => '1'],
            ConsultaPorRps::sobreORps('7', '1')->paraApi(),
        );
    }

    public function test_consultar_por_rps_pergunta_pelo_par_serie_numero(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'serie' => '2',
            'numero' => 77,
        ]);

        $resposta = app(ConsultarPorRps::class)->executar($nota, ConsultaPorRps::sobreORps('77', '2'));

        $this->assertTrue($resposta->foiSucesso());
        $this->assertStringContainsString('RPS 2/77', $resposta->resposta);
    }

    public function test_a_substituta_nasce_como_copia_corrigida_com_numero_proprio(): void
    {
        $original = $this->notaAutorizada();
        $numeroAnterior = $original->empresa->proximo_numero_dps;

        app(SubstituirNota::class)->executar(
            $original,
            ['valor_servico' => 2000, 'descricao_servico' => 'Descrição corrigida'],
            MotivoDoCancelamento::descrito('Emitida com valor incorreto'),
        );

        $substituta = Nota::query()->where('substitui_nota_id', $original->getKey())->sole();

        $this->assertSame('2000.00', $substituta->valor_servico);
        $this->assertSame('Descrição corrigida', $substituta->descricao_servico);
        $this->assertSame($original->cliente_id, $substituta->cliente_id);
        $this->assertSame($numeroAnterior, (int) $substituta->numero);
        $this->assertSame(StatusNota::Autorizada, $substituta->status);
        $this->assertSame(str_repeat('9', 50), $substituta->chave, 'a substituta guarda a chave que o evento devolveu');
        $this->assertSame('PROTO-SUBST', $substituta->protocolo);
        $this->assertSame(base64_encode('<NFSe substituta/>'), $substituta->xml_autorizado);

        // Sem numero e codigo de verificacao a substituta ficava autorizada sem
        // identificacao propria, e `paraSubstituir()` a recusaria para sempre:
        // a correcao morreria num passo so.
        $this->assertSame('202600000002', $substituta->numero_nfse);
        $this->assertSame('SUBST01', $substituta->codigo_verificacao);
        $this->assertNull($substituta->impedimentos()->paraSubstituir(), 'a substituta pode ser corrigida de novo');

        $this->assertSame(StatusNota::Substituida, $original->refresh()->status);
        $this->assertSame('Emitida com valor incorreto', $original->motivo_cancelamento);
        $this->assertNotNull($original->substituta);
        $this->assertSame($substituta->getKey(), $original->substituta->getKey());
    }

    /**
     * Substituir e emitir a MESMA operacao, corrigida. Se a suspensao e o
     * beneficio nao vierem junto, a nota nova sai com base de calculo e ISSQN
     * diferentes da que ela troca, que e o oposto de substituir.
     */
    public function test_a_substituta_herda_a_tributacao_inteira_da_original(): void
    {
        $original = $this->notaAutorizada();
        $original->forceFill([
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::DecisaoJudicial,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
            'numero_beneficio_municipal' => 'BM-2026-77',
            'percentual_reducao_base' => 20,
            'total_tributos_federais' => 75.01,
        ])->save();

        app(SubstituirNota::class)->executar(
            $original->refresh(),
            ['valor_servico' => 2000, 'descricao_servico' => 'Descrição corrigida'],
            MotivoDoCancelamento::descrito('Emitida com valor incorreto'),
        );

        $substituta = Nota::query()->where('substitui_nota_id', $original->getKey())->sole();

        $this->assertSame(TipoSuspensaoDeExigibilidade::DecisaoJudicial, $substituta->tipo_suspensao);
        $this->assertSame('0001234-56.2026.8.19.0001', $substituta->numero_processo_suspensao);
        $this->assertSame('BM-2026-77', $substituta->numero_beneficio_municipal);
        $this->assertSame('75.01', $substituta->total_tributos_federais);

        // 2000 corrigidos, menos os 20% do beneficio: a conta acompanha a copia.
        $this->assertSame(1600.0, $substituta->valoresDoServico()->baseDeCalculo()->emReais());
    }

    /**
     * Falhar e nao saber sao coisas diferentes. Sem resposta a substituta PODE
     * existir no provedor, e voltar ao rascunho convidaria a emiti-la de novo.
     */
    public function test_substituicao_sem_resposta_deixa_a_substituta_indeterminada(): void
    {
        $original = $this->notaAutorizada();

        $this->gateway->falhaNaSubstituicao = new DesfechoIndeterminado(
            DesfechoIndeterminado::CODIGO,
            'a API fiscal não respondeu depois de a chamada sair',
        );

        try {
            app(SubstituirNota::class)->executar(
                $original,
                ['valor_servico' => 2000, 'descricao_servico' => 'Descrição corrigida'],
                MotivoDoCancelamento::descrito('Emitida com valor incorreto'),
            );
            $this->fail('A dúvida precisa chegar a quem chamou.');
        } catch (DesfechoIndeterminado $falha) {
            $this->assertFalse($falha->ehSeguroRepetir());
        }

        $substituta = Nota::query()->where('substitui_nota_id', $original->getKey())->sole();

        $this->assertSame(StatusNota::Indeterminada, $substituta->status);
        $this->assertSame(
            [[
                'codigo' => DesfechoIndeterminado::CODIGO,
                'descricao' => 'a API fiscal não respondeu depois de a chamada sair',
            ]],
            $substituta->mensagens,
        );
        $this->assertNotNull($substituta->transmitida_em, 'A tentativa aconteceu, e a hora dela importa.');
        $this->assertSame(StatusNota::Autorizada, $original->refresh()->status, 'a original continua valendo');
    }

    /**
     * A substituta indeterminada nao tem id_dps nem chave, so serie e numero.
     * E por isso que a consulta por RPS precisa aparecer para ela.
     */
    public function test_a_consulta_por_rps_aparece_para_a_nota_indeterminada(): void
    {
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Indeterminada,
        ]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('consultarPorRps');
    }

    /**
     * Nenhum provedor testado oferece substituicao por webservice. O que a tela
     * precisa garantir e que a recusa nao apague o trabalho do usuario.
     */
    public function test_provedor_sem_substituicao_deixa_a_substituta_como_rascunho(): void
    {
        $original = $this->notaAutorizada();

        $this->gateway->falhaNaSubstituicao = new FalhaFiscal(
            CodigoDeFalha::OperacaoNaoSuportada->value,
            'o provedor de NFS-e deste município não oferece substituição por webservice',
        );

        try {
            app(SubstituirNota::class)->executar(
                $original,
                ['valor_servico' => 2000, 'descricao_servico' => 'Descrição corrigida'],
                MotivoDoCancelamento::descrito('Emitida com valor incorreto'),
            );
            $this->fail('A recusa do provedor precisa chegar a quem chamou.');
        } catch (FalhaFiscal $falha) {
            $this->assertSame(CodigoDeFalha::OperacaoNaoSuportada->value, $falha->codigo);
            $this->assertFalse($falha->ehSeguroRepetir());
        }

        $substituta = Nota::query()->where('substitui_nota_id', $original->getKey())->sole();
        $this->assertSame(StatusNota::Rascunho, $substituta->status);
        $this->assertSame('2000.00', $substituta->valor_servico, 'o que o usuário corrigiu não pode se perder');
        $this->assertSame(StatusNota::Autorizada, $original->refresh()->status, 'a original continua valendo');
    }

    public function test_so_nota_autorizada_pode_ser_substituida(): void
    {
        Livewire::test(ViewNota::class, ['record' => Nota::factory()->create()->getKey()])
            ->assertActionHidden('substituir');

        Livewire::test(ViewNota::class, ['record' => $this->notaAutorizada()->getKey()])
            ->assertActionVisible('substituir')
            ->assertActionEnabled('substituir');
    }

    /**
     * Falar com o provedor e sempre chamada assinada. Sem certificado o botao
     * fica na tela, desabilitado, dizendo o que falta.
     */
    public function test_sem_certificado_as_consultas_ficam_desabilitadas(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->assertActionVisible('consultarPorRps')
            ->assertActionDisabled('consultarPorRps');

        $this->assertStringContainsString(
            'certificado A1',
            (string) $nota->impedimentos()->paraFalarComOProvedor(),
        );
    }

    private function notaAutorizada(): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
            'codigo_verificacao' => 'ABC123',
            'chave' => str_repeat('3', 50),
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);
    }
}

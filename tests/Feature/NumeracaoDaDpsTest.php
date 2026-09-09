<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\AbrirNota;
use App\Actions\Notas\GerarDps;
use App\Actions\Notas\ReservarNumeroDaDps;
use App\Actions\Notas\TransmitirNota;
use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * A numeracao da DPS e do sistema emissor: a API fiscal nao a controla nem
 * impede duplicidade. Estes testes cobrem o que isso implica.
 */
class NumeracaoDaDpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sequencia_avanca_de_um_em_um(): void
    {
        $empresa = Empresa::factory()->create(['proximo_numero_dps' => 41]);
        $reservar = app(ReservarNumeroDaDps::class);

        $this->assertSame(41, $reservar->executar($empresa));
        $this->assertSame(42, $reservar->executar($empresa));
        $this->assertSame(43, $reservar->executar($empresa));
        $this->assertSame(44, $empresa->refresh()->proximo_numero_dps);
    }

    /**
     * A corrida real dentro de um processo: dois pontos do codigo segurando a
     * mesma empresa, cada um com o seu valor lido em memoria. Se a reserva
     * lesse o modelo em vez de incrementar no banco, os dois levariam o mesmo
     * numero.
     */
    public function test_instancia_desatualizada_nao_repete_numero(): void
    {
        $empresa = Empresa::factory()->create(['proximo_numero_dps' => 7]);
        $mesmaEmpresaOutraInstancia = Empresa::query()->findOrFail($empresa->getKey());
        assert($mesmaEmpresaOutraInstancia instanceof Empresa);
        $reservar = app(ReservarNumeroDaDps::class);

        $primeiro = $reservar->executar($empresa);
        $segundo = $reservar->executar($mesmaEmpresaOutraInstancia);

        $this->assertSame(7, $primeiro);
        $this->assertSame(8, $segundo);
    }

    public function test_o_numero_acompanha_a_serie_do_emitente(): void
    {
        $empresa = Empresa::factory()->create(['serie_dps' => '3', 'proximo_numero_dps' => 100]);
        $cliente = Cliente::factory()->create();

        $nota = app(AbrirNota::class)->executar($this->dadosDoFormulario($empresa, $cliente));

        $this->assertSame('3', $nota->serie);
        $this->assertSame(100, (int) $nota->numero);
    }

    public function test_emitentes_diferentes_tem_sequencias_independentes(): void
    {
        $primeira = Empresa::factory()->create(['proximo_numero_dps' => 1]);
        $segunda = Empresa::factory()->create(['proximo_numero_dps' => 500]);
        $reservar = app(ReservarNumeroDaDps::class);

        $this->assertSame(1, $reservar->executar($primeira));
        $this->assertSame(500, $reservar->executar($segunda));
        $this->assertSame(2, $reservar->executar($primeira));
    }

    /**
     * A rede embaixo: mesmo que a reserva falhasse, o banco recusa a segunda
     * nota com o mesmo (emitente, serie, numero).
     */
    public function test_o_banco_recusa_duas_notas_com_o_mesmo_numero(): void
    {
        $nota = Nota::factory()->create(['serie' => '1', 'numero' => 10]);

        $this->expectException(UniqueConstraintViolationException::class);

        Nota::factory()->create([
            'empresa_id' => $nota->empresa_id,
            'serie' => '1',
            'numero' => 10,
        ]);
    }

    /**
     * Retransmitir uma nota rejeitada nao pode consumir um numero novo: o
     * documento e o mesmo.
     */
    public function test_retransmitir_nao_consome_numero_novo(): void
    {
        $empresa = Empresa::factory()->create(['proximo_numero_dps' => 1]);
        $cliente = Cliente::factory()->create();

        $nota = app(AbrirNota::class)->executar($this->dadosDoFormulario($empresa, $cliente));
        $numeroOriginal = (int) $nota->numero;

        $nota->refresh();

        $this->assertSame($numeroOriginal, (int) $nota->numero);
        $this->assertSame(2, $empresa->refresh()->proximo_numero_dps);
    }

    /**
     * Transmissao recusada nao gasta numero. O numero nasce na abertura da nota,
     * e nao na transmissao: a rejeicao devolve o mesmo documento para corrigir e
     * mandar de novo, e trocar o numero no meio criaria buraco na sequencia e um
     * segundo documento para o mesmo fato.
     *
     * O teste que existia acima promete isto no nome e nunca transmite. Este
     * transmite, falha, corrige e transmite de novo.
     */
    public function test_transmissao_recusada_nao_gasta_numero(): void
    {
        $gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $gateway);

        $empresa = Empresa::factory()->comCertificado()->create(['proximo_numero_dps' => 1]);
        $cliente = Cliente::factory()->create();

        $nota = app(AbrirNota::class)->executar($this->dadosDoFormulario($empresa, $cliente));
        app(GerarDps::class)->executar($nota);

        $gateway->falharNaTransmissaoCom(new FalhaFiscal(
            CodigoDeFalha::RegrasDeNegocio->value,
            'E0625 rejeitada pela prefeitura',
        ));

        try {
            app(TransmitirNota::class)->executar($nota->refresh());
            $this->fail('A transmissão tinha que ser recusada.');
        } catch (FalhaFiscal) {
            // A rejeicao e o ponto do teste.
        }

        $this->assertSame(1, (int) $nota->refresh()->numero);
        $this->assertSame(2, $empresa->refresh()->proximo_numero_dps);

        // Corrigido o motivo, a mesma nota vai com o mesmo numero.
        $gateway->falhaNaTransmissao = null;
        app(TransmitirNota::class)->executar($nota->refresh());

        $this->assertSame(1, (int) $nota->refresh()->numero);
        $this->assertSame(2, $empresa->refresh()->proximo_numero_dps);
        $this->assertSame(1, Nota::query()->where('empresa_id', $empresa->getKey())->count());
    }

    public function test_o_cadastro_recusa_voltar_o_contador_para_tras(): void
    {
        $this->actingAs(User::factory()->create());

        $nota = Nota::factory()->create(['serie' => '1', 'numero' => 42]);

        Livewire::test(EditEmpresa::class, ['record' => $nota->empresa_id])
            ->fillForm(['serie_dps' => '1', 'proximo_numero_dps' => 30])
            ->call('save')
            ->assertHasFormErrors(['proximo_numero_dps']);

        Livewire::test(EditEmpresa::class, ['record' => $nota->empresa_id])
            ->fillForm(['serie_dps' => '1', 'proximo_numero_dps' => 43])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_o_contador_pode_recomecar_numa_serie_nova(): void
    {
        $this->actingAs(User::factory()->create());

        $nota = Nota::factory()->create(['serie' => '1', 'numero' => 42]);

        Livewire::test(EditEmpresa::class, ['record' => $nota->empresa_id])
            ->fillForm(['serie_dps' => '2', 'proximo_numero_dps' => 1])
            ->call('save')
            ->assertHasNoFormErrors();
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

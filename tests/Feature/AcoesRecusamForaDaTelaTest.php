<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\AlterarNota;
use App\Actions\Notas\BaixarDanfse;
use App\Actions\Notas\BuscarXmlDoEvento;
use App\Actions\Notas\CancelarNota;
use App\Actions\Notas\ConsultarDpsPendente;
use App\Actions\Notas\ConsultarNotaNoProvedor;
use App\Actions\Notas\ConsultarPorRps;
use App\Actions\Notas\EmitirNota;
use App\Actions\Notas\GerarDps;
use App\Actions\Notas\SubstituirNota;
use App\Actions\Notas\TransmitirNota;
use App\Domain\Enums\StatusNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Pedidos\ConsultaPorRps;
use App\Fiscal\Pedidos\MotivoDoCancelamento;
use App\Models\Empresa;
use App\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * O motivo de `ImpedimentosDaNota` existir, verificado.
 *
 * A regra é dita uma vez e serve a dois públicos: a tela desabilita o botão, e
 * o caso de uso recusa quando é chamado de um job, de um comando ou de um
 * teste, onde não há botão nenhum. A metade da tela tem teste desde sempre; a
 * outra não tinha, e é ela que protege contra a chamada programática.
 *
 * Cada teste aqui chama a Action direto, com o gateway de pé: se a guarda
 * sumir, a chamada vai ao provedor em vez de parar aqui.
 */
class AcoesRecusamForaDaTelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(GatewayFiscal::class, new GatewayFiscalFalso);
    }

    /**
     * O caso que motivou a guarda de `GerarDps`. Emitir uma nota ja autorizada
     * regravava o status para `DpsGerada` no primeiro passo, e a conferencia do
     * segundo passava a ver um estado transmissivel: o documento saia duas
     * vezes, com id_dps e XML novos por cima dos antigos.
     */
    public function test_emitir_recusa_nota_ja_autorizada_e_nao_remonta_a_dps(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
            'numero_nfse' => '202600000001',
        ]);

        $idDpsAntes = $nota->id_dps;

        $this->recusa(
            fn () => app(EmitirNota::class)->executar($nota),
            'não é transmitida de novo',
        );

        $nota->refresh();

        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame($idDpsAntes, $nota->id_dps);
        $this->assertSame('202600000001', $nota->numero_nfse);
    }

    /**
     * A mesma guarda, chamada direto. Gerar a DPS nao pede certificado, entao o
     * que recusa aqui e so o estado.
     */
    public function test_gerar_a_dps_recusa_nota_que_ja_saiu(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create([
            'status' => StatusNota::Cancelada,
        ]);

        $idDpsAntes = $nota->id_dps;

        $this->recusa(
            fn () => app(GerarDps::class)->executar($nota),
            'não é montada de novo',
        );

        $this->assertSame($idDpsAntes, $nota->refresh()->id_dps);
    }

    public function test_transmitir_recusa_sem_dps_montada(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->recusa(
            fn () => app(TransmitirNota::class)->executar($nota),
            'Gere a DPS primeiro',
        );

        $this->assertSame(StatusNota::Rascunho, $nota->refresh()->status);
    }

    public function test_alterar_recusa_nota_que_ja_saiu(): void
    {
        $nota = Nota::factory()->create([
            'status' => StatusNota::Autorizada,
            'descricao_servico' => 'Descrição original',
        ]);

        $this->recusa(
            fn () => app(AlterarNota::class)->executar($nota, ['descricao_servico' => 'Outra coisa']),
            'não pode ser alterada',
        );

        $this->assertSame('Descrição original', $nota->refresh()->descricao_servico);
    }

    public function test_cancelar_recusa_nota_que_nao_esta_autorizada(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'chave' => str_repeat('3', 50),
        ]);

        $this->recusa(
            fn () => app(CancelarNota::class)->executar(
                $nota,
                MotivoDoCancelamento::descrito('Emitida com valor incorreto'),
            ),
            'não é cancelada',
        );

        $this->assertNull($nota->refresh()->cancelada_em);
    }

    /**
     * A substituta consome numeração: recusar antes de abri-la é o que impede
     * um buraco na sequência a cada tentativa inválida.
     */
    public function test_substituir_recusa_e_nao_consome_numeracao(): void
    {
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
            'numero_nfse' => null,
        ]);
        $numeroAntes = $nota->empresa->proximo_numero_dps;

        $this->recusa(
            fn () => app(SubstituirNota::class)->executar(
                $nota,
                ['valor_servico' => 2000],
                MotivoDoCancelamento::descrito('Emitida com valor incorreto'),
            ),
            'identifica a nota antiga pelo número',
        );

        $this->assertSame(0, Nota::query()->whereNotNull('substitui_nota_id')->count());
        $this->assertSame($numeroAntes, $nota->empresa->refresh()->proximo_numero_dps);
    }

    public function test_consultar_a_dps_recusa_sem_id_dps(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->recusa(
            fn () => app(ConsultarDpsPendente::class)->executar($nota),
            'precisa do id_dps',
        );

        $this->assertNull($nota->refresh()->mensagens);
    }

    public function test_consultar_no_provedor_recusa_sem_chave(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->recusa(
            fn () => app(ConsultarNotaNoProvedor::class)->executar($nota),
            'chave de acesso só existe depois',
        );
    }

    public function test_o_danfse_recusa_sem_xml_autorizado(): void
    {
        $nota = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
        ]);

        $this->recusa(
            fn () => app(BaixarDanfse::class)->executar($nota),
            'desenhado a partir do XML autorizado',
        );
    }

    /**
     * Falar com o provedor é sempre chamada assinada, e a falta de certificado
     * para as seis rotas, não só as que a tela mostra.
     */
    /**
     * A busca do evento percorre a fila DF-e do emitente inteira, e ela e
     * indexada pela chave: chamada sobre nota que nao teve evento, ela sairia
     * varrendo o ADN para nunca achar nada.
     */
    public function test_buscar_o_evento_recusa_nota_sem_evento(): void
    {
        $autorizada = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
        ]);

        $this->recusa(fn () => app(BuscarXmlDoEvento::class)->executar($autorizada), 'cancelada ou substituída');

        $semChave = Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Cancelada,
            'chave' => null,
        ]);

        $this->recusa(fn () => app(BuscarXmlDoEvento::class)->executar($semChave), 'chave de acesso');
    }

    public function test_sem_certificado_nenhuma_rota_do_provedor_sai(): void
    {
        $porTransmitir = Nota::factory()->comDpsGerada()->create();

        $autorizada = Nota::factory()->comDpsGerada()->create([
            'empresa_id' => $porTransmitir->empresa_id,
            'status' => StatusNota::Autorizada,
            'chave' => str_repeat('3', 50),
            'numero_nfse' => '202600000001',
        ]);

        $frase = 'certificado A1';

        $this->recusa(fn () => app(TransmitirNota::class)->executar($porTransmitir), $frase);
        $this->recusa(fn () => app(ConsultarDpsPendente::class)->executar($porTransmitir), $frase);
        $this->recusa(fn () => app(ConsultarNotaNoProvedor::class)->executar($autorizada), $frase);
        $this->recusa(
            fn () => app(CancelarNota::class)->executar($autorizada, MotivoDoCancelamento::descrito('Emitida com erro')),
            $frase,
        );
        $this->recusa(
            fn () => app(SubstituirNota::class)->executar($autorizada, [], MotivoDoCancelamento::descrito('Emitida com erro')),
            $frase,
        );
        $this->recusa(
            fn () => app(ConsultarPorRps::class)->executar(
                $autorizada,
                ConsultaPorRps::sobreORps((string) $autorizada->numero, $autorizada->serie),
            ),
            $frase,
        );

        $cancelada = Nota::factory()->create([
            'empresa_id' => $porTransmitir->empresa_id,
            'status' => StatusNota::Cancelada,
            'chave' => str_repeat('3', 50),
        ]);

        $this->recusa(fn () => app(BuscarXmlDoEvento::class)->executar($cancelada), $frase);
    }

    private function recusa(callable $chamada, string $trecho): void
    {
        try {
            $chamada();
            $this->fail("A operação precisa ser recusada: esperava \"{$trecho}\".");
        } catch (LogicException $recusa) {
            $this->assertStringContainsString($trecho, $recusa->getMessage());
        }
    }
}

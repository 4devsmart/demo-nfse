<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notas\AlterarNota;
use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Filament\Resources\Notas\Pages\EditNota;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Os grupos municipais. `tribFed` e `ibscbs` tem teste proprio, em
 * `TributacaoFederalTest`.
 */
class TributacaoMunicipalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_o_beneficio_municipal_reduz_a_base_e_o_imposto(): void
    {
        $semBeneficio = Nota::factory()->create(['valor_servico' => 1000, 'aliquota_iss' => 5]);
        $comBeneficio = Nota::factory()->create([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'numero_beneficio_municipal' => 'BM-2026-77',
            'percentual_reducao_base' => 20,
        ]);

        $this->assertSame(1000.0, $semBeneficio->valoresDoServico()->baseDeCalculo()->emReais());
        $this->assertSame(50.0, $semBeneficio->valoresDoServico()->issqnDevido()->emReais());

        $this->assertSame(800.0, $comBeneficio->valoresDoServico()->baseDeCalculo()->emReais());
        $this->assertSame(40.0, $comBeneficio->valoresDoServico()->issqnDevido()->emReais());
    }

    public function test_a_suspensao_leva_o_tipo_e_o_processo_para_a_api(): void
    {
        $nota = Nota::factory()->create([
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::DecisaoJudicial,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
        ]);

        $tribMun = $nota->valoresDoServico()->paraApi()['tribMun'];

        $this->assertSame(1, $tribMun['tpSusp']);
        $this->assertSame('0001234-56.2026.8.19.0001', $tribMun['nProcesso']);
    }

    public function test_suspensao_sem_processo_nao_e_montada(): void
    {
        $nota = Nota::factory()->create([
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::ProcessoAdministrativo,
            'numero_processo_suspensao' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('número do processo');

        $nota->valoresDoServico();
    }

    public function test_os_totais_aproximados_so_vao_quando_ha_algum(): void
    {
        $semTotais = Nota::factory()->create();
        $comTotais = Nota::factory()->create([
            'total_tributos_federais' => 75.01,
            'total_tributos_municipais' => 75.03,
        ]);

        $this->assertArrayNotHasKey('totTrib', array_filter($semTotais->valoresDoServico()->paraApi()));
        $this->assertSame(
            ['vTotTribFed' => 75.01, 'vTotTribEst' => 0.0, 'vTotTribMun' => 75.03],
            $comTotais->valoresDoServico()->paraApi()['totTrib'],
        );
    }

    public function test_a_pergunta_de_retencao_revela_o_campo_e_some_com_ele(): void
    {
        $nota = Nota::factory()->create(['retencao_issqn' => RetencaoIssqn::NaoRetido]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormFieldHidden('retencao_issqn')
            ->fillForm(['tem_retencao' => true])
            ->assertFormFieldVisible('retencao_issqn');
    }

    /**
     * A análise estática apontou este `instanceof` como sempre falso. Se for,
     * abrir uma nota com retenção mostraria a pergunta em "Não" e esconderia o
     * campo, perdendo o dado na primeira gravação.
     */
    public function test_a_pergunta_de_retencao_chega_marcada_quando_ha_retencao(): void
    {
        $nota = Nota::factory()->create(['retencao_issqn' => RetencaoIssqn::RetidoPeloTomador]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['tem_retencao' => true])
            ->assertFormFieldVisible('retencao_issqn');
    }

    /**
     * O outro sentido da mesma pergunta. O Filament nao desidrata componente
     * escondido, entao os campos que a pergunta esconde nao chegam ao `$data`:
     * responder "Nao" numa nota que ja tem o dado gravado precisa limpar a
     * coluna, ou a DPS seguinte sai com a suspensao que o usuario tirou.
     */
    public function test_responder_nao_apaga_a_suspensao_ja_gravada(): void
    {
        $nota = Nota::factory()->create([
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::DecisaoJudicial,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
        ]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['tem_suspensao' => true])
            ->fillForm(['tem_suspensao' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $nota->refresh();
        $this->assertNull($nota->tipo_suspensao);
        $this->assertNull($nota->numero_processo_suspensao);
        $this->assertNull($nota->valoresDoServico()->exigibilidadeSuspensa);
    }

    public function test_responder_nao_apaga_o_beneficio_e_a_reducao_da_base(): void
    {
        $nota = Nota::factory()->create([
            'valor_servico' => 1000,
            'aliquota_iss' => 5,
            'numero_beneficio_municipal' => 'BM-2026-77',
            'percentual_reducao_base' => 20,
        ]);

        $this->assertSame(800.0, $nota->valoresDoServico()->baseDeCalculo()->emReais());

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['tem_beneficio' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $nota->refresh();
        $this->assertNull($nota->numero_beneficio_municipal);
        $this->assertNull($nota->percentual_reducao_base);
        $this->assertSame(
            1000.0,
            $nota->valoresDoServico()->baseDeCalculo()->emReais(),
            'A base tem que voltar ao cheio: benefício removido é ISSQN maior.',
        );
    }

    public function test_responder_nao_apaga_os_totais_aproximados(): void
    {
        $nota = Nota::factory()->create([
            'total_tributos_federais' => 75.01,
            'total_tributos_municipais' => 75.03,
        ]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['tem_totais' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($nota->refresh()->total_tributos_federais);
        $this->assertArrayNotHasKey('totTrib', array_filter($nota->valoresDoServico()->paraApi()));
    }

    /**
     * Retencao nao zera: ela tem um valor proprio para "nao retido", e e ele
     * que a coluna precisa guardar.
     */
    public function test_responder_nao_devolve_a_retencao_para_nao_retido(): void
    {
        $nota = Nota::factory()->create(['retencao_issqn' => RetencaoIssqn::RetidoPeloTomador]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->fillForm(['tem_retencao' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(RetencaoIssqn::NaoRetido, $nota->refresh()->retencao_issqn);
    }

    /**
     * Atualizacao que nao passa pela tela (job, comando, importacao) nao
     * responde pergunta nenhuma, e nao pode apagar o que nao mencionou.
     */
    public function test_alteracao_sem_as_perguntas_nao_apaga_a_tributacao(): void
    {
        $nota = Nota::factory()->create([
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::DecisaoJudicial,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
        ]);

        app(AlterarNota::class)->executar($nota, ['descricao_servico' => 'Outra coisa']);

        $nota->refresh();
        $this->assertSame('Outra coisa', $nota->descricao_servico);
        $this->assertSame(TipoSuspensaoDeExigibilidade::DecisaoJudicial, $nota->tipo_suspensao);
    }

    public function test_a_pergunta_chega_marcada_quando_a_nota_ja_tem_o_dado(): void
    {
        $nota = Nota::factory()->create([
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::DecisaoJudicial,
            'numero_processo_suspensao' => '0001234-56.2026.8.19.0001',
        ]);

        Livewire::test(EditNota::class, ['record' => $nota->getKey()])
            ->assertFormSet(['tem_suspensao' => true])
            ->assertFormFieldVisible('numero_processo_suspensao');
    }
}

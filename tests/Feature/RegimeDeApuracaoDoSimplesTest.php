<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RegimeDeApuracaoDoSimples;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `regTrib.regApTribSN` diz se o ISSQN do optante do Simples e apurado dentro ou
 * fora da guia unica.
 *
 * O defeito que estes testes trancam era mudo e vinha de fora: medido contra a
 * API, sem o campo a biblioteca fiscal grava `1` sozinha na nota de ME/EPP, e
 * `1` afirma que o ISSQN vai no Simples. Quem apura o ISSQN por fora, por
 * exigencia do municipio, emitia declarando o contrario, e o cadastro nao tinha
 * onde dizer a verdade.
 */
class RegimeDeApuracaoDoSimplesTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_optante_declara_o_regime_de_apuracao_que_escolheu(): void
    {
        $empresa = Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::OptanteMicroEmpresa,
            'regime_apuracao_simples' => RegimeDeApuracaoDoSimples::FederaisPeloSimplesIssqnPorFora,
        ]);

        $regime = $empresa->comoPrestador()->paraApi()['regTrib'];

        $this->assertSame(3, $regime['opSimpNac']);
        $this->assertSame(2, $regime['regApTribSN']);
    }

    /**
     * Nao optante nao tem regime de apuracao do Simples a declarar, e o
     * `semVazios()` do ConstrutorDps poda o nulo.
     */
    public function test_o_nao_optante_nao_declara_regime_de_apuracao(): void
    {
        $empresa = Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::NaoOptante,
            'regime_apuracao_simples' => RegimeDeApuracaoDoSimples::FederaisEMunicipalPeloSimples,
        ]);

        $this->assertNull($empresa->comoPrestador()->paraApi()['regTrib']['regApTribSN']);
    }

    public function test_a_escolha_do_cadastro_chega_a_dps(): void
    {
        $empresa = Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::OptanteMicroEmpresa,
            'regime_apuracao_simples' => RegimeDeApuracaoDoSimples::FederaisEMunicipalPorFora,
        ]);

        $nota = Nota::factory()->create(['empresa_id' => $empresa->getKey()]);

        $this->assertSame(3, $nota->empresa->comoPrestador()->paraApi()['regTrib']['regApTribSN']);
    }

    /**
     * O campo nao e oferecido ao MEI, e a razao e medida: a biblioteca fiscal
     * grava o `regApTribSN` do ME/EPP no XML e descarta o do MEI. Um seletor que
     * nao muda nada e pior que a ausencia dele.
     */
    public function test_o_campo_nao_e_oferecido_ao_mei(): void
    {
        $this->actingAs(User::factory()->create());
        $empresa = Empresa::factory()->create(['regime_simples_nacional' => RegimeSimplesNacional::OptanteMei]);

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->assertDontSee('Apuração do ISSQN no Simples');
    }

    /**
     * O campo so aparece para optante, e some junto com a resposta quando o
     * emitente deixa de ser: regime de apuracao de quem nao esta no Simples e
     * pergunta sem sentido.
     */
    public function test_o_campo_so_aparece_para_optante(): void
    {
        $this->actingAs(User::factory()->create());
        $empresa = Empresa::factory()->create(['regime_simples_nacional' => RegimeSimplesNacional::OptanteMicroEmpresa]);

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->assertSee('Apuração do ISSQN no Simples')
            ->fillForm(['regime_simples_nacional' => RegimeSimplesNacional::NaoOptante->value])
            ->assertDontSee('Apuração do ISSQN no Simples')
            ->assertFormSet(['regime_apuracao_simples' => null]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\RegimeDeApuracaoDoSimples;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Domain\Enums\RetencaoIssqn;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A rejeicao E0625 recusa a nota que informa aliquota quando o prestador e
 * ME/EPP com o ISSQN apurado pelo Simples e ninguem retem: o imposto esta dentro
 * da guia unica, e a aliquota e a da tabela do Simples, nao a do municipio.
 *
 * O projeto informava a aliquota do cadastro em toda nota, e a rejeicao so
 * apareceu na transmissao.
 */
class AliquotaNoSimplesNacionalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{RegimeSimplesNacional, RegimeDeApuracaoDoSimples|null, RetencaoIssqn}  $regime
     */
    #[DataProvider('regimes')]
    public function test_a_dps_informa_aliquota_so_quando_ha_aliquota_municipal(array $regime, bool $declara): void
    {
        [$optante, $apuracao, $retencao] = $regime;

        $empresa = Empresa::factory()->create([
            'regime_simples_nacional' => $optante,
            'regime_apuracao_simples' => $apuracao,
        ]);

        $nota = Nota::factory()->create([
            'empresa_id' => $empresa->getKey(),
            'retencao_issqn' => $retencao,
            'aliquota_iss' => 5,
            'valor_servico' => 1000,
        ]);

        $corpo = $nota->valoresDoServico()->paraApi();

        $declara
            ? $this->assertSame(5.0, $corpo['tribMun']['pAliq'])
            : $this->assertNull($corpo['tribMun']['pAliq']);
    }

    /**
     * @return array<string, array{array{RegimeSimplesNacional, RegimeDeApuracaoDoSimples|null, RetencaoIssqn}, bool}>
     */
    public static function regimes(): array
    {
        return [
            'ME/EPP pelo Simples, não retido' => [
                [RegimeSimplesNacional::OptanteMicroEmpresa, RegimeDeApuracaoDoSimples::FederaisEMunicipalPeloSimples, RetencaoIssqn::NaoRetido],
                false,
            ],
            'ME/EPP pelo Simples, retido pelo tomador' => [
                [RegimeSimplesNacional::OptanteMicroEmpresa, RegimeDeApuracaoDoSimples::FederaisEMunicipalPeloSimples, RetencaoIssqn::RetidoPeloTomador],
                true,
            ],
            'ME/EPP com o ISSQN por fora do Simples' => [
                [RegimeSimplesNacional::OptanteMicroEmpresa, RegimeDeApuracaoDoSimples::FederaisPeloSimplesIssqnPorFora, RetencaoIssqn::NaoRetido],
                true,
            ],
            'não optante' => [
                [RegimeSimplesNacional::NaoOptante, null, RetencaoIssqn::NaoRetido],
                true,
            ],
        ];
    }

    /**
     * Sem aliquota a declarar nao ha ISSQN neste documento: ele sai na guia
     * unica. A previa mostrava base vezes aliquota, um imposto que a nota nao
     * carrega.
     */
    public function test_sem_aliquota_a_declarar_o_issqn_da_nota_e_zero(): void
    {
        $empresa = Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::OptanteMicroEmpresa,
            'regime_apuracao_simples' => RegimeDeApuracaoDoSimples::FederaisEMunicipalPeloSimples,
        ]);

        $nota = Nota::factory()->create([
            'empresa_id' => $empresa->getKey(),
            'retencao_issqn' => RetencaoIssqn::NaoRetido,
            'aliquota_iss' => 5,
            'valor_servico' => 1000,
        ]);

        $valores = $nota->valoresDoServico();

        $this->assertSame(0.0, $valores->issqnDevido()->emReais());
        $this->assertSame(1000.0, $valores->valorLiquido()->emReais());
    }

    /**
     * A tela nao pode discordar do documento: se a DPS nao informa aliquota, o
     * formulario nao a pede, e diz por que. Ausencia sem explicacao pareceria
     * campo esquecido.
     */
    public function test_a_tela_esconde_a_aliquota_e_explica_o_motivo(): void
    {
        $this->actingAs(User::factory()->create());

        Empresa::factory()->create([
            'regime_simples_nacional' => RegimeSimplesNacional::OptanteMicroEmpresa,
            'regime_apuracao_simples' => RegimeDeApuracaoDoSimples::FederaisEMunicipalPeloSimples,
        ]);

        Livewire::test(CreateNota::class)
            ->assertDontSee('Alíquota do ISS')
            ->assertSee('O ISSQN deste emitente sai na guia única do Simples')
            ->fillForm(['tem_retencao' => true, 'retencao_issqn' => RetencaoIssqn::RetidoPeloTomador->value])
            ->assertSee('Alíquota do ISS');
    }
}

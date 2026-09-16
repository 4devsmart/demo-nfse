<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Consultas\PadroesDaEmpresa;
use App\Filament\Resources\Empresas\Pages\EditEmpresa;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Filament\Schemas\Campos;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Filament\Support\RawJs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O percentual do cadastro do emitente chega à nota no formato da máscara.
 *
 * A coluna é `decimal:4`, e o cast devolve "2.0000". A máscara do campo é a
 * brasileira, vírgula decimal e ponto de milhar, e lê aquele ponto como milhar:
 * o emitente cadastrado com ISS de 2% abria a nota sugerindo 20.000. Na edição
 * não acontecia, porque o registro passa por `formatStateUsing`; o padrão do
 * emitente e a troca de emitente não passavam.
 */
class PercentuaisDoEmitenteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_a_nota_nova_abre_com_a_aliquota_do_emitente_como_a_mascara_le(): void
    {
        Empresa::factory()->create([
            'aliquota_iss_padrao' => 2,
            'aliquota_pis_padrao' => 0.65,
            'aliquota_cofins_padrao' => 3,
        ]);

        Livewire::test(CreateNota::class)
            ->assertFormSet([
                'aliquota_iss' => '2',
                'aliquota_pis' => '0,65',
                'aliquota_cofins' => '3',
            ]);
    }

    public function test_trocar_de_emitente_traz_os_percentuais_como_a_mascara_le(): void
    {
        Empresa::factory()->create(['aliquota_iss_padrao' => 5]);
        $outra = Empresa::factory()->create([
            'aliquota_iss_padrao' => 2.75,
            'aliquota_pis_padrao' => 0.65,
            'aliquota_irrf_padrao' => 1.5,
        ]);

        Livewire::test(CreateNota::class)
            ->fillForm(['empresa_id' => $outra->getKey()])
            ->assertFormSet([
                'aliquota_iss' => '2,75',
                'aliquota_pis' => '0,65',
                'aliquota_irrf' => '1,5',
            ]);
    }

    /**
     * A lista de percentuais precisa acompanhar o mapeamento. Campo herdado que
     * a nota guarda em quatro casas e ficou fora dela voltaria a chegar cru à
     * máscara, sem teste nenhum perceber.
     */
    public function test_todo_percentual_herdado_esta_na_lista(): void
    {
        $empresa = Empresa::factory()->create();
        $casts = (new Nota)->getCasts();

        $emQuatroCasas = array_values(array_filter(
            array_keys(app(PadroesDaEmpresa::class)->paraNota($empresa->getKey())),
            static fn (string $campo): bool => ($casts[$campo] ?? null) === 'decimal:4',
        ));

        $this->assertEqualsCanonicalizing($emQuatroCasas, PadroesDaEmpresa::PERCENTUAIS);
    }

    /**
     * Padrão brasileiro: vírgula nas casas decimais. A máscara descarta o ponto
     * digitado; o que chega com ponto por outro caminho é recusado com a razão,
     * e não lido como 2,75 nem como 275.
     */
    public function test_percentual_com_ponto_e_recusado_com_a_razao(): void
    {
        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->fillForm(['aliquota_iss_padrao' => '2.75'])
            ->call('save')
            ->assertHasFormErrors(['aliquota_iss_padrao'])
            ->assertSee('Use vírgula nas casas decimais: 2,75, e não 2.75.');
    }

    #[DataProvider('percentuaisComVirgula')]
    public function test_percentual_com_virgula_grava_o_numero(string $digitado, string $gravado): void
    {
        $empresa = Empresa::factory()->create();

        Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()])
            ->fillForm(['aliquota_iss_padrao' => $digitado])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($gravado, $empresa->refresh()->aliquota_iss_padrao);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function percentuaisComVirgula(): array
    {
        return [
            'inteiro' => ['5', '5.0000'],
            'duas casas' => ['2,75', '2.7500'],
            'quatro casas' => ['0,6534', '0.6534'],
        ];
    }

    /**
     * O ponto é barrado antes de entrar, e não limpo depois: limpo depois,
     * colar "0.65" virava "065", 65%. Os bloqueios chegam ao input renderizado,
     * e não só à configuração do campo.
     */
    public function test_o_input_do_percentual_barra_o_ponto_na_digitacao_e_na_colagem(): void
    {
        $empresa = Empresa::factory()->create();

        $tela = Livewire::test(EditEmpresa::class, ['record' => $empresa->getKey()]);

        foreach (Campos::SEM_PONTO as $atributo => $bloqueio) {
            $tela->assertSeeHtml("{$atributo}=\"{$bloqueio}\"");
        }
    }

    /**
     * A máscara vai sem separador de milhar: é o que tira o ponto do campo.
     */
    public function test_a_mascara_do_percentual_nao_tem_ponto(): void
    {
        $mascara = Campos::percentual('aliquota_iss', 'ISS')->getMask();

        $this->assertInstanceOf(RawJs::class, $mascara);
        $this->assertSame("\$money(\$input, ',', '', 4)", (string) $mascara);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servicos\ImportarCodigosDeTributacaoNacional;
use App\Actions\Servicos\ImportarListaDeServicos;
use App\Domain\Enums\TributacaoIssqn;
use App\Filament\Resources\Notas\Pages\CreateNota;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Os limites que a lei fixa e a tela precisa dizer. Nenhum deles trava a
 * emissao: quem responde pelo conteudo fiscal e quem emite, e a lei do
 * municipio, que este sistema nao conhece, pode explicar o numero.
 */
class LimitesLegaisDaTelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        app(ImportarListaDeServicos::class)->executar();
        app(ImportarCodigosDeTributacaoNacional::class)->executar();
        Empresa::factory()->create();
    }

    /**
     * LC 116, art. 8º, II: teto de 5%. Art. 8º-A: piso de 2%, com a ressalva do
     * §1º para os subitens 7.02, 7.05 e 16.01.
     */
    #[DataProvider('aliquotas')]
    public function test_a_tela_aponta_o_piso_e_o_teto_do_iss(string $codigo, string $aliquota, ?string $aviso): void
    {
        $pagina = Livewire::test(CreateNota::class)
            ->fillForm(['codigo_servico' => $codigo, 'aliquota_iss' => $aliquota]);

        $aviso === null
            ? $pagina->assertDontSee('LC 116, art. 8')
            : $pagina->assertSee($aviso);
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function aliquotas(): array
    {
        return [
            'dentro da faixa' => ['010701', '5', null],
            'acima do teto' => ['010701', '5,5', 'Acima do teto de 5% da LC 116, art. 8º, II.'],
            'abaixo do piso' => ['010701', '1,5', 'Abaixo do piso de 2% da LC 116, art. 8º-A.'],
            'abaixo do piso no subitem 07.02' => ['070201', '1,5', null],
            'abaixo do piso no subitem 16.01' => ['160101', '1,5', null],
        ];
    }

    /**
     * A alíquota some das três tributações que não têm imposto: exigir um
     * percentual ali é pedir um número que não se aplica.
     */
    public function test_a_aliquota_some_quando_nao_ha_issqn(): void
    {
        Livewire::test(CreateNota::class)
            ->assertSee('Alíquota do ISS')
            ->fillForm(['tributacao_issqn' => TributacaoIssqn::Imunidade->value])
            ->assertDontSee('Alíquota do ISS')
            ->fillForm(['tributacao_issqn' => TributacaoIssqn::OperacaoTributavel->value])
            ->assertSee('Alíquota do ISS');
    }

    /**
     * Lei 10.833/2003, art. 31, §3º: dispensada a retenção das três
     * contribuições em pagamento de até cinco mil reais. O §4º manda somar os
     * pagamentos do mês, e é por isso que a tela avisa em vez de desmarcar.
     */
    public function test_a_tela_aponta_a_dispensa_da_csrf_ate_cinco_mil(): void
    {
        Livewire::test(CreateNota::class)
            ->fillForm(['tem_retencao_federal' => true, 'valor_servico' => '1.000,00'])
            ->fillForm(['contribuicoes_retidas' => ['pis', 'cofins', 'csll']])
            ->assertSee('A Lei 10.833/2003 dispensa a retenção das três em pagamento de até R$ 5.000,00')
            ->fillForm(['valor_servico' => '6.000,00'])
            ->assertDontSee('A Lei 10.833/2003 dispensa');
    }

    /**
     * LC 116, art. 7º, §2º, I: da base só sai o material dos subitens 7.02 e
     * 7.05. O campo não dizia nada e reduzia a base de qualquer serviço.
     */
    public function test_o_campo_de_deducoes_diz_o_que_a_lei_permite(): void
    {
        Livewire::test(CreateNota::class)->assertSee('subitens 7.02 e 7.05');
    }
}

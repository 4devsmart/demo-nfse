<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\StatusNota;
use App\Domain\Enums\TributacaoIssqn;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Nota;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Nota>
 */
class NotaFactory extends Factory
{
    protected $model = Nota::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'cliente_id' => Cliente::factory(),
            'cidade_prestacao_id' => fn (): int => CidadeFactory::rio()->getKey(),
            'referencia' => (string) Str::ulid(),
            'serie' => '1',
            'numero' => fake()->unique()->numberBetween(1, 100000),
            'competencia' => now()->startOfMonth(),
            'ambiente' => Ambiente::Homologacao,
            'status' => StatusNota::Rascunho,
            'descricao_servico' => 'Desenvolvimento de software sob encomenda',
            'codigo_servico' => fn (): string => CodigoDeTributacaoNacionalFactory::suporteEmInformatica()->codigo,
            'item_lista_servico' => fn (): string => ItemDaListaDeServicosFactory::suporteEmInformatica()->pontuado(),
            'cnae' => '6201501',
            'valor_servico' => 1500.50,
            'aliquota_iss' => 5,
            'deducoes' => 0,
            'desconto_incondicionado' => 0,
            'desconto_condicionado' => 0,
            'tributacao_issqn' => TributacaoIssqn::OperacaoTributavel,
            'retencao_issqn' => RetencaoIssqn::NaoRetido,
        ];
    }

    public function comDpsGerada(): static
    {
        return $this->state(fn (): array => [
            'status' => StatusNota::DpsGerada,
            'id_dps' => 'DPS330455721913124300019700001000000000000001',
            'xml_dps' => base64_encode('<DPS/>'),
            'provedor' => 'PadraoNacional (padrao_nacional)',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IndicadorDeOperacao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndicadorDeOperacao>
 */
class IndicadorDeOperacaoFactory extends Factory
{
    protected $model = IndicadorDeOperacao::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'tipo_operacao' => 'Serviço prestado fisicamente sobre bem imóvel',
            'caracteristica' => 'Execução de serviços diversos prestados fisicamente sobre bem imóvel',
            'local_do_fornecimento' => 'Localidade do imóvel',
            'dispositivo_legal' => 'Art. 11, II e §2º',
        ];
    }
}

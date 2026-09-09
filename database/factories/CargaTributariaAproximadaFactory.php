<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CargaTributariaAproximada;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CargaTributariaAproximada>
 */
class CargaTributariaAproximadaFactory extends Factory
{
    protected $model = CargaTributariaAproximada::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => '0107',
            'uf' => 'RJ',
            'descricao' => 'Suporte técnico em informática.',
            'percentual_federal' => 13.45,
            'percentual_federal_importado' => 15.45,
            'percentual_estadual' => 0,
            'percentual_municipal' => 2.95,
            'vigencia_inicio' => now()->subMonth()->toDateString(),
            'vigencia_fim' => now()->addMonth()->toDateString(),
            'versao' => '26.2.A',
        ];
    }

    public function vencida(): self
    {
        return $this->state(fn (): array => [
            'vigencia_inicio' => now()->subYear()->toDateString(),
            'vigencia_fim' => now()->subDay()->toDateString(),
            'versao' => '25.1.A',
        ]);
    }
}

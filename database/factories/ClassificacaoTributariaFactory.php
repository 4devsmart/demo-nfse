<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ClassificacaoTributaria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassificacaoTributaria>
 */
class ClassificacaoTributariaFactory extends Factory
{
    protected $model = ClassificacaoTributaria::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'cst' => '000',
            'nome_cst' => 'Tributação integral',
            'descricao' => 'Situações tributadas integralmente pelo IBS e CBS.',
            'percentual_reducao_ibs' => 0,
            'percentual_reducao_cbs' => 0,
            'exige_tributo' => true,
            'permite_credito_presumido' => false,
            'tributacao_regular' => false,
            'vigencia_inicio' => '2025-05-05',
            'vigencia_fim' => null,
            'url_legislacao' => null,
        ];
    }

    /**
     * Classificacao ja revogada: continua na tabela para explicar nota antiga,
     * mas nao pode aparecer para escolher numa nota nova.
     */
    public function revogada(): self
    {
        return $this->state(fn (): array => ['vigencia_fim' => now()->subDay()->toDateString()]);
    }
}

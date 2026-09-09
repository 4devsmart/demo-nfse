<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cidade>
 */
class CidadeFactory extends Factory
{
    private const DADOS_DO_RIO = [
        'codigo_ibge' => '3304557',
        'nome' => 'Rio de Janeiro',
        'uf' => 'RJ',
    ];

    protected $model = Cidade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo_ibge' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'nome' => fake()->city(),
            'uf' => fake()->randomElement(['SP', 'RJ', 'MG', 'RS', 'PR']),
        ];
    }

    public function rioDeJaneiro(): static
    {
        return $this->state(fn (): array => self::DADOS_DO_RIO);
    }

    /**
     * O municipio da demonstracao, criado uma vez por teste. O codigo IBGE e
     * unico: duas fabricas pedindo "Rio de Janeiro" precisam receber a mesma
     * linha, nao duas.
     */
    public static function rio(): Cidade
    {
        return Cidade::query()->firstOrCreate(
            ['codigo_ibge' => self::DADOS_DO_RIO['codigo_ibge']],
            self::DADOS_DO_RIO,
        );
    }
}

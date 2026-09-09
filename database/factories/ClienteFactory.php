<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoPessoa;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_pessoa' => TipoPessoa::Juridica,
            'cpf_cnpj' => fake('pt_BR')->unique()->cnpj(false),
            'razao_social' => fake()->company(),
            'inscricao_municipal' => '7654321',
            'cidade_id' => fn (): int => CidadeFactory::rio()->getKey(),
            'cep' => '20031170',
            'logradouro' => 'Avenida Rio Branco',
            'numero' => '100',
            'bairro' => 'Centro',
            'telefone' => '2144445555',
            'email' => 'contas@exemplo.test',
        ];
    }
}

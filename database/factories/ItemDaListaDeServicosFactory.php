<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ItemDaListaDeServicos;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemDaListaDeServicos>
 */
class ItemDaListaDeServicosFactory extends Factory
{
    /**
     * O subitem que a demonstracao usa, com o texto que a LC 116 lhe da.
     *
     * @var array{codigo: string, descricao: string}
     */
    public const SUPORTE_EM_INFORMATICA = [
        'codigo' => '0107',
        'descricao' => 'Suporte técnico em informática, inclusive instalação, configuração e manutenção de programas de computação e bancos de dados.',
    ];

    protected $model = ItemDaListaDeServicos::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'descricao' => self::SUPORTE_EM_INFORMATICA['descricao'],
        ];
    }

    /**
     * O subitem da demonstracao, criado uma vez por teste. Como o municipio do
     * Rio: as fabricas que dependem dele precisam receber a mesma linha, e o
     * seletor da tela so aceita codigo que exista na tabela.
     */
    public static function suporteEmInformatica(): ItemDaListaDeServicos
    {
        return ItemDaListaDeServicos::query()->firstOrCreate(
            ['codigo' => self::SUPORTE_EM_INFORMATICA['codigo']],
            self::SUPORTE_EM_INFORMATICA,
        );
    }
}

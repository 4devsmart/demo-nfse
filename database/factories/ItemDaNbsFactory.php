<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ItemDaNbs;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemDaNbs>
 */
class ItemDaNbsFactory extends Factory
{
    /**
     * Uma das quatro NBS do subitem 01.07, que e o servico da demonstracao.
     *
     * @var array{item_lista_servico: string, nbs: string, descricao: string}
     */
    public const SUPORTE_EM_INFORMATICA = [
        'item_lista_servico' => '0107',
        'nbs' => '1.1501.30.00',
        'descricao' => 'Serviços de suporte técnico em tecnologia da informação',
    ];

    protected $model = ItemDaNbs::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_lista_servico' => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'nbs' => '1.'.fake()->unique()->numerify('####').'.10.00',
            'descricao' => self::SUPORTE_EM_INFORMATICA['descricao'],
        ];
    }

    /**
     * A NBS da demonstracao, criada uma vez por teste, como o municipio do Rio.
     */
    public static function suporteEmInformatica(): ItemDaNbs
    {
        return ItemDaNbs::query()->firstOrCreate(
            [
                'item_lista_servico' => self::SUPORTE_EM_INFORMATICA['item_lista_servico'],
                'nbs' => self::SUPORTE_EM_INFORMATICA['nbs'],
            ],
            self::SUPORTE_EM_INFORMATICA,
        );
    }
}

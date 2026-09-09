<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CodigoDeTributacaoNacional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodigoDeTributacaoNacional>
 */
class CodigoDeTributacaoNacionalFactory extends Factory
{
    /**
     * O desdobramento unico do subitem 01.07 na tabela nacional.
     *
     * @var array{codigo: string, item_lista_servico: string, descricao: string}
     */
    public const SUPORTE_EM_INFORMATICA = [
        'codigo' => '010701',
        'item_lista_servico' => '0107',
        'descricao' => 'Suporte técnico em informática, inclusive instalação, configuração e manutenção de programas de computação e bancos de dados.',
    ];

    protected $model = CodigoDeTributacaoNacional::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $codigo = str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'codigo' => $codigo,
            'item_lista_servico' => substr($codigo, 0, 4),
            'descricao' => self::SUPORTE_EM_INFORMATICA['descricao'],
        ];
    }

    /**
     * O codigo da demonstracao, com o subitem da LC 116 que ele detalha. Os
     * dois vem juntos porque a tela liga um ao outro: escolher o codigo
     * preenche o item.
     */
    public static function suporteEmInformatica(): CodigoDeTributacaoNacional
    {
        ItemDaListaDeServicosFactory::suporteEmInformatica();

        return CodigoDeTributacaoNacional::query()->firstOrCreate(
            ['codigo' => self::SUPORTE_EM_INFORMATICA['codigo']],
            self::SUPORTE_EM_INFORMATICA,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Domain\ValueObjects\Dinheiro;
use BackedEnum;

/**
 * O estado de um formulario Filament nao tem tipo fixo: o mesmo campo chega
 * como enum quando vem do default ou do banco, e como string crua quando vem do
 * que foi digitado. Converter no ponto de leitura, uma vez, evita espalhar
 * casts frageis por toda tela que consome esse estado.
 */
final readonly class EstadoDoFormulario
{
    /**
     * @param  array<string, mixed>  $estado
     */
    public static function inteiro(array $estado, string $campo, int $padrao = 0): int
    {
        $valor = $estado[$campo] ?? null;

        if ($valor instanceof BackedEnum) {
            return (int) $valor->value;
        }

        return is_numeric($valor) ? (int) $valor : $padrao;
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    public static function numero(array $estado, string $campo, float $padrao = 0.0): float
    {
        $valor = $estado[$campo] ?? null;

        if (is_float($valor) || is_int($valor)) {
            return (float) $valor;
        }

        if (! is_string($valor) || trim($valor) === '') {
            return $padrao;
        }

        // Campo com mascara chega como "1.500,50": e Dinheiro quem sabe ler isso.
        return Dinheiro::numeroDoTexto($valor);
    }

    /**
     * O que uma lista de marcacao devolve. Campo escondido nao chega ao estado,
     * e ai a resposta e "nada marcado", nao "todas".
     *
     * @param  array<string, mixed>  $estado
     * @return list<string>
     */
    public static function lista(array $estado, string $campo): array
    {
        $valor = $estado[$campo] ?? null;

        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) (is_scalar($item) ? $item : ''), $valor));
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    public static function texto(array $estado, string $campo): string
    {
        $valor = $estado[$campo] ?? null;

        if ($valor instanceof BackedEnum) {
            return (string) $valor->value;
        }

        return is_scalar($valor) ? trim((string) $valor) : '';
    }
}

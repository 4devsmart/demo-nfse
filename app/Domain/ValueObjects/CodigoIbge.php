<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * Codigo IBGE do municipio, 7 digitos. E ele que decide o provedor de NFS-e:
 * a API resolve o layout a partir dele e nao aceita override.
 */
final readonly class CodigoIbge implements Stringable
{
    private const TAMANHO = 7;

    private function __construct(public string $valor) {}

    public static function deSeteDigitos(string $codigo): self
    {
        $digitos = preg_replace('/\D/', '', $codigo) ?? '';

        if (strlen($digitos) !== self::TAMANHO) {
            throw new InvalidArgumentException("Código IBGE precisa ter 7 dígitos: {$codigo}.");
        }

        return new self($digitos);
    }

    public static function ehValido(string $codigo): bool
    {
        return strlen(preg_replace('/\D/', '', $codigo) ?? '') === self::TAMANHO;
    }

    public function __toString(): string
    {
        return $this->valor;
    }
}

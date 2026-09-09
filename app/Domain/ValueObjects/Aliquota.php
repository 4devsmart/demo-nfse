<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * Percentual de aliquota. A API recebe em percentual (`5` = 5%), nao em fracao.
 */
final readonly class Aliquota implements Stringable
{
    private function __construct(public float $percentual) {}

    public static function deQuatroCasas(float|int|string $percentual): self
    {
        if (! is_numeric($percentual)) {
            throw new InvalidArgumentException("Alíquota inválida: {$percentual}.");
        }

        $valor = round((float) $percentual, 4);

        if ($valor < 0 || $valor > 100) {
            throw new InvalidArgumentException("Alíquota fora da faixa 0–100: {$valor}.");
        }

        return new self($valor);
    }

    public function ehZero(): bool
    {
        return $this->percentual === 0.0;
    }

    public function emFracao(): float
    {
        return $this->percentual / 100;
    }

    public function formatada(): string
    {
        return number_format($this->percentual, 2, ',', '.').'%';
    }

    public function __toString(): string
    {
        return number_format($this->percentual, 2, '.', '');
    }
}

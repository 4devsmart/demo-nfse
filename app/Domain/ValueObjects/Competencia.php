<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;
use Stringable;

/**
 * Mes de competencia do servico (`infDPS.dCompet`). A API quer AAAA-MM-DD; o
 * dia nao carrega significado, entao normalizamos para o primeiro do mes.
 *
 * Guarda `DateTimeImmutable`, e nao `Carbon`: aqui a data so precisa de parse,
 * primeiro dia do mes e dois formatos, e nada disso pede o Carbon. O `date` do
 * Eloquent entrega Carbon, que e um `DateTimeInterface`, entao a fronteira
 * continua funcionando.
 */
final readonly class Competencia implements Stringable
{
    private function __construct(public DateTimeImmutable $primeiroDiaDoMes) {}

    public static function noMesDe(DateTimeInterface|string $data): self
    {
        $momento = $data instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($data)
            : new DateTimeImmutable($data);

        return new self($momento->modify('first day of this month')->setTime(0, 0));
    }

    public function emIso(): string
    {
        return $this->primeiroDiaDoMes->format('Y-m-d');
    }

    public function rotulo(): string
    {
        return $this->primeiroDiaDoMes->format('m/Y');
    }

    public function __toString(): string
    {
        return $this->emIso();
    }
}

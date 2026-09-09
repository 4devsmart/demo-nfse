<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Colecao de primeira classe: erros e alertas do provedor nunca circulam como
 * array solto.
 *
 * @implements IteratorAggregate<int, Mensagem>
 */
final readonly class Mensagens implements Countable, IteratorAggregate
{
    /**
     * @param  list<Mensagem>  $itens
     */
    private function __construct(private array $itens) {}

    /**
     * Que o argumento seja lista, quem garante e o LeitorDaResposta. O que ele
     * nao garante e o formato de cada item: o contrato manda objeto, e uma
     * string solta no meio nao pode derrubar a leitura de uma resposta que pode
     * trazer nota autorizada.
     *
     * @param  array<int|string, mixed>  $brutas
     */
    public static function daLista(array $brutas): self
    {
        return new self(array_values(array_map(
            static fn (mixed $bruta): Mensagem => Mensagem::comoVeio($bruta),
            $brutas,
        )));
    }

    public static function vazia(): self
    {
        return new self([]);
    }

    public function count(): int
    {
        return count($this->itens);
    }

    public function getIterator(): Traversable
    {
        yield from $this->itens;
    }

    /**
     * @return list<array{codigo: string, descricao: string}>
     */
    public function paraArray(): array
    {
        return array_map(
            static fn (Mensagem $mensagem): array => [
                'codigo' => $mensagem->codigo,
                'descricao' => $mensagem->descricao,
            ],
            $this->itens,
        );
    }

    public function emLinhas(): string
    {
        return implode("\n", array_map(strval(...), $this->itens));
    }
}

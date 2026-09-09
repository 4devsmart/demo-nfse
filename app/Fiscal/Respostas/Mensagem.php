<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

use Stringable;

final readonly class Mensagem implements Stringable
{
    public function __construct(
        public string $codigo,
        public string $descricao,
    ) {}

    /**
     * O contrato manda `{"codigo","descricao"}`. Quando vem outra coisa, uma
     * string solta, um numero, guardar o texto como descricao preserva o que
     * o provedor disse; recusar perderia a resposta inteira, e ela pode ser o
     * retorno de uma nota ja autorizada.
     */
    public static function comoVeio(mixed $bruta): self
    {
        if (is_array($bruta)) {
            return self::doArray($bruta);
        }

        return new self('', is_scalar($bruta) ? trim((string) $bruta) : '');
    }

    /**
     * @param  array<array-key, mixed>  $bruta
     */
    private static function doArray(array $bruta): self
    {
        $lida = new LeitorDaResposta($bruta);
        $codigo = $lida->texto('codigo');
        $descricao = $lida->texto('descricao');

        if ($codigo === '' && $descricao === '') {
            return self::daListaPosicional($bruta);
        }

        return new self($codigo, $descricao);
    }

    /**
     * Ha provedor que manda a mensagem como lista, `["E123", "Aliquota
     * divergente"]`, sem as chaves do contrato. Lendo so pelas chaves, isso
     * virava `Mensagem('', '')` e a rejeicao chegava na tela sem motivo
     * nenhum. Aqui os escalares viram codigo e descricao na ordem em que
     * vieram; com um item so, ele e a descricao, porque texto sem codigo ainda
     * explica, e codigo sem texto nao.
     *
     * @param  array<array-key, mixed>  $bruta
     */
    private static function daListaPosicional(array $bruta): self
    {
        $partes = array_values(array_map(
            static fn (mixed $parte): string => trim((string) $parte),
            array_filter($bruta, static fn (mixed $parte): bool => is_scalar($parte)),
        ));

        return match (count($partes)) {
            0 => new self('', ''),
            1 => new self('', $partes[0]),
            default => new self($partes[0], implode(' ', array_slice($partes, 1))),
        };
    }

    public function __toString(): string
    {
        return trim("{$this->codigo} {$this->descricao}");
    }
}

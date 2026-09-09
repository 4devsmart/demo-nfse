<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Leitura de cadeia fluente, que e o formato em que o Filament e escrito.
 *
 * O Deptrac responde "a classe A pode citar a classe B?". Estas regras
 * respondem outra coisa: o que foi chamado, com que argumento, e o que faltou
 * ser chamado. E por isso que elas vivem no PHPStan, que ja tem a arvore.
 */
final class Cadeia
{
    /**
     * Os nomes de metodo chamados em qualquer ponto dentro de `$raiz`.
     *
     * @return list<string>
     */
    public static function nomesDentroDe(Node $raiz): array
    {
        $nomes = [];

        foreach ((new NodeFinder)->findInstanceOf($raiz, MethodCall::class) as $chamada) {
            if ($chamada->name instanceof Identifier) {
                $nomes[] = $chamada->name->name;
            }
        }

        return $nomes;
    }

    /**
     * Os nomes de metodo da cadeia inteira em que `$chamada` esta.
     *
     * `Select::make('x')->options([...])->searchable()`: parado no no de
     * `options`, o `searchable` esta acima dele, e `->var` so anda para baixo.
     * O de cima e achado procurando, dentro de `$raiz`, quem tem este no como
     * `var`.
     *
     * @return list<string>
     */
    public static function nomesDaCadeiaDe(Node $raiz, MethodCall $chamada): array
    {
        $nomes = [];

        $abaixo = $chamada;

        while ($abaixo instanceof MethodCall) {
            if ($abaixo->name instanceof Identifier) {
                $nomes[] = $abaixo->name->name;
            }

            $abaixo = $abaixo->var;
        }

        $procurador = new NodeFinder;
        $filho = $chamada;

        while (true) {
            $acima = $procurador->findFirst(
                $raiz,
                static fn (Node $no): bool => $no instanceof MethodCall && $no->var === $filho,
            );

            if (! $acima instanceof MethodCall) {
                break;
            }

            if ($acima->name instanceof Identifier) {
                $nomes[] = $acima->name->name;
            }

            $filho = $acima;
        }

        return $nomes;
    }

    /**
     * O primeiro argumento de uma chamada, quando ele e literal de texto.
     */
    public static function primeiroTexto(MethodCall|StaticCall $chamada): ?string
    {
        $argumentos = $chamada->getArgs();

        if ($argumentos === []) {
            return null;
        }

        $valor = $argumentos[0]->value;

        return $valor instanceof String_ ? $valor->value : null;
    }

    /**
     * O escopo vem pelo namespace, e nao pelo caminho: no container o projeto
     * inteiro mora em `/app`, entao procurar `/app/` no caminho da verdadeiro
     * para todo arquivo, `tests/` incluido.
     */
    public static function ehTela(?string $namespace): bool
    {
        return $namespace !== null && str_starts_with($namespace, 'App\\Filament');
    }

    /**
     * A regra do icone vale em toda a aplicacao. `Domain/` conhece o framework
     * e `StatusNota::getIcon()` devolve `Heroicon`, entao icone escrito como
     * texto e engano em qualquer pasta de `app/`.
     */
    public static function ehAplicacao(?string $namespace): bool
    {
        return $namespace !== null && str_starts_with($namespace, 'App\\');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Quem monta a tabela, de verdade.
 *
 * `NotaResource::table()` so delega para `NotasTable::configure()`, e as duas
 * assinaturas sao iguais. O que separa uma da outra e `->columns()`: quem
 * declara coluna e quem responde pela tabela, e e nele que as regras batem.
 */
final class MontagemDeTabela
{
    public static function ehMontador(ClassMethod $metodo): bool
    {
        $retorno = $metodo->returnType;

        // Tipo composto (`?X`, `A|B`) nunca e o retorno de um montador de
        // tabela, e `(string)` sobre ele estoura.
        if (! $retorno instanceof Identifier && ! $retorno instanceof Name) {
            return false;
        }

        if (! str_ends_with($retorno->toString(), 'Table')) {
            return false;
        }

        return in_array('columns', Cadeia::nomesDentroDe($metodo), true);
    }
}

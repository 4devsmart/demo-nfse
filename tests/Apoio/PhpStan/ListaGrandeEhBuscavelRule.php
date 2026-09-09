<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Lista longa sem busca faz rolar atras da opcao. O `searchable()` resolve, e o
 * corte precisa ser em algum numero: abaixo dele a busca atrapalha mais do que
 * ajuda, porque some com a lista inteira atras de um campo de texto.
 *
 * Le a cadeia toda do componente, e nao so o `options()`, porque o
 * `searchable()` vem depois dele.
 *
 * @implements Rule<ClassMethod>
 */
final class ListaGrandeEhBuscavelRule implements Rule
{
    private const OPCOES_ATE_ONDE_SE_ROLA = 10;

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @param  ClassMethod  $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! Cadeia::ehTela($scope->getNamespace())) {
            return [];
        }

        $erros = [];

        foreach ((new NodeFinder)->findInstanceOf($node, MethodCall::class) as $chamada) {
            if (! $chamada->name instanceof Identifier || $chamada->name->name !== 'options') {
                continue;
            }

            $argumentos = $chamada->getArgs();

            if ($argumentos === [] || ! $argumentos[0]->value instanceof Array_) {
                continue;
            }

            $opcoes = count($argumentos[0]->value->items);

            if ($opcoes <= self::OPCOES_ATE_ONDE_SE_ROLA) {
                continue;
            }

            if (in_array('searchable', Cadeia::nomesDaCadeiaDe($node, $chamada), true)) {
                continue;
            }

            $erros[] = RuleErrorBuilder::message(sprintf(
                'Lista com %d opções sem `->searchable()`: acima de %d se rola atrás da opção.',
                $opcoes,
                self::OPCOES_ATE_ONDE_SE_ROLA,
            ))
                ->identifier('nfse.listaGrandeSemBusca')
                ->line($chamada->getStartLine())
                ->build();
        }

        return $erros;
    }
}

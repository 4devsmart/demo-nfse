<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `unique(ignoreRecord: true)` repete o padrao. O Filament resolve o argumento
 * com `$ignoreRecord ??= $component->shouldUniqueValidationIgnoreRecordByDefault()`,
 * e essa propriedade nasce `true`. Escrever o argumento nao muda nada e sugere,
 * para quem le, que exista um caso em que a regra seria outra.
 *
 * Isto o Deptrac nao alcanca: a classe citada e a mesma com e sem o argumento.
 *
 * @implements Rule<MethodCall>
 */
final class UniqueSemIgnoreRecordRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param  MethodCall  $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! Cadeia::ehTela($scope->getNamespace())) {
            return [];
        }

        if (! $node->name instanceof Identifier || $node->name->name !== 'unique') {
            return [];
        }

        foreach ($node->getArgs() as $argumento) {
            if (! $argumento->name instanceof Identifier || $argumento->name->name !== 'ignoreRecord') {
                continue;
            }

            if (! $argumento->value instanceof ConstFetch || strtolower($argumento->value->name->toString()) !== 'true') {
                continue;
            }

            return [
                RuleErrorBuilder::message('`ignoreRecord: true` já é o padrão do Filament. Escrever de novo sugere uma escolha que não existe.')
                    ->identifier('nfse.uniqueComIgnoreRecordRedundante')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        return [];
    }
}

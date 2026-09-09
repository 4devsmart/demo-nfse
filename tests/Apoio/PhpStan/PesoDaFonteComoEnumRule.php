<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Mesmo caso do icone: `->fontWeight('semibold')` e texto solto, e
 * `FontWeight::SemiBold` e enum. Hoje o projeto nao escreve nenhum dos dois, e
 * a regra existe para o primeiro que aparecer ja nascer certo.
 *
 * @implements Rule<MethodCall>
 */
final class PesoDaFonteComoEnumRule implements Rule
{
    /** @var list<string> */
    private const PESOS = [
        'thin', 'extralight', 'light', 'normal', 'medium',
        'semibold', 'bold', 'extrabold', 'black',
    ];

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

        if (! $node->name instanceof Identifier || $node->name->name !== 'fontWeight') {
            return [];
        }

        $peso = Cadeia::primeiroTexto($node);

        if ($peso === null || ! in_array(strtolower($peso), self::PESOS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Peso de fonte como texto (`%s`). Use o enum `FontWeight`.',
                $peso,
            ))
                ->identifier('nfse.pesoDaFonteComoTexto')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}

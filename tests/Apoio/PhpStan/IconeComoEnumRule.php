<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * `'heroicon-o-clock'` e texto: erro de digitacao vira icone que nao aparece, e
 * ninguem descobre ate abrir a tela. `Heroicon::OutlinedClock` e enum, e o erro
 * passa a ser de compilacao.
 *
 * Vale em toda a aplicacao, `Domain/` incluido: os enums de la implementam os
 * contratos do Filament e devolvem `Heroicon`, entao nao ha canto onde icone
 * como texto seja a forma certa.
 *
 * Bate no literal, e nao no metodo que o recebe: `icon()`, `descriptionIcon()`
 * e `emptyStateIcon()` sao tres nomes para o mesmo engano, e texto comecando
 * por `heroicon-` nunca e outra coisa.
 *
 * O PHPStan nao le Blade, entao a mesma regra nas views e o
 * `IconesDasViewsTest`.
 *
 * @implements Rule<String_>
 */
final class IconeComoEnumRule implements Rule
{
    private const PREFIXO = 'heroicon-';

    public function getNodeType(): string
    {
        return String_::class;
    }

    /**
     * @param  String_  $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! Cadeia::ehAplicacao($scope->getNamespace())) {
            return [];
        }

        $icone = $node->value;

        if (! str_starts_with($icone, self::PREFIXO)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Ícone como texto (`%s`). Use o enum `Heroicon`, que erra em tempo de compilação.',
                $icone,
            ))
                ->identifier('nfse.iconeComoTexto')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}

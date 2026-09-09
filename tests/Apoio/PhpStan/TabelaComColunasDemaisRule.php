<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Passado um certo numero de colunas, a tabela rola na horizontal e quem le
 * para de achar o que procura. Coluna que existe para o caso raro cabe em
 * `toggleable(isToggledHiddenByDefault: true)`, que a deixa disponivel sem
 * ocupar largura.
 *
 * O teto conta so o que aparece por padrao: coluna escondida nao rouba espaco.
 *
 * @implements Rule<MethodCall>
 */
final class TabelaComColunasDemaisRule implements Rule
{
    private const TETO = 10;

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

        if (! $node->name instanceof Identifier || $node->name->name !== 'columns') {
            return [];
        }

        $argumentos = $node->getArgs();

        if ($argumentos === [] || ! $argumentos[0]->value instanceof Array_) {
            return [];
        }

        $visiveis = self::visiveis($argumentos[0]->value);

        if ($visiveis <= self::TETO) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'A tabela mostra %d colunas por padrão, acima do teto de %d. Use `toggleable(isToggledHiddenByDefault: true)` nas acessórias.',
                $visiveis,
                self::TETO,
            ))
                ->identifier('nfse.tabelaComColunasDemais')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private static function visiveis(Array_ $colunas): int
    {
        $total = 0;

        foreach ($colunas->items as $item) {
            $chamados = $item->value instanceof MethodCall
                ? Cadeia::nomesDaCadeiaDe($item->value, $item->value)
                : [];

            if (! in_array('toggleable', $chamados, true)) {
                $total++;
            }
        }

        return $total;
    }
}

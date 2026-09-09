<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Tabela sem `deferLoading()` consulta o banco durante o render da pagina, e a
 * pagina so responde quando a consulta termina. Com ele a tela chega primeiro e
 * a tabela se preenche por AJAX, o que muda o tempo ate a primeira pintura sem
 * mudar o total.
 *
 * @implements Rule<ClassMethod>
 */
final class TabelaComDeferLoadingRule implements Rule
{
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
        if (! Cadeia::ehTela($scope->getNamespace()) || ! MontagemDeTabela::ehMontador($node)) {
            return [];
        }

        if (in_array('deferLoading', Cadeia::nomesDentroDe($node), true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Tabela sem `->deferLoading()`: a página espera a consulta para pintar.')
                ->identifier('nfse.tabelaSemDeferLoading')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}

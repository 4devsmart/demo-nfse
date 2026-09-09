<?php

declare(strict_types=1);

namespace Tests\Apoio\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Coluna escrita como `cliente.razao_social` le uma relacao, e sem carregar a
 * relacao junto isso e uma consulta por linha. Numa tabela de 25 linhas com
 * duas colunas assim, sao cinquenta consultas que nao aparecem em lugar nenhum.
 *
 * A tabela satisfaz a regra de duas formas: `modifyQueryUsing()`, que e o
 * gancho do Filament para mexer na consulta padrao, ou `query()`, que e quando
 * ela traz a propria consulta e o `with()` mora la, como em `NotasRecentes`.
 *
 * @implements Rule<ClassMethod>
 */
final class TabelaComEagerLoadingRule implements Rule
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

        $relacoes = self::colunasDeRelacao($node);

        if ($relacoes === []) {
            return [];
        }

        $chamados = Cadeia::nomesDentroDe($node);

        if (in_array('modifyQueryUsing', $chamados, true) || in_array('query', $chamados, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Tabela lê relação em %s sem carregar junto: é uma consulta por linha.',
                implode(', ', $relacoes),
            ))
                ->identifier('nfse.tabelaSemEagerLoading')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function colunasDeRelacao(ClassMethod $metodo): array
    {
        $relacoes = [];

        foreach ((new NodeFinder)->findInstanceOf($metodo, StaticCall::class) as $chamada) {
            if (! $chamada->name instanceof Identifier || $chamada->name->name !== 'make') {
                continue;
            }

            $nome = Cadeia::primeiroTexto($chamada);

            if ($nome !== null && str_contains($nome, '.')) {
                $relacoes[] = "`{$nome}`";
            }
        }

        return $relacoes;
    }
}

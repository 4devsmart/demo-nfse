<?php

declare(strict_types=1);

namespace App\Actions\Classificacoes;

use App\Models\ClassificacaoTributaria;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a tabela `cClassTrib`. A fonte padrao e o arquivo que acompanha o
 * projeto, para que a demonstracao suba sem internet; `AtualizarClassificacoesPelaSvrs`
 * recarrega da origem oficial quando ha rede.
 *
 * A gravacao e `upsert` por `codigo`, e nao apaga-e-recria: codigo revogado
 * some da origem, e apagar a linha deixaria sem explicacao a nota antiga que o
 * declarou. Quem some da tela e quem tem `vigencia_fim` vencida, pelo escopo
 * `vigente()` do model.
 */
final readonly class ImportarClassificacoesTributarias
{
    private const LOTE = 200;

    public function __construct(private FonteDeClassificacoes $fonte) {}

    public function executar(): int
    {
        $classificacoes = $this->fonte->classificacoes();

        if ($classificacoes === []) {
            throw new RuntimeException(__('A fonte não devolveu nenhuma classificação tributária.'));
        }

        DB::transaction(function () use ($classificacoes): void {
            foreach (array_chunk($classificacoes, self::LOTE) as $lote) {
                ClassificacaoTributaria::query()->upsert($this->comCarimboDeTempo($lote), ['codigo'], [
                    'cst', 'nome_cst', 'descricao',
                    'percentual_reducao_ibs', 'percentual_reducao_cbs',
                    'exige_tributo', 'permite_credito_presumido', 'tributacao_regular',
                    'vigencia_inicio', 'vigencia_fim', 'url_legislacao', 'updated_at',
                ]);
            }
        });

        return count($classificacoes);
    }

    /**
     * @param  list<array<string, mixed>>  $lote
     * @return list<array<string, mixed>>
     */
    private function comCarimboDeTempo(array $lote): array
    {
        $agora = now()->toDateTimeString();

        return array_map(
            static fn (array $classificacao): array => [...$classificacao, 'created_at' => $agora, 'updated_at' => $agora],
            $lote,
        );
    }
}

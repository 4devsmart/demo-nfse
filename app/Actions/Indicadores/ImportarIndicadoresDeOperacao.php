<?php

declare(strict_types=1);

namespace App\Actions\Indicadores;

use App\Models\IndicadorDeOperacao;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega a tabela `cIndOp` a partir do arquivo que acompanha o projeto.
 *
 * Nao ha aqui o par "atualiza pela origem" que as cidades e as classificacoes
 * tem, e a razao e a forma da fonte: o Anexo VII e publicado em `.xlsx`, e nao
 * como pagina ou JSON. Ler planilha exigiria abrir o ZIP e interpretar o XML do
 * OOXML dentro do projeto, para uma tabela de 36 linhas que mudou uma vez desde
 * que existe. O arquivo local e gerado do anexo oficial:
 *
 *   https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/
 *     anexovii-indop_ibscbs_v1-01-00.xlsx/@@download/file
 *
 * O sufixo `/@@download/file` importa: sem ele o portal responde 200 com um
 * corpo JSON de erro em vez da planilha.
 */
final readonly class ImportarIndicadoresDeOperacao
{
    public function __construct(private FonteDeIndicadores $fonte) {}

    public function executar(): int
    {
        $indicadores = $this->fonte->indicadores();

        if ($indicadores === []) {
            throw new RuntimeException(__('A fonte não devolveu nenhum indicador de operação.'));
        }

        DB::transaction(function () use ($indicadores): void {
            $agora = now()->toDateTimeString();

            $lote = array_map(
                static fn (array $indicador): array => [...$indicador, 'created_at' => $agora, 'updated_at' => $agora],
                $indicadores,
            );

            IndicadorDeOperacao::query()->upsert($lote, ['codigo'], [
                'tipo_operacao', 'caracteristica', 'local_do_fornecimento', 'dispositivo_legal', 'updated_at',
            ]);
        });

        return count($indicadores);
    }
}

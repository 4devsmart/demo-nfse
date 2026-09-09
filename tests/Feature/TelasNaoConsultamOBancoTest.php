<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A tela chama uma Acao para escrever e uma Consulta para ler. O `deptrac.php`
 * cobra a metade disso que da para ver pelo `use`: `Filament/` nao alcanca
 * `Fiscal/Contracts` nem a fachada `DB`.
 *
 * A outra metade nao aparece num `use`. `Nota::query()` e `fn (Nota $nota)`
 * importam a mesma classe, e para o Deptrac sao a mesma dependencia; so lendo o
 * codigo da para separar uma da outra. E este teste.
 *
 * O defeito que ele pega e silencioso: consulta escrita dentro de um widget
 * funciona, e o preco aparece depois, quando a mesma pergunta precisa ser feita
 * de um comando, de um job ou de um teste, e nao ha de onde chama-la.
 */
class TelasNaoConsultamOBancoTest extends TestCase
{
    /**
     * `Model::class` e a unica forma estatica que passa: e o Filament pedindo
     * qual e o model do recurso, nao consulta nenhuma.
     */
    private const ESTATICA_PERMITIDA = 'class';

    /**
     * SQL escrito a mao. A fachada `DB` o Deptrac ja recusa pelo `use`; os
     * `*Raw` nao aparecem em import nenhum, sao metodo sobre um builder que o
     * proprio Filament entrega em `modifyQueryUsing`.
     */
    private const SQL_CRU = [
        'DB::',
        '->whereRaw(',
        '->selectRaw(',
        '->orderByRaw(',
        '->havingRaw(',
        '->groupByRaw(',
        '->fromRaw(',
    ];

    public function test_nenhuma_tela_chama_model_direto(): void
    {
        $problemas = [];

        foreach (File::allFiles(app_path('Filament')) as $arquivo) {
            foreach (self::chamadasDeModel($arquivo->getContents()) as $chamada) {
                $problemas[] = "{$arquivo->getRelativePathname()} chama {$chamada}";
            }
        }

        $this->assertSame(
            [],
            $problemas,
            "Consulta de tela vira Consulta em `app/Consultas`, e escrita vira Acao em `app/Actions`:\n  "
                .implode("\n  ", $problemas),
        );
    }

    public function test_nenhuma_tela_escreve_sql(): void
    {
        $problemas = [];

        foreach (File::allFiles(app_path('Filament')) as $arquivo) {
            $conteudo = $arquivo->getContents();

            foreach (self::SQL_CRU as $trecho) {
                if (str_contains($conteudo, $trecho)) {
                    $problemas[] = "{$arquivo->getRelativePathname()} escreve \"{$trecho}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $problemas,
            "SQL na tela e consulta que ninguem mais reaproveita:\n  ".implode("\n  ", $problemas),
        );
    }

    /**
     * Só os models que o arquivo importa entram na conta. `StatusNota::Rascunho`
     * e `NotaResource::getUrl()` são estáticas legítimas de outra coisa, e
     * varrer por qualquer `::` acusaria as duas.
     *
     * @return list<string>
     */
    private static function chamadasDeModel(string $conteudo): array
    {
        preg_match_all('/^use App\\\\Models\\\\(\w+);/m', $conteudo, $importados);

        if ($importados[1] === []) {
            return [];
        }

        $alternativa = implode('|', array_map(
            static fn (string $model): string => preg_quote($model, '/'),
            $importados[1],
        ));

        preg_match_all("/\b({$alternativa})::(\w+)/", $conteudo, $encontradas, PREG_SET_ORDER);

        $problemas = [];

        foreach ($encontradas as $encontrada) {
            if ($encontrada[2] !== self::ESTATICA_PERMITIDA) {
                $problemas[] = $encontrada[0];
            }
        }

        return $problemas;
    }
}

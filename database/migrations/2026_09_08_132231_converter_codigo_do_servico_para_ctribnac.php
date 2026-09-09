<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Antes de a tabela `cTribNac` existir, o codigo do servico era texto livre e o
 * que se escrevia nele era o subitem da LC 116 ("01.07"). Agora o campo e o
 * codigo de tributacao nacional, de seis digitos, e o valor antigo nao existe
 * na tabela: o registro que o guarda nao volta a gravar nem nos campos que
 * ninguem tocou.
 *
 * A conversao so acontece onde ela nao e palpite. Dos 200 subitens da lista,
 * 138 tem um unico desdobramento, e para esses "01.07" so pode ser "010701".
 * Os outros 62 tem de dois a cinco, e escolher por conta propria seria decidir
 * conteudo fiscal no lugar de quem emite: esses ficam como estao, e a tela os
 * mostra pedindo que sejam reescolhidos.
 *
 * O mapa sai do arquivo que acompanha o projeto, e nao dos models nem da tabela
 * ja carregada. Da tabela porque na atualizacao ela ainda esta vazia quando as
 * migrations rodam, antes do seeder; dos models porque migration que depende de
 * classe da aplicacao quebra no dia em que a classe muda.
 */
return new class extends Migration
{
    private const ARQUIVO = 'data/codigos-de-tributacao-nacional.json';

    /**
     * O que ja saiu deste sistema nao se reescreve. Nota autorizada tem XML
     * assinado na mao da prefeitura dizendo "01.07"; trocar a coluna por
     * "010701" poria o registro em desacordo com o documento que existe la
     * fora, e a substituta sairia copiando o codigo trocado. So rascunho e DPS
     * gerada, que ainda nao foram transmitidos, podem ser convertidos.
     *
     * @var list<string>
     */
    private const STATUS_CONVERSIVEIS = ['rascunho', 'dps_gerada'];

    /**
     * @var array<string, array{codigo: string, coluna_do_item: string}>
     */
    private const COLUNAS = [
        'notas' => ['codigo' => 'codigo_servico', 'coluna_do_item' => 'item_lista_servico'],
        'empresas' => ['codigo' => 'codigo_servico_padrao', 'coluna_do_item' => 'item_lista_servico_padrao'],
    ];

    public function up(): void
    {
        $desdobramentoUnico = $this->desdobramentoUnicoPorSubitem();

        foreach (self::COLUNAS as $tabela => $colunas) {
            $this->converter($tabela, $colunas['codigo'], $colunas['coluna_do_item'], $desdobramentoUnico);
        }
    }

    /**
     * Sem volta, de proposito. Desfazer seria reescrever o cTribNac como
     * subitem, e a essa altura nao ha como separar o que esta migration
     * converteu do que foi escolhido na tela depois dela.
     */
    public function down(): void
    {
        //
    }

    /**
     * @param  array<string, string>  $desdobramentoUnico
     */
    private function converter(string $tabela, string $coluna, string $colunaDoItem, array $desdobramentoUnico): void
    {
        DB::table($tabela)
            ->select(['id', $coluna, $colunaDoItem])
            ->whereNotNull($coluna)
            ->when($tabela === 'notas', fn ($consulta) => $consulta->whereIn('status', self::STATUS_CONVERSIVEIS))
            ->orderBy('id')
            ->chunkById(500, function ($registros) use ($tabela, $coluna, $colunaDoItem, $desdobramentoUnico): void {
                foreach ($registros as $registro) {
                    $subitem = $this->comoSubitem((string) $registro->{$coluna});
                    $codigo = $desdobramentoUnico[$subitem] ?? null;

                    if ($codigo === null) {
                        continue;
                    }

                    DB::table($tabela)->where('id', $registro->id)->update([
                        $coluna => $codigo,
                        // O valor antigo era o subitem: preencher o campo ABRASF
                        // com ele nao inventa nada, so escreve onde ele cabe.
                        $colunaDoItem => $registro->{$colunaDoItem} ?? substr($subitem, 0, 2).'.'.substr($subitem, 2),
                    ]);
                }
            });
    }

    /**
     * O subitem de quatro digitos por tras do valor antigo, ou vazio quando nao
     * da para afirmar qual e.
     *
     * As duas metades sao contadas separadamente, e nao os digitos em fila. Sem
     * isso "10.7" virava "0107", que e o suporte em informatica, quando quem
     * escreveu queria o item 10, corretagem de seguros: a migration trocaria o
     * servico da nota por outro, calada e sem volta.
     *
     * Fica de fora tudo que nao fecha: "10.7" tem subitem de um digito so, "107"
     * sem ponto pode ser 01.07 ou 10.70, e seis digitos ja sao cTribNac. Nesses
     * o registro sai intacto e a tela o mostra como codigo antigo, que e sempre
     * melhor que um palpite gravado.
     */
    private function comoSubitem(string $valor): string
    {
        $limpo = trim($valor);

        if (! str_contains($limpo, '.')) {
            return preg_match('/^\d{4}$/', $limpo) === 1 ? $limpo : '';
        }

        return preg_match('/^(\d{1,2})\.(\d{2})$/', $limpo, $partes) === 1
            ? str_pad($partes[1], 2, '0', STR_PAD_LEFT).$partes[2]
            : '';
    }

    /**
     * @return array<string, string>
     */
    private function desdobramentoUnicoPorSubitem(): array
    {
        $caminho = database_path(self::ARQUIVO);
        $tabela = is_readable($caminho)
            ? json_decode((string) file_get_contents($caminho), true)
            : null;

        // Sem o mapa nao ha conversao, e sair em silencio seria pior que falhar:
        // a atualizacao passaria por concluida deixando para tras registros que
        // nao voltam a gravar, sem nada dizendo por que. A conferencia e no fim,
        // sobre o mapa montado, e nao aqui sobre o JSON: arquivo com a chave
        // renomeada decodifica inteiro e produz mapa vazio do mesmo jeito.
        if (! is_array($tabela)) {
            throw new RuntimeException("Arquivo de códigos de tributação ilegível em {$caminho}.");
        }

        $porSubitem = [];

        foreach ($tabela as $linha) {
            if (! is_array($linha) || ! isset($linha['codigo'], $linha['item_lista_servico'])) {
                continue;
            }

            $porSubitem[(string) $linha['item_lista_servico']][] = (string) $linha['codigo'];
        }

        $mapa = array_map(
            static fn (array $codigos): string => $codigos[0],
            array_filter($porSubitem, static fn (array $codigos): bool => count($codigos) === 1),
        );

        if ($mapa === []) {
            throw new RuntimeException(
                "Arquivo de códigos de tributação sem nenhum subitem utilizável em {$caminho}: "
                .'as chaves `codigo` e `item_lista_servico` mudaram? Nada foi convertido.'
            );
        }

        return $mapa;
    }
};

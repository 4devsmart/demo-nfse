<?php

declare(strict_types=1);

namespace App\Actions\Cargas;

use RuntimeException;
use ZipArchive;

/**
 * Le a TabelaIBPTax no formato em que o IBPT a distribui, sem conversao nenhuma
 * pelo caminho: o arquivo que se baixa de <https://deolhonoimposto.ibpt.org.br>
 * com o CNPJ da empresa entra aqui como veio.
 *
 * A distribuicao e um ZIP com um CSV por UF, `TabelaIBPTax{UF}{versao}.csv`, e
 * a UF esta no nome do arquivo, nao dentro dele. Baixar so o proprio estado
 * tambem funciona: um CSV solto e aceito do mesmo jeito.
 *
 * O CSV e `;` como separador, latin-1, com estas colunas:
 *
 *   codigo;ex;tipo;descricao;nacionalfederal;importadosfederal;estadual;
 *   municipal;vigenciainicio;vigenciafim;chave;versao;fonte
 *
 * A coluna `tipo` separa os tres cadastros que convivem no mesmo arquivo:
 * `0` e NCM, `1` e NBS e `2` sao os itens da LC 116. So os de tipo 2 interessam
 * a uma NFS-e, e sao 198 dos mais de doze mil registros.
 */
final readonly class TabelaIbptOficial implements FonteDeCargas
{
    private const ITEM_DA_LC116 = '2';

    private const COLUNAS = 13;

    public function __construct(private string $caminho) {}

    public function cargas(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo do IBPT não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $linhas = str_ends_with(mb_strtolower($this->caminho), '.zip')
            ? $this->doPacote()
            : $this->doArquivo(basename($this->caminho), (string) file_get_contents($this->caminho));

        if ($linhas === []) {
            throw new RuntimeException(__('Nenhum item da LC 116 no arquivo. Confira se é a TabelaIBPTax e não outra planilha.'));
        }

        usort($linhas, static fn (array $uma, array $outra): int => [$uma['codigo'], $uma['uf']] <=> [$outra['codigo'], $outra['uf']]);

        return $linhas;
    }

    /**
     * @return list<array{codigo: string, uf: string, descricao: string, percentual_federal: float, percentual_federal_importado: float, percentual_estadual: float, percentual_municipal: float, vigencia_inicio: string|null, vigencia_fim: string|null, versao: string}>
     */
    private function doPacote(): array
    {
        $pacote = new ZipArchive;

        if ($pacote->open($this->caminho) !== true) {
            throw new RuntimeException(__('Não deu para abrir o ZIP do IBPT.'));
        }

        $linhas = [];

        for ($indice = 0; $indice < $pacote->numFiles; $indice++) {
            $nome = (string) $pacote->getNameIndex($indice);

            if (! str_ends_with(mb_strtolower($nome), '.csv')) {
                continue;
            }

            $linhas = [...$linhas, ...$this->doArquivo(basename($nome), (string) $pacote->getFromIndex($indice))];
        }

        $pacote->close();

        return $linhas;
    }

    /**
     * @return list<array{codigo: string, uf: string, descricao: string, percentual_federal: float, percentual_federal_importado: float, percentual_estadual: float, percentual_municipal: float, vigencia_inicio: string|null, vigencia_fim: string|null, versao: string}>
     */
    private function doArquivo(string $nome, string $conteudo): array
    {
        $uf = $this->ufDoNome($nome);
        $linhas = [];

        // O arquivo vem em latin-1: sem a conversao, "Serviços" chega quebrado
        // ao banco e nunca mais se conserta.
        foreach (preg_split('/\R/', mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1')) ?: [] as $numero => $linha) {
            if ($numero === 0 || trim($linha) === '') {
                continue;
            }

            $carga = $this->traduzir(str_getcsv($linha, ';', '"', ''), $uf);

            if ($carga !== null) {
                $linhas[] = $carga;
            }
        }

        return $linhas;
    }

    /**
     * A UF sai do nome do arquivo, `TabelaIBPTaxRJ26.2.A.csv`, porque dentro
     * dele nao ha coluna que a diga: o IBPT publica um arquivo por estado.
     */
    private function ufDoNome(string $nome): string
    {
        if (preg_match('/TabelaIBPTax([A-Z]{2})/i', $nome, $encontrado) !== 1) {
            throw new RuntimeException(__('Não deu para saber a UF pelo nome :nome. O arquivo do IBPT vem como TabelaIBPTaxRJ26.2.A.csv.', ['nome' => $nome]));
        }

        return mb_strtoupper($encontrado[1]);
    }

    /**
     * @param  list<string|null>  $colunas
     * @return array{codigo: string, uf: string, descricao: string, percentual_federal: float, percentual_federal_importado: float, percentual_estadual: float, percentual_municipal: float, vigencia_inicio: string|null, vigencia_fim: string|null, versao: string}|null
     */
    private function traduzir(array $colunas, string $uf): ?array
    {
        if (count($colunas) < self::COLUNAS || ($colunas[2] ?? '') !== self::ITEM_DA_LC116) {
            return null;
        }

        return [
            'codigo' => trim((string) $colunas[0]),
            'uf' => $uf,
            'descricao' => trim((string) $colunas[3]),
            'percentual_federal' => (float) $colunas[4],
            'percentual_federal_importado' => (float) $colunas[5],
            'percentual_estadual' => (float) $colunas[6],
            'percentual_municipal' => (float) $colunas[7],
            'vigencia_inicio' => $this->data((string) $colunas[8]),
            'vigencia_fim' => $this->data((string) $colunas[9]),
            'versao' => trim((string) $colunas[11]),
        ];
    }

    /**
     * A data vem como `20/08/2026`; a coluna guarda ISO.
     */
    private function data(string $valor): ?string
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($valor), $partes) !== 1) {
            return null;
        }

        return "{$partes[3]}-{$partes[2]}-{$partes[1]}";
    }
}

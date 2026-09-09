<?php

declare(strict_types=1);

namespace App\Actions\Classificacoes;

use Illuminate\Http\Client\Factory as ClienteHttp;
use RuntimeException;

/**
 * A tabela `cClassTrib` na origem: o portal da SVRS, que e para onde o Portal
 * Nacional da NF-e aponta. So e usado quando alguem pede a atualizacao; a carga
 * do dia a dia sai do arquivo local.
 *
 * A pagina oferece botoes de CSV, Excel e JSON, mas nenhum deles e um endereco:
 * a exportacao acontece no navegador, a partir de um vetor que a propria pagina
 * carrega. Nao ha, portanto, CSV oficial para apontar. O que existe, e e
 * estavel, e esse vetor: `var dadosOriginais`, com a tabela inteira, agrupada
 * por CST. E dele que os tres botoes tiram o que exportam, entao ler dali e ler
 * a mesma coisa que o portal entrega a quem clica.
 *
 * Da tabela inteira ficam os codigos marcados `IndNfse`: os demais valem para
 * NF-e, CT-e e os outros documentos, e nao para NFS-e.
 */
final readonly class PortalDaSvrs implements FonteDeClassificacoes
{
    /** Ver `WrapperFiscal`: o aperto de mao nao usa o timeout de leitura. */
    private const SEGUNDOS_PARA_CONECTAR = 3;

    private const VETOR = 'var dadosOriginais';

    public function __construct(
        private ClienteHttp $http,
        private string $url,
        private int $segundosDeTimeout = 120,
    ) {}

    public function classificacoes(): array
    {
        $resposta = $this->http
            ->connectTimeout(self::SEGUNDOS_PARA_CONECTAR)
            ->timeout($this->segundosDeTimeout)
            ->get($this->url);

        if ($resposta->failed()) {
            throw new RuntimeException(__('O portal da SVRS respondeu :status.', ['status' => $resposta->status()]));
        }

        $tabela = json_decode($this->vetorDaPagina($resposta->body()), true);

        if (! is_array($tabela) || $tabela === []) {
            throw new RuntimeException(__('A tabela de classificações veio vazia ou ilegível.'));
        }

        return $this->achatar($tabela);
    }

    /**
     * O JSON e recortado contando colchetes, e nao por expressao regular. Um
     * `/\[.*\];/s` pararia no primeiro `];` que aparecesse dentro de uma
     * descricao, e a pagina tem 3,8 MB de texto livre onde isso e questao de
     * tempo. Contar respeita aspas e escape, entao acha o fim de verdade.
     */
    private function vetorDaPagina(string $html): string
    {
        $declaracao = strpos($html, self::VETOR);

        if ($declaracao === false) {
            throw new RuntimeException(__('A página da SVRS mudou: não há mais :vetor.', ['vetor' => self::VETOR]));
        }

        $inicio = strpos($html, '[', $declaracao);

        if ($inicio === false) {
            throw new RuntimeException(__('A página da SVRS mudou: :vetor não abre um vetor.', ['vetor' => self::VETOR]));
        }

        return substr($html, $inicio, $this->comprimentoDoVetor($html, $inicio));
    }

    /**
     * A varredura e por byte, e nao por caractere: sao quase quatro milhoes
     * deles, e `mb_substr` a cada passo percorreria a string inteira de novo em
     * cada um. Ler byte e seguro aqui porque os quatro caracteres que importam,
     * `[`, `]`, `"` e a barra invertida, sao ASCII, e em UTF-8 nenhum byte de
     * continuacao de caractere acentuado colide com ASCII.
     */
    private function comprimentoDoVetor(string $html, int $inicio): int
    {
        $profundidade = 0;
        $dentroDeTexto = false;
        $escapado = false;
        $fim = strlen($html);

        for ($posicao = $inicio; $posicao < $fim; $posicao++) {
            $caractere = $html[$posicao];

            if ($escapado) {
                $escapado = false;

                continue;
            }

            if ($dentroDeTexto) {
                $escapado = $caractere === '\\';
                $dentroDeTexto = $caractere !== '"';

                continue;
            }

            if ($caractere === '"') {
                $dentroDeTexto = true;

                continue;
            }

            if ($caractere === '[') {
                $profundidade++;
            }

            if ($caractere === ']' && --$profundidade === 0) {
                return $posicao - $inicio + 1;
            }
        }

        throw new RuntimeException(__('A página da SVRS veio truncada: o vetor não fecha.'));
    }

    /**
     * A origem agrupa as classificacoes dentro do CST. Aqui elas viram linha,
     * cada uma repetindo o CST a que pertence, que e a forma como a DPS as usa.
     *
     * @param  array<int, mixed>  $tabela
     * @return list<array{codigo: string, cst: string, nome_cst: string, descricao: string, percentual_reducao_ibs: float, percentual_reducao_cbs: float, exige_tributo: bool, permite_credito_presumido: bool, tributacao_regular: bool, vigencia_inicio: string|null, vigencia_fim: string|null, url_legislacao: string|null}>
     */
    private function achatar(array $tabela): array
    {
        $linhas = [];

        foreach ($tabela as $cst) {
            if (! is_array($cst)) {
                continue;
            }

            foreach ((array) ($cst['ClassificacoesTributarias'] ?? []) as $classificacao) {
                if (! is_array($classificacao) || ($classificacao['IndNfse'] ?? false) !== true) {
                    continue;
                }

                $linhas[] = $this->traduzir($cst, $classificacao);
            }
        }

        usort($linhas, static fn (array $uma, array $outra): int => $uma['codigo'] <=> $outra['codigo']);

        return $linhas;
    }

    /**
     * @param  array<string, mixed>  $cst
     * @param  array<string, mixed>  $classificacao
     * @return array{codigo: string, cst: string, nome_cst: string, descricao: string, percentual_reducao_ibs: float, percentual_reducao_cbs: float, exige_tributo: bool, permite_credito_presumido: bool, tributacao_regular: bool, vigencia_inicio: string|null, vigencia_fim: string|null, url_legislacao: string|null}
     */
    private function traduzir(array $cst, array $classificacao): array
    {
        $descricao = (string) ($classificacao['NomeReduzido'] ?? '');

        return [
            'codigo' => (string) ($classificacao['CodClassTrib'] ?? ''),
            'cst' => (string) ($cst['Cst'] ?? ''),
            'nome_cst' => (string) ($cst['NomeCst'] ?? ''),
            'descricao' => $descricao !== '' ? $descricao : (string) ($classificacao['NomeClassTrib'] ?? ''),
            'percentual_reducao_ibs' => (float) ($classificacao['PercRedIbs'] ?? 0),
            'percentual_reducao_cbs' => (float) ($classificacao['PercRedCbs'] ?? 0),
            'exige_tributo' => (bool) ($cst['IndExigeTrib'] ?? false),
            'permite_credito_presumido' => (bool) ($classificacao['IndPermiteCredPres'] ?? false),
            'tributacao_regular' => (bool) ($classificacao['IndTribRegular'] ?? false),
            'vigencia_inicio' => $this->data($classificacao['DthIniVig'] ?? null),
            'vigencia_fim' => $this->data($classificacao['DthFimVig'] ?? null),
            'url_legislacao' => blank($classificacao['TexUrlLegislacao'] ?? null)
                ? null
                : (string) $classificacao['TexUrlLegislacao'],
        ];
    }

    /**
     * A origem manda data e hora ("2025-05-05T00:00:00"); a coluna guarda data.
     */
    private function data(mixed $valor): ?string
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        return explode('T', $valor)[0];
    }
}

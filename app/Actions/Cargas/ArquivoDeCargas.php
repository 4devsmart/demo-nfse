<?php

declare(strict_types=1);

namespace App\Actions\Cargas;

use RuntimeException;

/**
 * A TabelaIBPTax que acompanha o projeto, ja reduzida aos itens da LC 116.
 *
 * O arquivo e gravado numa forma compacta, e nao como as 5.346 linhas que vao
 * ao banco: dos cinco campos, so o percentual municipal muda de UF para UF, e
 * repetir descricao e percentual federal vinte e sete vezes levava o arquivo de
 * 129 KB para 2 MB. Quem expande e este leitor.
 */
final readonly class ArquivoDeCargas implements FonteDeCargas
{
    public function __construct(private string $caminho) {}

    public function cargas(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo de cargas tributárias não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $conteudo = json_decode((string) file_get_contents($this->caminho), true);

        if (! is_array($conteudo) || ! is_array($conteudo['servicos'] ?? null)) {
            throw new RuntimeException(__('Arquivo de cargas tributárias inválido.'));
        }

        return $this->expandir($conteudo);
    }

    /**
     * @param  array<string, mixed>  $conteudo
     * @return list<array{codigo: string, uf: string, descricao: string, percentual_federal: float, percentual_federal_importado: float, percentual_estadual: float, percentual_municipal: float, vigencia_inicio: string|null, vigencia_fim: string|null, versao: string}>
     */
    private function expandir(array $conteudo): array
    {
        $comum = [
            'vigencia_inicio' => $this->texto($conteudo, 'vigencia_inicio'),
            'vigencia_fim' => $this->texto($conteudo, 'vigencia_fim'),
            'versao' => (string) $this->texto($conteudo, 'versao'),
        ];

        $linhas = [];

        foreach ((array) $conteudo['servicos'] as $servico) {
            if (! is_array($servico)) {
                continue;
            }

            foreach ((array) ($servico['municipal'] ?? []) as $uf => $municipal) {
                $linhas[] = [
                    'codigo' => (string) ($servico['codigo'] ?? ''),
                    'uf' => (string) $uf,
                    'descricao' => (string) ($servico['descricao'] ?? ''),
                    'percentual_federal' => (float) ($servico['federal'] ?? 0),
                    'percentual_federal_importado' => (float) ($servico['federal_importado'] ?? 0),
                    'percentual_estadual' => (float) ($servico['estadual'] ?? 0),
                    'percentual_municipal' => (float) $municipal,
                    ...$comum,
                ];
            }
        }

        return $linhas;
    }

    /**
     * @param  array<string, mixed>  $conteudo
     */
    private function texto(array $conteudo, string $campo): ?string
    {
        $valor = $conteudo[$campo] ?? null;

        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}

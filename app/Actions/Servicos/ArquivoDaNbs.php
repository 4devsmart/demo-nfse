<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

use RuntimeException;

/**
 * A copia da correlacao NBS que vai junto com o projeto, ja convertida de
 * planilha para JSON.
 *
 * Sem par "atualiza pela origem", pela mesma razao do Anexo VII: o anexo oficial
 * e publicado em `.xlsx`, e ler planilha exigiria abrir o ZIP e interpretar o
 * XML do OOXML dentro do projeto. O arquivo local e gerado do Anexo VIII:
 *
 *   https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/
 *     anexoviii-correlacaoitemnbsindopcclasstrib_ibscbs_v1-01-00.xlsx/@@download/file
 *
 * O sufixo `/@@download/file` importa: sem ele o portal responde 200 com um
 * corpo JSON de erro em vez da planilha.
 *
 * Da aba "tabela geral" saem as colunas do subitem da LC 116, do item da NBS e
 * da descricao. As linhas de item `99.02.01`, `99.03.01` e `99.04.01` ficam de
 * fora: nao sao subitens da lista e nao tem par na tabela de codigos de
 * tributacao deste projeto.
 */
final readonly class ArquivoDaNbs implements FonteDaNbs
{
    public function __construct(private string $caminho) {}

    public function correlacoes(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo de correlação da NBS não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $conteudo = json_decode((string) file_get_contents($this->caminho), true);

        if (! is_array($conteudo)) {
            throw new RuntimeException(__('Arquivo de correlação da NBS inválido.'));
        }

        return array_values($conteudo);
    }
}

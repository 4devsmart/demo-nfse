<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

use RuntimeException;

/**
 * A copia da lista anexa a LC 116/2003 que vai junto com o projeto.
 *
 * Nao ha par "atualiza pela origem" como o dos codigos de tributacao, e a razao
 * e a forma da fonte: o texto do Planalto e a lei consolidada, com a redacao
 * original e a alterada uma ao lado da outra, notas de "Redacao dada por" no
 * meio das descricoes e os subitens vetados ainda listados. Ler isso e escolher
 * qual redacao vale, e nao raspar uma tabela. A lista mudou tres vezes em vinte
 * e dois anos, entao a escolha fica registrada aqui, no arquivo.
 *
 * O arquivo e gerado de:
 *
 *   https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm
 *
 * O `User-Agent` importa: sem um de navegador, o servidor do Planalto deixa a
 * conexao pendurada ate o timeout em vez de responder.
 */
final readonly class ArquivoDaListaDeServicos implements FonteDaListaDeServicos
{
    public function __construct(private string $caminho) {}

    public function itens(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException(__('Arquivo da lista de serviços não encontrado em :caminho.', ['caminho' => $this->caminho]));
        }

        $conteudo = json_decode((string) file_get_contents($this->caminho), true);

        if (! is_array($conteudo)) {
            throw new RuntimeException(__('Arquivo da lista de serviços inválido.'));
        }

        return array_values($conteudo);
    }
}

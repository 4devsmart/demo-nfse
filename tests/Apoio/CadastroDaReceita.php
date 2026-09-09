<?php

declare(strict_types=1);

namespace Tests\Apoio;

/**
 * Uma resposta real da consulta de CNPJ, reduzida aos campos que o cadastro le.
 * Mora aqui porque a mesma resposta serve ao teste da acao e ao teste da tela,
 * e um corpo copiado em dois lugares envelhece so de um lado.
 */
final class CadastroDaReceita
{
    /**
     * @param  array<string, mixed>  $trocas
     * @return array<string, mixed>
     */
    public static function corpo(array $trocas = []): array
    {
        return [
            ...[
                'razao_social' => 'CARREFOUR COMERCIO E INDUSTRIA LTDA',
                'nome_fantasia' => 'CARREFOUR',
                'cnae_fiscal' => 4711301,
                'descricao_situacao_cadastral' => 'ATIVA',
                'descricao_tipo_de_logradouro' => 'AVENIDA',
                'logradouro' => 'TUCUNARE',
                'numero' => '125',
                'complemento' => 'BLOCO C SALA 1 C101',
                'bairro' => 'TAMBORE',
                'cep' => '06460020',
                'municipio' => 'BARUERI',
                'uf' => 'SP',
                // Os dois codigos de municipio da resposta: o da Receita e o do
                // IBGE, que e o que liga o cadastro a cidade daqui.
                'codigo_municipio' => 6213,
                'codigo_municipio_ibge' => 3505708,
                'ddd_telefone_1' => '1199999999',
                'email' => 'CONTAS@EXEMPLO.TEST',
            ],
            ...$trocas,
        ];
    }
}

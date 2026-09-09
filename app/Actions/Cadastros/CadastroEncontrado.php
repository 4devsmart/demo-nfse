<?php

declare(strict_types=1);

namespace App\Actions\Cadastros;

use App\Actions\Enderecos\EnderecoEncontrado;

/**
 * O que a consulta de CNPJ devolve, ja no vocabulario do cadastro. O endereco
 * vem no mesmo tipo da busca de CEP para a tela ter um preenchimento so.
 */
final readonly class CadastroEncontrado
{
    public function __construct(
        public string $razaoSocial,
        public string $nomeFantasia,
        public string $cnaePrincipal,
        public string $telefone,
        public string $email,
        public string $situacao,
        public EnderecoEncontrado $endereco,
    ) {}

    /**
     * Situacao fora de ATIVA nao impede preencher, mas precisa aparecer: baixada,
     * suspensa e inapta descrevem cadastro que a Receita nao considera regular,
     * e a nota sairia em nome dele.
     */
    public function estaAtiva(): bool
    {
        return $this->situacao === 'ATIVA';
    }
}

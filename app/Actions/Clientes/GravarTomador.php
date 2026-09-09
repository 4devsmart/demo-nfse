<?php

declare(strict_types=1);

namespace App\Actions\Clientes;

use App\Models\Cliente;

/**
 * Grava o tomador vindo de um formulario, seja o da tela de clientes ou o do
 * modal que a emissao abre. Existe como Acao porque a tela nao escreve no banco:
 * sem isto, criar tomador de dentro da nota seria uma segunda gravacao, escrita
 * em outro lugar e sem como ser chamada de um comando ou de um teste.
 */
final readonly class GravarTomador
{
    /**
     * Com `$tomador`, atualiza; sem ele, cria. Os dois caminhos existem porque
     * o seletor da nota abre os dois modais, e o que muda entre eles e so isso.
     *
     * @param  array<string, mixed>  $dados
     */
    public function executar(array $dados, ?Cliente $tomador = null): Cliente
    {
        if ($tomador === null) {
            return Cliente::create($dados);
        }

        $tomador->update($dados);

        return $tomador;
    }
}

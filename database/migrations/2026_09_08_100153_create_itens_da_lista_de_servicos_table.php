<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lista de servicos anexa a LC 116/2003: o `ItemListaServico` que os
 * provedores ABRASF pedem. Sao os 200 subitens em vigor, ja com as redacoes da
 * LC 157/2016 e da LC 183/2021, e sem os que foram vetados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_da_lista_de_servicos', function (Blueprint $tabela): void {
            $tabela->id();

            // Quatro digitos sem o ponto ("0107"): e a forma que casa com o
            // prefixo do cTribNac. O ponto e coisa de tela, e o model o poe.
            $tabela->string('codigo', 4)->unique();
            $tabela->text('descricao');

            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_da_lista_de_servicos');
    }
};

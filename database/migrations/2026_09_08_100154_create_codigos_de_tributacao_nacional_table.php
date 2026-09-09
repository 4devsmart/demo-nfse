<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O `cTribNac` da DPS: seis digitos que sao o subitem da LC 116 mais o
 * desdobramento que o Padrao Nacional deu a ele. Um subitem da lista vira uma
 * ou varias linhas aqui, e e esta a tabela que a nota declara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigos_de_tributacao_nacional', function (Blueprint $tabela): void {
            $tabela->id();

            // Seis digitos com zero a esquerda ("010701"): texto, nunca inteiro.
            $tabela->string('codigo', 6)->unique();

            // O subitem da LC 116 a que o codigo pertence, denormalizado como o
            // `cst` das classificacoes: e por ele que a tela liga um ao outro
            // sem uma segunda consulta.
            $tabela->string('item_lista_servico', 4)->index();
            $tabela->text('descricao');

            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos_de_tributacao_nacional');
    }
};

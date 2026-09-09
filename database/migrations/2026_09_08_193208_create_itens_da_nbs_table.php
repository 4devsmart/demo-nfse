<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A correlacao entre o subitem da LC 116 e os itens da NBS que o descrevem.
 *
 * Existe porque a rejeicao E0322 do Padrao Nacional exige o `cNBS` sempre que a
 * DPS declara qualquer informacao de IBS/CBS. Sao 903 correlacoes sobre os 200
 * subitens, com 676 itens de NBS distintos.
 *
 * A relacao e de um para muitos, e e isso que impede derivar o campo: o subitem
 * 01.01 tem cinco itens de NBS, e um deles chega a setenta e cinco. Quem escolhe
 * qual descreve o servico prestado e quem emite; o que a tabela faz e reduzir a
 * escolha de 676 para as poucas que cabem no servico da nota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_da_nbs', function (Blueprint $tabela): void {
            $tabela->id();

            // O subitem da LC 116 em quatro digitos sem ponto, como nas outras
            // tabelas oficiais: e por ele que a tela filtra.
            $tabela->string('item_lista_servico', 4)->index();

            // Doze caracteres, como o anexo publica: "1.1502.10.00". A API tira
            // os pontos ao montar o XML, entao guardar pontuado nao custa e
            // mantem a forma que o operador reconhece.
            $tabela->string('nbs', 12);
            $tabela->text('descricao');

            $tabela->timestamps();
            $tabela->unique(['item_lista_servico', 'nbs']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_da_nbs');
    }
};

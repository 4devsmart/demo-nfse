<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O `cIndOp` da DPS: a tabela de codigos indicadores da operacao, baseada no
 * art. 11 da LC 214/2025. E o Anexo VII da NT 007, que diz onde a operacao se
 * considera ocorrida, e por consequencia a quem cabe o IBS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicadores_de_operacao', function (Blueprint $tabela): void {
            $tabela->id();

            // Seis digitos com zero a esquerda ("010101"): texto, nunca inteiro.
            $tabela->string('codigo', 6)->unique();
            $tabela->string('tipo_operacao');
            $tabela->text('caracteristica');
            $tabela->text('local_do_fornecimento');
            $tabela->string('dispositivo_legal');

            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicadores_de_operacao');
    }
};

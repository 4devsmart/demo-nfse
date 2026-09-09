<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A carga tributaria aproximada por item da LC 116, que e o que a Lei da
 * Transparencia (12.741/2012) manda destacar no documento.
 *
 * A fonte e a TabelaIBPTax, do IBPT. Ela e publicada por UF, e a divisao tem
 * razao de ser: so o percentual municipal muda de estado para estado, o federal
 * e o estadual sao iguais no pais inteiro. Ainda assim a linha guarda a UF, e
 * nao um mapa: a consulta e sempre por par codigo + UF, e desnormalizar aqui
 * troca 5.346 linhas pequenas por um JOIN que ninguem precisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargas_tributarias_aproximadas', function (Blueprint $tabela): void {
            $tabela->id();

            // O codigo vem sem ponto na tabela do IBPT ("0107"), enquanto a
            // nota o escreve como "01.07". Quem normaliza e a consulta.
            $tabela->string('codigo', 6);
            $tabela->string('uf', 2);
            $tabela->text('descricao');

            $tabela->decimal('percentual_federal', 7, 4)->default(0);
            $tabela->decimal('percentual_federal_importado', 7, 4)->default(0);
            $tabela->decimal('percentual_estadual', 7, 4)->default(0);
            $tabela->decimal('percentual_municipal', 7, 4)->default(0);

            // A tabela vence: o IBPT publica uma nova a cada poucos meses, e a
            // vigencia vem escrita nela. Guardar a data e o que permite a tela
            // avisar que a carga em uso esta velha.
            $tabela->date('vigencia_inicio')->nullable();
            $tabela->date('vigencia_fim')->nullable();
            $tabela->string('versao', 20);

            $tabela->timestamps();

            $tabela->unique(['codigo', 'uf']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargas_tributarias_aproximadas');
    }
};

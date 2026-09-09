<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `valores.totTrib.pTotTribSN`: a aliquota efetiva do Simples Nacional, para a
 * Lei da Transparencia.
 *
 * Empresa do Simples nao paga a carga que a tabela do IBPT estima. O DAS reune
 * IRPJ, CSLL, PIS, COFINS, CPP e o proprio ISS numa guia so, entao nao ha o que
 * separar em federal, estadual e municipal: e um percentual unico, e o leiaute
 * tem campo proprio para ele.
 *
 * A coluna e da nota, e nao do emitente, porque o numero muda de mes para mes:
 * a aliquota efetiva sai da receita bruta dos ultimos doze meses. Guardada no
 * cadastro, ela envelheceria em silencio e sairia errada na competencia
 * seguinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->decimal('percentual_simples_nacional', 7, 4)
                ->nullable()
                ->after('total_tributos_municipais');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->dropColumn('percentual_simples_nacional');
        });
    }
};

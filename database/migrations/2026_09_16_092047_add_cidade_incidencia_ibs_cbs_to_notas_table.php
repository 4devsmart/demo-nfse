<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O `cLocalidadeIncid`: o local da operacao do IBS/CBS, art. 11 da LC 214/2025.
 *
 * Anulavel porque so parte dos provedores ABRASF o pede dentro do RPS. No
 * Padrao Nacional quem o calcula e a Sefin, na autorizacao, e a nota nao tem o
 * que dizer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->foreignId('cidade_incidencia_ibs_cbs_id')->nullable()->after('classificacao_tributaria')
                ->constrained('cidades');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->dropConstrainedForeignId('cidade_incidencia_ibs_cbs_id');
        });
    }
};

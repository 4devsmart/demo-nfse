<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `regTrib.regApTribSN` do prestador: se o ISSQN do optante do Simples e apurado
 * dentro ou fora da guia unica.
 *
 * Anulavel porque so o optante o declara. Sem coluna, a biblioteca fiscal
 * gravava `1` por conta propria em toda nota de ME/EPP, e `1` afirma que o
 * ISSQN vai no Simples.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->unsignedTinyInteger('regime_apuracao_simples')->nullable()->after('regime_simples_nacional');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->dropColumn('regime_apuracao_simples');
        });
    }
};

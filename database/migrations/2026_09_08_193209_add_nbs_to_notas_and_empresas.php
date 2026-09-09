<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O `serv.cNBS` da DPS, e o padrao dele no cadastro do emitente.
 *
 * Anulavel nos dois: a NBS so e exigida quando a nota declara IBS/CBS, que
 * ainda e opcional no Padrao Nacional. Sem essa declaracao o campo nao tem o
 * que dizer, e o `semVazios()` do ConstrutorDps poda o nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->string('nbs', 12)->nullable()->after('item_lista_servico');
        });

        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->string('nbs_padrao', 12)->nullable()->after('item_lista_servico_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->dropColumn('nbs');
        });

        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->dropColumn('nbs_padrao');
        });
    }
};

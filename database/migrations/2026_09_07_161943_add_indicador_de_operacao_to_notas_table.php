<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `infDPS.ibscbs.cIndOp`. Vai junto com o par CST + `cClassTrib`, e nao depois:
 * esta build da wrapper-api escreve `<cIndOp></cIndOp>` vazio no XML quando o
 * grupo `IBSCBS` existe e o campo nao vem, o que e pior do que nao mandar o
 * grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->string('indicador_de_operacao', 6)->nullable()->after('cst_ibs_cbs');
        });

        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->string('indicador_de_operacao_padrao', 6)->nullable()->after('cst_ibs_cbs_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->dropColumn('indicador_de_operacao');
        });

        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->dropColumn('indicador_de_operacao_padrao');
        });
    }
};

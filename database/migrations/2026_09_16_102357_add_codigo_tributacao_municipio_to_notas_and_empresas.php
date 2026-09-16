<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O `serv.cTribMun`, que vira o `CodigoTributacaoMunicipio` do ABRASF, e o
 * padrao dele no cadastro do emitente.
 *
 * Cada municipio tem a propria tabela, e ha municipio no GISS que recusa o lote
 * sem o codigo (E202). Anulavel nos dois: no Padrao Nacional o campo e opcional, e
 * boa parte dos provedores ABRASF nao o pede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->string('codigo_tributacao_municipio', 20)->nullable()->after('item_lista_servico');
        });

        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->string('codigo_tributacao_municipio_padrao', 20)->nullable()->after('item_lista_servico_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->dropColumn('codigo_tributacao_municipio');
        });

        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->dropColumn('codigo_tributacao_municipio_padrao');
        });
    }
};

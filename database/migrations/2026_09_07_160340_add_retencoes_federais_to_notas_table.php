<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `valores.trib.tribFed` e `infDPS.ibscbs` na nota, como a NT 007 (7/2/2026)
 * os deixou.
 *
 * As aliquotas sao copiadas do emitente na criacao, e nao lidas dele na hora de
 * montar a DPS: nota emitida guarda o percentual que valia naquele dia, senao
 * mudar a aliquota no cadastro reescreveria o passado.
 *
 * As tres colunas `retem_*` existem porque `vPis` e `vCofins` sao valores
 * DEVIDOS, e o que o tomador RETEM vai somado em `vRetCSLL`. Sem saber quais
 * das tres contribuicoes foram retidas nao ha como escolher o `tpRetPisCofins`
 * nem como compor essa soma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->string('cst_pis_cofins', 2)->nullable()->after('retencao_issqn');
            $tabela->decimal('aliquota_pis', 7, 4)->default(0)->after('cst_pis_cofins');
            $tabela->decimal('aliquota_cofins', 7, 4)->default(0)->after('aliquota_pis');
            $tabela->decimal('aliquota_csll', 7, 4)->default(0)->after('aliquota_cofins');
            $tabela->decimal('aliquota_irrf', 7, 4)->default(0)->after('aliquota_csll');
            $tabela->decimal('aliquota_previdenciaria', 7, 4)->default(0)->after('aliquota_irrf');

            $tabela->boolean('retem_pis')->default(false)->after('aliquota_previdenciaria');
            $tabela->boolean('retem_cofins')->default(false)->after('retem_pis');
            $tabela->boolean('retem_csll')->default(false)->after('retem_cofins');

            // Reforma Tributaria. Sem valor nenhum: a DPS so classifica, e quem
            // calcula IBS e CBS e a Sefin Nacional, na autorizacao.
            $tabela->string('cst_ibs_cbs', 3)->nullable()->after('retem_csll');
            $tabela->string('classificacao_tributaria', 6)->nullable()->after('cst_ibs_cbs');
            $tabela->string('codigo_credito_presumido', 6)->nullable()->after('classificacao_tributaria');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->dropColumn([
                'cst_pis_cofins',
                'aliquota_pis',
                'aliquota_cofins',
                'aliquota_csll',
                'aliquota_irrf',
                'aliquota_previdenciaria',
                'retem_pis',
                'retem_cofins',
                'retem_csll',
                'cst_ibs_cbs',
                'classificacao_tributaria',
                'codigo_credito_presumido',
            ]);
        });
    }
};

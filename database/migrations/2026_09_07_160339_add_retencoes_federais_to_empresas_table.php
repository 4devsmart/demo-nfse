<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As aliquotas de retencao federal e a classificacao de IBS/CBS que a nota
 * herda do emitente, no mesmo lugar onde ja moram serie, ambiente e aliquota do
 * ISS.
 *
 * Ficam no cadastro, e nao na nota, porque nao mudam a cada emissao: sao
 * percentuais de lei (IN RFB 459/2004 para PIS, COFINS e CSLL; art. 714 do
 * RIR/2018 para o IRRF; 11% na cessao de mao de obra para a previdenciaria).
 * Quem muda de nota para nota e SE o tomador retem, e essa resposta e da
 * operacao: fica em `notas`, nao aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->string('cst_pis_cofins_padrao', 2)->nullable()->after('aliquota_iss_padrao');
            $tabela->decimal('aliquota_pis_padrao', 7, 4)->default(0)->after('cst_pis_cofins_padrao');
            $tabela->decimal('aliquota_cofins_padrao', 7, 4)->default(0)->after('aliquota_pis_padrao');
            $tabela->decimal('aliquota_csll_padrao', 7, 4)->default(0)->after('aliquota_cofins_padrao');
            $tabela->decimal('aliquota_irrf_padrao', 7, 4)->default(0)->after('aliquota_csll_padrao');
            $tabela->decimal('aliquota_previdenciaria_padrao', 7, 4)->default(0)->after('aliquota_irrf_padrao');

            // Reforma Tributaria: o par CST + cClassTrib da tabela oficial.
            $tabela->string('cst_ibs_cbs_padrao', 3)->nullable()->after('aliquota_previdenciaria_padrao');
            $tabela->string('classificacao_tributaria_padrao', 6)->nullable()->after('cst_ibs_cbs_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $tabela): void {
            $tabela->dropColumn([
                'cst_pis_cofins_padrao',
                'aliquota_pis_padrao',
                'aliquota_cofins_padrao',
                'aliquota_csll_padrao',
                'aliquota_irrf_padrao',
                'aliquota_previdenciaria_padrao',
                'cst_ibs_cbs_padrao',
                'classificacao_tributaria_padrao',
            ]);
        });
    }
};

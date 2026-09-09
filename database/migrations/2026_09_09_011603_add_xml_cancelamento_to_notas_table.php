<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O XML do evento de cancelamento, em base64.
 *
 * A API devolve o documento do evento em `xml_b64` na resposta do
 * cancelamento, e ele estava sendo descartado: a nota ficava Cancelada sem
 * guardar a prova disso. E o mesmo tratamento que a substituicao ja dava ao
 * XML que ela recebe, e ha um so momento de guarda-lo, porque nao existe
 * segunda via.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->text('xml_cancelamento')->nullable()->after('xml_autorizado');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->dropColumn('xml_cancelamento');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coluna guarda o documento do evento, e cancelamento nao e o unico.
 *
 * A nota substituida tambem tem evento, e ele cai no mesmo lugar: a fila DF-e
 * so distingue NFS-e de EVENTO, e a busca acha o que houver sobre aquela chave.
 * Chamar a coluna de `xml_cancelamento` fazia a tela oferecer "XML do evento de
 * cancelamento" para uma substituicao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->renameColumn('xml_cancelamento', 'xml_evento');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $tabela): void {
            $tabela->renameColumn('xml_evento', 'xml_cancelamento');
        });
    }
};

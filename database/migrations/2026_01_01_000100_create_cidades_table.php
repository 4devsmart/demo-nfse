<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cidades', function (Blueprint $tabela): void {
            $tabela->id();
            $tabela->string('codigo_ibge', 7)->unique();
            $tabela->string('nome');
            $tabela->string('uf', 2);
            $tabela->timestamps();

            $tabela->index(['uf', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cidades');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $tabela): void {
            $tabela->id();
            $tabela->string('tipo_pessoa', 20)->default('juridica');
            $tabela->string('cpf_cnpj', 14)->unique();
            $tabela->string('razao_social');
            $tabela->string('inscricao_municipal')->nullable();

            $tabela->foreignId('cidade_id')->constrained('cidades');
            $tabela->string('cep', 8);
            $tabela->string('logradouro');
            $tabela->string('numero', 20);
            $tabela->string('complemento')->nullable();
            $tabela->string('bairro');
            $tabela->string('telefone', 20)->nullable();
            $tabela->string('email')->nullable();

            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};

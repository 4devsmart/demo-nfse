<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $tabela): void {
            $tabela->id();
            $tabela->string('razao_social');
            $tabela->string('nome_fantasia')->nullable();
            $tabela->string('cnpj', 14)->unique();
            $tabela->string('inscricao_municipal')->nullable();

            $tabela->foreignId('cidade_id')->constrained('cidades');
            $tabela->string('cep', 8);
            $tabela->string('logradouro');
            $tabela->string('numero', 20);
            $tabela->string('complemento')->nullable();
            $tabela->string('bairro');
            $tabela->string('telefone', 20)->nullable();
            $tabela->string('email')->nullable();

            // Regime tributario do prestador: sem ele o Padrao Nacional nao monta o XML.
            $tabela->unsignedTinyInteger('regime_simples_nacional')->default(1);
            $tabela->unsignedTinyInteger('regime_especial')->default(0);

            // Padroes que a nota herda ao ser criada.
            $tabela->string('ambiente', 20)->default('homologacao');
            $tabela->string('serie_dps', 5)->default('1');
            $tabela->unsignedBigInteger('proximo_numero_dps')->default(1);
            $tabela->string('codigo_servico_padrao', 20)->nullable();
            $tabela->string('cnae_padrao', 20)->nullable();
            $tabela->string('item_lista_servico_padrao', 20)->nullable();
            $tabela->decimal('aliquota_iss_padrao', 7, 4)->default(0);

            // O A1 fica cifrado em repouso. A API fiscal nao guarda nada: quem
            // guarda somos nos, e a chave e a APP_KEY.
            $tabela->text('certificado_arquivo')->nullable();
            $tabela->text('certificado_senha')->nullable();
            $tabela->string('certificado_titular')->nullable();
            $tabela->date('certificado_valido_ate')->nullable();

            // Login de webservice de prefeitura, exigido por provedores fora do
            // Padrao Nacional. Como o certificado, a API nao persiste: vai na
            // requisicao e morre com ela.
            $tabela->text('prefeitura_usuario')->nullable();
            $tabela->text('prefeitura_senha')->nullable();
            $tabela->text('prefeitura_token')->nullable();

            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};

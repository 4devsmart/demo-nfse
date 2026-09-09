<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tabela oficial de classificacao tributaria do IBS e da CBS (`cClassTrib`),
 * publicada pela SVRS no portal que o Portal Nacional da NF-e indica. Sao 164
 * codigos no total; aqui entram os validos para NFS-e, que a origem marca com
 * `IndNfse`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classificacoes_tributarias', function (Blueprint $tabela): void {
            $tabela->id();

            // O codigo tem seis digitos com zero a esquerda ("000001"), e o CST
            // tem tres ("000"): os dois sao texto, nunca inteiro.
            $tabela->string('codigo', 6)->unique();
            $tabela->string('cst', 3);
            $tabela->string('nome_cst');
            $tabela->text('descricao');

            // Reducao de aliquota concedida pela classificacao. Nao entra em
            // conta nenhuma deste sistema: quem calcula IBS e CBS e a Sefin
            // Nacional. Fica gravada porque e o que explica, na tela, por que
            // duas classificacoes parecidas nao sao a mesma coisa.
            $tabela->decimal('percentual_reducao_ibs', 7, 4)->default(0);
            $tabela->decimal('percentual_reducao_cbs', 7, 4)->default(0);

            $tabela->boolean('exige_tributo')->default(true);
            $tabela->boolean('permite_credito_presumido')->default(false);
            $tabela->boolean('tributacao_regular')->default(false);

            // Vigencia da propria classificacao. A tabela e versionada na
            // origem: codigo revogado continua existindo para as notas antigas.
            $tabela->date('vigencia_inicio')->nullable();
            $tabela->date('vigencia_fim')->nullable();

            $tabela->string('url_legislacao')->nullable();

            $tabela->timestamps();

            $tabela->index(['cst', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classificacoes_tributarias');
    }
};

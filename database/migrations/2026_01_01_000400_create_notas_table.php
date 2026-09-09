<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas', function (Blueprint $tabela): void {
            $tabela->id();
            $tabela->foreignId('empresa_id')->constrained('empresas');
            $tabela->foreignId('cliente_id')->constrained('clientes');
            $tabela->foreignId('cidade_prestacao_id')->constrained('cidades');

            // A nota substituta aponta para a que ela troca. O caminho inverso
            // se le pela relacao, sem guardar o par duas vezes.
            $tabela->foreignId('substitui_nota_id')->nullable()
                ->constrained('notas')->nullOnDelete();

            $tabela->string('referencia')->unique();
            $tabela->string('serie', 5);
            $tabela->unsignedBigInteger('numero');
            $tabela->date('competencia');
            $tabela->string('ambiente', 20);
            $tabela->string('status', 20);

            $tabela->text('descricao_servico');
            $tabela->string('codigo_servico', 20);
            $tabela->string('cnae', 20)->nullable();
            $tabela->string('item_lista_servico', 20)->nullable();

            $tabela->decimal('valor_servico', 15, 2);
            $tabela->decimal('aliquota_iss', 7, 4)->default(0);
            $tabela->decimal('deducoes', 15, 2)->default(0);
            $tabela->decimal('desconto_incondicionado', 15, 2)->default(0);
            $tabela->decimal('desconto_condicionado', 15, 2)->default(0);
            $tabela->unsignedTinyInteger('tributacao_issqn')->default(1);
            $tabela->unsignedTinyInteger('retencao_issqn')->default(1);

            // Exigibilidade suspensa: a prefeitura precisa saber por que, e o
            // numero do processo e obrigatorio junto.
            $tabela->unsignedTinyInteger('tipo_suspensao')->nullable();
            $tabela->string('numero_processo_suspensao')->nullable();

            // Beneficio municipal: reduz a base de calculo, logo reduz o ISSQN.
            $tabela->string('numero_beneficio_municipal')->nullable();
            $tabela->decimal('percentual_reducao_base', 7, 4)->nullable();

            // Lei da Transparencia (12.741/2012): quanto de tributo ha embutido.
            $tabela->decimal('total_tributos_federais', 15, 2)->nullable();
            $tabela->decimal('total_tributos_estaduais', 15, 2)->nullable();
            $tabela->decimal('total_tributos_municipais', 15, 2)->nullable();

            // A DPS: identificador deterministico + o XML montado, antes de sair.
            $tabela->string('id_dps')->nullable();
            $tabela->text('xml_dps')->nullable();
            $tabela->text('provedor')->nullable();

            // A nota: o que a prefeitura devolveu. Nao ha segunda via do XML.
            $tabela->string('numero_nfse')->nullable();
            $tabela->string('chave', 60)->nullable();
            $tabela->string('codigo_verificacao')->nullable();
            $tabela->string('protocolo')->nullable();
            $tabela->text('xml_autorizado')->nullable();

            $tabela->json('mensagens')->nullable();
            $tabela->timestamp('transmitida_em')->nullable();
            $tabela->timestamp('cancelada_em')->nullable();
            $tabela->text('motivo_cancelamento')->nullable();

            $tabela->timestamps();

            $tabela->unique(['empresa_id', 'serie', 'numero']);
            $tabela->index(['status', 'competencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas');
    }
};

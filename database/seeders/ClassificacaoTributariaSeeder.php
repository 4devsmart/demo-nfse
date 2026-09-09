<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Classificacoes\ImportarClassificacoesTributarias;
use App\Models\ClassificacaoTributaria;
use Illuminate\Database\Seeder;

class ClassificacaoTributariaSeeder extends Seeder
{
    public function run(ImportarClassificacoesTributarias $importar): void
    {
        if (ClassificacaoTributaria::query()->exists()) {
            $this->command?->info('Classificações tributárias já carregadas.');

            return;
        }

        $total = $importar->executar();

        $this->command?->info("{$total} classificações tributárias de IBS/CBS carregadas.");
    }
}

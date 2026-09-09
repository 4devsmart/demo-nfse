<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Servicos\ImportarCodigosDeTributacaoNacional;
use App\Models\CodigoDeTributacaoNacional;
use Illuminate\Database\Seeder;

class CodigoDeTributacaoNacionalSeeder extends Seeder
{
    public function run(ImportarCodigosDeTributacaoNacional $importar): void
    {
        if (CodigoDeTributacaoNacional::query()->exists()) {
            $this->command?->info('Códigos de tributação nacional já carregados.');

            return;
        }

        $total = $importar->executar();

        $this->command?->info("{$total} códigos de tributação nacional (cTribNac) carregados.");
    }
}

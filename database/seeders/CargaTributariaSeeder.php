<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Cargas\ImportarCargasTributarias;
use App\Models\CargaTributariaAproximada;
use Illuminate\Database\Seeder;

class CargaTributariaSeeder extends Seeder
{
    public function run(ImportarCargasTributarias $importar): void
    {
        if (CargaTributariaAproximada::query()->exists()) {
            $this->command?->info('Cargas tributárias já carregadas.');

            return;
        }

        $total = $importar->executar();

        $this->command?->info("{$total} cargas tributárias aproximadas (IBPT) carregadas.");
    }
}

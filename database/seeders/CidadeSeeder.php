<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Cidades\ImportarCidades;
use App\Models\Cidade;
use Illuminate\Database\Seeder;

class CidadeSeeder extends Seeder
{
    public function run(ImportarCidades $importar): void
    {
        if (Cidade::query()->exists()) {
            $this->command?->info('Cidades já carregadas.');

            return;
        }

        $total = $importar->executar();

        $this->command?->info("{$total} municípios do IBGE carregados.");
    }
}

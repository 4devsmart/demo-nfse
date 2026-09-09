<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Indicadores\ImportarIndicadoresDeOperacao;
use App\Models\IndicadorDeOperacao;
use Illuminate\Database\Seeder;

class IndicadorDeOperacaoSeeder extends Seeder
{
    public function run(ImportarIndicadoresDeOperacao $importar): void
    {
        if (IndicadorDeOperacao::query()->exists()) {
            $this->command?->info('Indicadores de operação já carregados.');

            return;
        }

        $total = $importar->executar();

        $this->command?->info("{$total} indicadores de operação (cIndOp) carregados.");
    }
}

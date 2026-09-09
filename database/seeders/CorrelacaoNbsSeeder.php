<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Servicos\ImportarCorrelacaoNbs;
use App\Models\ItemDaNbs;
use Illuminate\Database\Seeder;

class CorrelacaoNbsSeeder extends Seeder
{
    public function run(ImportarCorrelacaoNbs $importar): void
    {
        if (ItemDaNbs::query()->exists()) {
            $this->command?->info('Correlação da NBS já carregada.');

            return;
        }

        $total = $importar->executar();

        $this->command?->info("{$total} correlações entre subitem da LC 116 e itens da NBS carregadas.");
    }
}

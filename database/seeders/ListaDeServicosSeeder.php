<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Servicos\ImportarListaDeServicos;
use App\Models\ItemDaListaDeServicos;
use Illuminate\Database\Seeder;

class ListaDeServicosSeeder extends Seeder
{
    public function run(ImportarListaDeServicos $importar): void
    {
        if (ItemDaListaDeServicos::query()->exists()) {
            $this->command?->info('Lista de serviços já carregada.');

            return;
        }

        $total = $importar->executar();

        $this->command?->info("{$total} subitens da lista anexa à LC 116/2003 carregados.");
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CidadeSeeder::class,
            ClassificacaoTributariaSeeder::class,
            IndicadorDeOperacaoSeeder::class,
            ListaDeServicosSeeder::class,
            CodigoDeTributacaoNacionalSeeder::class,
            CorrelacaoNbsSeeder::class,
            CargaTributariaSeeder::class,
            DemonstracaoSeeder::class,
        ]);
    }
}

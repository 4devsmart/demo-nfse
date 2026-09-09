<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * O outro lado da regra `IconeComoEnumRule`, que roda no PHPStan.
 *
 * Ela cobre o PHP, e o PHPStan não lê Blade: as dez ocorrências que existiam
 * nas views passavam inteiras por baixo dela.
 *
 * O defeito é silencioso, e é o mesmo do `EstiloProprioTest`: um typo em
 * `heroicon-o-clock` vira ícone que não existe, a tela renderiza sem erro
 * nenhum e só falta o desenho. Com `Heroicon::OutlinedClock` o mesmo engano é
 * erro de compilação.
 *
 * Na view, o caminho é `@use('Filament\Support\Icons\Heroicon')` no topo e
 * `:icon="Heroicon::OutlinedClock"` no componente. Quando a escolha depende de
 * estado, ela não é da view: `PassoDaConfiguracao::icone()` é o exemplo.
 */
class IconesDasViewsTest extends TestCase
{
    public function test_nenhuma_view_escreve_icone_como_texto(): void
    {
        $problemas = [];

        foreach (File::allFiles(resource_path('views')) as $arquivo) {
            preg_match_all('/heroicon-[a-z0-9-]+/', $arquivo->getContents(), $icones);

            foreach ($icones[0] as $icone) {
                $problemas[] = "{$arquivo->getRelativePathname()} escreve \"{$icone}\"";
            }
        }

        $this->assertSame(
            [],
            $problemas,
            "Ícone é enum, aqui também: `@use('Filament\\Support\\Icons\\Heroicon')` e `:icon=\"Heroicon::...\"`:\n  "
                .implode("\n  ", $problemas),
        );
    }
}

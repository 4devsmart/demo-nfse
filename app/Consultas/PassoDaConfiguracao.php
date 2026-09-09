<?php

declare(strict_types=1);

namespace App\Consultas;

use Filament\Support\Icons\Heroicon;

final readonly class PassoDaConfiguracao
{
    public function __construct(
        public string $titulo,
        public string $explicacao,
        public bool $concluido,
        public string $rotulo,
        public string $url,
    ) {}

    /**
     * O icone do passo. Fica aqui, e nao na view, porque a escolha depende do
     * estado do passo, e a view nao precisa saber ler esse estado para
     * desenha-lo.
     */
    public function icone(): Heroicon
    {
        return $this->concluido ? Heroicon::CheckCircle : Heroicon::OutlinedClock;
    }
}

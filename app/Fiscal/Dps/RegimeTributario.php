<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\Enums\RegimeDeApuracaoDoSimples;
use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;

/**
 * `prest.regTrib`. Obrigatorio para o prestador no Padrao Nacional: sem ele a
 * biblioteca fiscal falha ao montar o XML.
 */
final readonly class RegimeTributario
{
    public function __construct(
        public RegimeSimplesNacional $simplesNacional,
        public RegimeEspecialTributacao $especial,
        public ?RegimeDeApuracaoDoSimples $apuracaoDoSimples = null,
    ) {}

    /**
     * O regime de apuracao so acompanha quem e optante: para o nao optante o
     * campo nao tem o que dizer, e o `semVazios()` do ConstrutorDps poda o nulo.
     *
     * Declarar importa. Medido contra a API: sem o campo, a biblioteca fiscal
     * grava `1` sozinha na nota de ME/EPP, e `1` afirma que o ISSQN e apurado
     * pelo Simples. Quem apura o ISSQN por fora saia declarando o contrario.
     *
     * @return array<string, int|null>
     */
    public function paraApi(): array
    {
        return [
            'opSimpNac' => $this->simplesNacional->value,
            'regApTribSN' => $this->simplesNacional->ehOptante() ? $this->apuracaoDoSimples?->value : null,
            'regEspTrib' => $this->especial->value,
        ];
    }
}

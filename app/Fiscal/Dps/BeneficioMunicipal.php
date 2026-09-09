<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\ValueObjects\Aliquota;
use InvalidArgumentException;

/**
 * `tribMun.nBM` e `tribMun.pRedBCBM`. O beneficio reduz a base de calculo do
 * ISSQN, entao muda o imposto devido.
 */
final readonly class BeneficioMunicipal
{
    private function __construct(
        public string $numero,
        public Aliquota $percentualDeReducao,
    ) {}

    public static function concedido(string $numero, Aliquota $percentualDeReducao): self
    {
        if (trim($numero) === '') {
            throw new InvalidArgumentException(__('O benefício municipal exige o número que a prefeitura concedeu.'));
        }

        return new self(trim($numero), $percentualDeReducao);
    }

    /**
     * @return array<string, float|string>
     */
    public function paraApi(): array
    {
        return [
            'nBM' => $this->numero,
            'pRedBCBM' => $this->percentualDeReducao->percentual,
        ];
    }
}

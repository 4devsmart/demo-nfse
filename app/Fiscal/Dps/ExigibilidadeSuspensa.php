<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use InvalidArgumentException;

/**
 * `tribMun.tpSusp` e `tribMun.nProcesso`. Suspensao sem numero de processo nao
 * existe: a prefeitura precisa saber qual decisao ampara a operacao.
 */
final readonly class ExigibilidadeSuspensa
{
    private function __construct(
        public TipoSuspensaoDeExigibilidade $tipo,
        public string $numeroDoProcesso,
    ) {}

    public static function por(TipoSuspensaoDeExigibilidade $tipo, string $numeroDoProcesso): self
    {
        if (trim($numeroDoProcesso) === '') {
            throw new InvalidArgumentException(__('A suspensão da exigibilidade exige o número do processo.'));
        }

        return new self($tipo, trim($numeroDoProcesso));
    }

    /**
     * @return array<string, int|string>
     */
    public function paraApi(): array
    {
        return [
            'tpSusp' => $this->tipo->value,
            'nProcesso' => $this->numeroDoProcesso,
        ];
    }
}

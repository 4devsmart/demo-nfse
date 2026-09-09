<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\ValueObjects\CodigoIbge;

/**
 * `infDPS.serv`. `codigoDoServico` vira `cTribNac` no Padrao Nacional; o
 * `itemDaListaDeServico` so e lido por provedores ABRASF.
 *
 * O `cNBS` e exigido pela rejeicao E0322 sempre que a DPS declara qualquer
 * informacao de IBS/CBS. Fora disso ele nao tem o que dizer, e o `semVazios()`
 * do ConstrutorDps poda o nulo.
 */
final readonly class ServicoPrestado
{
    private function __construct(
        public CodigoIbge $municipioDaPrestacao,
        public string $codigoDoServico,
        public string $descricao,
        public string $cnae,
        public string $itemDaListaDeServico,
        public string $nbs = '',
    ) {}

    public static function prestadoEm(CodigoIbge $municipio, string $codigoDoServico, string $descricao): self
    {
        return new self($municipio, $codigoDoServico, $descricao, '', '');
    }

    public function comCnae(string $cnae): self
    {
        return $this->com(cnae: $cnae);
    }

    public function comItemDaListaDeServico(string $item): self
    {
        return $this->com(itemDaListaDeServico: $item);
    }

    public function comNbs(string $nbs): self
    {
        return $this->com(nbs: $nbs);
    }

    private function com(?string $cnae = null, ?string $itemDaListaDeServico = null, ?string $nbs = null): self
    {
        return new self(
            $this->municipioDaPrestacao,
            $this->codigoDoServico,
            $this->descricao,
            $cnae ?? $this->cnae,
            $itemDaListaDeServico ?? $this->itemDaListaDeServico,
            $nbs ?? $this->nbs,
        );
    }

    /**
     * @return array<string, string>
     */
    public function paraApi(): array
    {
        return [
            'cMunPrestacao' => (string) $this->municipioDaPrestacao,
            'cServ' => $this->codigoDoServico,
            'xDescServ' => $this->descricao,
            'codigoCnae' => $this->cnae,
            'itemListaServico' => $this->itemDaListaDeServico,
            'cNBS' => $this->nbs,
        ];
    }
}

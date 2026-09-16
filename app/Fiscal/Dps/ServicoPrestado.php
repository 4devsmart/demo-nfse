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
 *
 * O `cTribMun` e o codigo da tabela do proprio municipio. O ABRASF o le em
 * `CodigoTributacaoMunicipio`, e ha municipio no GISS que recusa o lote sem
 * ele (E202). Vazio, o `semVazios()` o poda.
 *
 * O `municipioIncidencia` vira o `MunicipioIncidencia` do ABRASF. No Padrao
 * Nacional o gravador do ACBr nao o poe na DPS: quem decide ali e a Sefin.
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
        public ?CodigoIbge $municipioDeIncidencia = null,
        public string $codigoDeTributacaoMunicipal = '',
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

    public function comCodigoDeTributacaoMunicipal(string $codigo): self
    {
        return $this->com(codigoDeTributacaoMunicipal: trim($codigo));
    }

    /**
     * Onde o ISSQN e devido, que nem sempre e onde o servico foi prestado. Quem
     * decide e `LocalDeIncidenciaDoIssqn`; nulo quando nao ha imposto devido.
     */
    public function comIssqnDevidoEm(?CodigoIbge $municipio): self
    {
        return $this->com(municipioDeIncidencia: $municipio);
    }

    private function com(
        ?string $cnae = null,
        ?string $itemDaListaDeServico = null,
        ?string $nbs = null,
        ?CodigoIbge $municipioDeIncidencia = null,
        ?string $codigoDeTributacaoMunicipal = null,
    ): self {
        return new self(
            $this->municipioDaPrestacao,
            $this->codigoDoServico,
            $this->descricao,
            $cnae ?? $this->cnae,
            $itemDaListaDeServico ?? $this->itemDaListaDeServico,
            $nbs ?? $this->nbs,
            $municipioDeIncidencia ?? $this->municipioDeIncidencia,
            $codigoDeTributacaoMunicipal ?? $this->codigoDeTributacaoMunicipal,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function paraApi(): array
    {
        return [
            'cMunPrestacao' => (string) $this->municipioDaPrestacao,
            'cServ' => $this->codigoDoServico,
            'cTribMun' => $this->codigoDeTributacaoMunicipal,
            'xDescServ' => $this->descricao,
            'codigoCnae' => $this->cnae,
            'itemListaServico' => $this->itemDaListaDeServico,
            'cNBS' => $this->nbs,
            'municipioIncidencia' => $this->municipioDeIncidencia === null ? null : (string) $this->municipioDeIncidencia,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `valores.tribFed.tpRetPisCofins`. Apesar do nome do campo, ele responde pelas
 * tres contribuicoes sociais: PIS, COFINS e CSLL. Foi a NT 007 (7/2/2026) que
 * ampliou o dominio para dizer, codigo a codigo, quais das tres o tomador reteve.
 *
 * Os codigos 1 e 2 sao os antigos, e so eles eram aceitos no schema ate a NT.
 * Continuam aqui porque documento ja autorizado os carrega, e some do schema
 * quando o grupo `IBSCBS` virar obrigatorio. Nada neste sistema os produz: quem
 * escolhe o codigo e `deCombinacao()`, que so devolve 0 e 3 a 9.
 */
enum RetencaoDeContribuicoesSociais: int implements HasLabel
{
    case NenhumaRetida = 0;
    case PisCofinsRetidosLegado = 1;
    case PisCofinsNaoRetidosLegado = 2;
    case TodasRetidas = 3;
    case PisCofinsRetidos = 4;
    case ApenasPisRetido = 5;
    case ApenasCofinsRetido = 6;
    case CofinsCsllRetidos = 7;
    case ApenasCsllRetida = 8;
    case PisCsllRetidos = 9;

    public function getLabel(): string
    {
        return match ($this) {
            self::NenhumaRetida => __('PIS/COFINS/CSLL não retidos'),
            self::PisCofinsRetidosLegado => __('PIS/COFINS retido (código antigo)'),
            self::PisCofinsNaoRetidosLegado => __('PIS/COFINS não retido (código antigo)'),
            self::TodasRetidas => __('PIS/COFINS/CSLL retidos'),
            self::PisCofinsRetidos => __('PIS/COFINS retidos, CSLL não retido'),
            self::ApenasPisRetido => __('PIS retido, COFINS/CSLL não retido'),
            self::ApenasCofinsRetido => __('COFINS retido, PIS/CSLL não retido'),
            self::CofinsCsllRetidos => __('PIS não retido, COFINS/CSLL retidos'),
            self::ApenasCsllRetida => __('PIS/COFINS não retidos, CSLL retido'),
            self::PisCsllRetidos => __('COFINS não retido, PIS/CSLL retidos'),
        };
    }

    /**
     * O codigo que descreve a combinacao escolhida na tela. As oito combinacoes
     * possiveis de tres booleanos cabem nos codigos 0 e 3 a 9, sem sobra e sem
     * falta, e e por isso que nao ha ramo `default` aqui.
     */
    public static function deCombinacao(bool $pis, bool $cofins, bool $csll): self
    {
        return match (true) {
            ! $pis && ! $cofins && ! $csll => self::NenhumaRetida,
            $pis && $cofins && $csll => self::TodasRetidas,
            $pis && $cofins => self::PisCofinsRetidos,
            $pis && $csll => self::PisCsllRetidos,
            $cofins && $csll => self::CofinsCsllRetidos,
            $pis => self::ApenasPisRetido,
            $cofins => self::ApenasCofinsRetido,
            default => self::ApenasCsllRetida,
        };
    }
}

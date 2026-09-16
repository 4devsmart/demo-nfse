<?php

declare(strict_types=1);

namespace App\Domain\Notas;

use App\Domain\Enums\TributacaoIssqn;
use App\Domain\ValueObjects\CodigoIbge;

/**
 * O municipio onde o ISSQN e devido, pelo art. 3º da LC 116/2003. E o
 * `MunicipioIncidencia` do ABRASF, que o GISS recusa ausente com E310. No
 * Padrao Nacional quem o calcula e a Sefin, e o campo nao entra na DPS.
 *
 * A regra geral e o estabelecimento prestador. Os incisos II a XIX, XXI e XXII
 * levam o imposto para onde o servico acontece (obra, vigilancia, evento,
 * transporte, porto), e o XX para o estabelecimento do tomador da mao de obra.
 *
 * Planos de saude, cartoes e leasing (incisos XXIII a XXV) ficam na regra
 * geral: o STF derrubou a mudanca para o domicilio do tomador nas ADI 5835 e
 * 5862.
 *
 * Fica de fora o que um codigo de municipio sozinho nao expressa: servico
 * importado (inciso I), que este sistema nao emite, e os subitens 3.04 e
 * 22.01, cujo imposto se divide pela extensao da via em cada municipio.
 */
final readonly class LocalDeIncidenciaDoIssqn
{
    /**
     * Subitens cujo imposto e devido no local da prestacao.
     *
     * @var list<string>
     */
    private const SUBITENS_NO_LOCAL_DA_PRESTACAO = [
        '0305', '0702', '0704', '0705', '0709', '0710', '0711', '0712',
        '0716', '0717', '0718', '0719', '1101', '1102', '1104', '1710',
    ];

    /**
     * Itens inteiros no local da prestacao: diversao (12), transporte (16) e
     * servicos portuarios e aeroportuarios (20).
     *
     * @var list<string>
     */
    private const ITENS_NO_LOCAL_DA_PRESTACAO = ['12', '16', '20'];

    /** Producao de eventos e espetaculos: o unico subitem do item 12 que nao segue o item. */
    private const SUBITEM_FORA_DO_ITEM_12 = '1213';

    /** Fornecimento de mao de obra: devido no estabelecimento do tomador. */
    private const SUBITEM_NO_TOMADOR = '1705';

    /**
     * Sem ISSQN devido nao ha municipio de incidencia: o ABRASF so pede o campo
     * quando o imposto e exigivel, ainda que suspenso.
     *
     * @param  string  $codigoDoServico  o `cTribNac`, cujos quatro primeiros digitos sao o subitem da LC 116
     */
    public static function doServico(
        string $codigoDoServico,
        TributacaoIssqn $tributacao,
        CodigoIbge $estabelecimentoPrestador,
        CodigoIbge $localDaPrestacao,
        CodigoIbge $estabelecimentoDoTomador,
    ): ?CodigoIbge {
        if (! $tributacao->temIssqnDevido()) {
            return null;
        }

        $subitem = substr(preg_replace('/\D/', '', $codigoDoServico) ?? '', 0, 4);

        return match (true) {
            $subitem === self::SUBITEM_NO_TOMADOR => $estabelecimentoDoTomador,
            self::ficaNoLocalDaPrestacao($subitem) => $localDaPrestacao,
            default => $estabelecimentoPrestador,
        };
    }

    private static function ficaNoLocalDaPrestacao(string $subitem): bool
    {
        if (in_array($subitem, self::SUBITENS_NO_LOCAL_DA_PRESTACAO, true)) {
            return true;
        }

        return $subitem !== self::SUBITEM_FORA_DO_ITEM_12
            && in_array(substr($subitem, 0, 2), self::ITENS_NO_LOCAL_DA_PRESTACAO, true);
    }
}

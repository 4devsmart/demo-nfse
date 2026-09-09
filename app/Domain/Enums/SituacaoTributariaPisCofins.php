<?php

declare(strict_types=1);

namespace App\Domain\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * `valores.tribFed.CST`. O dominio inteiro veio da NT 007 (7/2/2026), que o
 * atualizou junto com o resto do grupo `piscofins`.
 *
 * O valor e string, e nao inteiro, porque o campo tem dois digitos com zero a
 * esquerda: `01` no XML nao e o mesmo texto que `1`.
 *
 * O dominio e maior do que uma NFS-e usa. Ele descreve tambem entrada, credito
 * e credito presumido, que sao situacoes de quem adquire, nao de quem presta.
 * Esta escrito inteiro porque e o dominio do leiaute; quem escolhe o que a tela
 * oferece e `deSaida()`.
 */
enum SituacaoTributariaPisCofins: string implements HasLabel
{
    case Nenhum = '00';
    case TributavelAliquotaBasica = '01';
    case TributavelAliquotaDiferenciada = '02';
    case TributavelPorUnidadeDeMedida = '03';
    case MonofasicaRevendaAliquotaZero = '04';
    case TributavelPorSubstituicaoTributaria = '05';
    case TributavelAliquotaZero = '06';
    case IsentaDaContribuicao = '07';
    case SemIncidenciaDaContribuicao = '08';
    case ComSuspensaoDaContribuicao = '09';
    case OutrasOperacoesDeSaida = '49';
    case CreditoTributadaMercadoInterno = '50';
    case CreditoNaoTributadaMercadoInterno = '51';
    case CreditoExportacao = '52';
    case CreditoTributadaENaoTributadaMercadoInterno = '53';
    case CreditoTributadaMercadoInternoEExportacao = '54';
    case CreditoNaoTributadaMercadoInternoEExportacao = '55';
    case CreditoTributadaENaoTributadaEExportacao = '56';
    case CreditoPresumidoTributadaMercadoInterno = '60';
    case CreditoPresumidoNaoTributadaMercadoInterno = '61';
    case CreditoPresumidoExportacao = '62';
    case CreditoPresumidoTributadaENaoTributadaMercadoInterno = '63';
    case CreditoPresumidoTributadaMercadoInternoEExportacao = '64';
    case CreditoPresumidoNaoTributadaMercadoInternoEExportacao = '65';
    case CreditoPresumidoTributadaENaoTributadaEExportacao = '66';
    case CreditoPresumidoOutrasOperacoes = '67';
    case AquisicaoSemDireitoACredito = '70';
    case AquisicaoComIsencao = '71';
    case AquisicaoComSuspensao = '72';
    case AquisicaoAliquotaZero = '73';
    case AquisicaoSemIncidencia = '74';
    case AquisicaoPorSubstituicaoTributaria = '75';
    case OutrasOperacoesDeEntrada = '98';
    case OutrasOperacoes = '99';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nenhum => __('Nenhum'),
            self::TributavelAliquotaBasica => __('Operação tributável com alíquota básica'),
            self::TributavelAliquotaDiferenciada => __('Operação tributável com alíquota diferenciada'),
            self::TributavelPorUnidadeDeMedida => __('Operação tributável com alíquota por unidade de medida de produto'),
            self::MonofasicaRevendaAliquotaZero => __('Operação tributável monofásica: revenda a alíquota zero'),
            self::TributavelPorSubstituicaoTributaria => __('Operação tributável por substituição tributária'),
            self::TributavelAliquotaZero => __('Operação tributável a alíquota zero'),
            self::IsentaDaContribuicao => __('Operação isenta da contribuição'),
            self::SemIncidenciaDaContribuicao => __('Operação sem incidência da contribuição'),
            self::ComSuspensaoDaContribuicao => __('Operação com suspensão da contribuição'),
            self::OutrasOperacoesDeSaida => __('Outras operações de saída'),
            self::CreditoTributadaMercadoInterno => __('Com direito a crédito: vinculada exclusivamente a receita tributada no mercado interno'),
            self::CreditoNaoTributadaMercadoInterno => __('Com direito a crédito: vinculada exclusivamente a receita não tributada no mercado interno'),
            self::CreditoExportacao => __('Com direito a crédito: vinculada exclusivamente a receita de exportação'),
            self::CreditoTributadaENaoTributadaMercadoInterno => __('Com direito a crédito: vinculada a receitas tributadas e não tributadas no mercado interno'),
            self::CreditoTributadaMercadoInternoEExportacao => __('Com direito a crédito: vinculada a receitas tributadas no mercado interno e de exportação'),
            self::CreditoNaoTributadaMercadoInternoEExportacao => __('Com direito a crédito: vinculada a receitas não tributadas no mercado interno e de exportação'),
            self::CreditoTributadaENaoTributadaEExportacao => __('Com direito a crédito: vinculada a receitas tributadas e não tributadas no mercado interno e de exportação'),
            self::CreditoPresumidoTributadaMercadoInterno => __('Crédito presumido: aquisição vinculada exclusivamente a receita tributada no mercado interno'),
            self::CreditoPresumidoNaoTributadaMercadoInterno => __('Crédito presumido: aquisição vinculada exclusivamente a receita não tributada no mercado interno'),
            self::CreditoPresumidoExportacao => __('Crédito presumido: aquisição vinculada exclusivamente a receita de exportação'),
            self::CreditoPresumidoTributadaENaoTributadaMercadoInterno => __('Crédito presumido: aquisição vinculada a receitas tributadas e não tributadas no mercado interno'),
            self::CreditoPresumidoTributadaMercadoInternoEExportacao => __('Crédito presumido: aquisição vinculada a receitas tributadas no mercado interno e de exportação'),
            self::CreditoPresumidoNaoTributadaMercadoInternoEExportacao => __('Crédito presumido: aquisição vinculada a receitas não tributadas no mercado interno e de exportação'),
            self::CreditoPresumidoTributadaENaoTributadaEExportacao => __('Crédito presumido: aquisição vinculada a receitas tributadas e não tributadas no mercado interno e de exportação'),
            self::CreditoPresumidoOutrasOperacoes => __('Crédito presumido: outras operações'),
            self::AquisicaoSemDireitoACredito => __('Aquisição sem direito a crédito'),
            self::AquisicaoComIsencao => __('Aquisição com isenção'),
            self::AquisicaoComSuspensao => __('Aquisição com suspensão'),
            self::AquisicaoAliquotaZero => __('Aquisição a alíquota zero'),
            self::AquisicaoSemIncidencia => __('Aquisição sem incidência da contribuição'),
            self::AquisicaoPorSubstituicaoTributaria => __('Aquisição por substituição tributária'),
            self::OutrasOperacoesDeEntrada => __('Outras operações de entrada'),
            self::OutrasOperacoes => __('Outras operações'),
        };
    }

    /**
     * As situacoes que cabem numa nota que o proprio prestador emite. As de
     * entrada, credito e credito presumido descrevem quem adquire, e oferece-las
     * aqui so daria ao operador trinta e tres formas de errar.
     *
     * A chave sai `int|string`, e nao ha como evitar: PHP converte chave de
     * array que seja numero decimal canonico, entao `'49'` vira `49` enquanto
     * `'01'` continua texto. Nao muda nada para quem consome, porque o
     * formulario devolve tudo como texto e e `tryFrom()` quem le.
     *
     * @return array<int|string, string>
     */
    public static function deSaida(): array
    {
        $rotulos = [];

        foreach (self::cases() as $caso) {
            if ($caso->ehDeSaida()) {
                $rotulos[$caso->value] = "{$caso->value} · {$caso->getLabel()}";
            }
        }

        return $rotulos;
    }

    /**
     * A lista e escrita, e nao deduzida da faixa numerica do codigo: `'49' <=
     * '09'` compara numero em PHP 8, e uma regra que depende disso quebra em
     * silencio no dia em que um codigo novo entrar fora da faixa.
     */
    public function ehDeSaida(): bool
    {
        return in_array($this, [
            self::Nenhum,
            self::TributavelAliquotaBasica,
            self::TributavelAliquotaDiferenciada,
            self::TributavelPorUnidadeDeMedida,
            self::MonofasicaRevendaAliquotaZero,
            self::TributavelPorSubstituicaoTributaria,
            self::TributavelAliquotaZero,
            self::IsentaDaContribuicao,
            self::SemIncidenciaDaContribuicao,
            self::ComSuspensaoDaContribuicao,
            self::OutrasOperacoesDeSaida,
            self::OutrasOperacoes,
        ], true);
    }

    /**
     * Se ha contribuicao devida a calcular. Nas demais situacoes a aliquota nao
     * se aplica, e um `vPis` calculado sobre elas seria numero inventado.
     */
    public function temContribuicaoDevida(): bool
    {
        return $this === self::TributavelAliquotaBasica
            || $this === self::TributavelAliquotaDiferenciada
            || $this === self::TributavelPorUnidadeDeMedida;
    }
}

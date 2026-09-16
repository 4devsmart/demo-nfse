<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Acoes;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

/**
 * O ponto único de acesso às ações da nota: a listagem e a página de detalhe
 * montam as mesmas, e é daqui que as duas as pedem.
 *
 * As ações em si moram nas classes por tema: emissão, consulta, correção e
 * documento. Esta fachada existe para que quem chama não precise saber disso.
 */
final class AcoesDaNota
{
    /**
     * Todas, na ordem do fluxo. Cada uma decide sozinha se aparece.
     *
     * @return array<int, Action|ActionGroup>
     */
    public static function todas(): array
    {
        return [
            self::emitir(),
            self::gerarDps(),
            self::transmitir(),
            self::consultarLote(),
            self::consultarDps(),
            self::consultarNoProvedor(),
            self::consultarPorRps(),
            self::substituir(),
            self::baixarDanfse(),
            self::baixarXml(),
            self::buscarXmlDoEvento(),
            self::verPayload(),
            self::verXml(),
            self::editar(),
            self::cancelar(),
        ];
    }

    public static function emitir(): Action
    {
        return AcoesDeEmissao::emitir();
    }

    public static function gerarDps(): Action
    {
        return AcoesDeEmissao::gerarDps();
    }

    public static function transmitir(): Action
    {
        return AcoesDeEmissao::transmitir();
    }

    public static function consultarLote(): Action
    {
        return AcoesDeConsulta::consultarLote();
    }

    public static function consultarDps(): Action
    {
        return AcoesDeConsulta::consultarDps();
    }

    public static function consultarNoProvedor(): Action
    {
        return AcoesDeConsulta::consultarNoProvedor();
    }

    public static function consultarPorRps(): Action
    {
        return AcoesDeConsulta::consultarPorRps();
    }

    public static function cancelar(): Action
    {
        return AcoesDeCorrecao::cancelar();
    }

    public static function substituir(): Action
    {
        return AcoesDeCorrecao::substituir();
    }

    public static function editar(): Action
    {
        return AcoesDeCorrecao::editar();
    }

    public static function baixarDanfse(): Action
    {
        return AcoesDeDocumento::baixarDanfse();
    }

    public static function baixarXml(): ActionGroup
    {
        return AcoesDeDocumento::baixarXml();
    }

    public static function buscarXmlDoEvento(): Action
    {
        return AcoesDeDocumento::buscarXmlDoEvento();
    }

    public static function verPayload(): Action
    {
        return AcoesDeDocumento::verPayload();
    }

    public static function verXml(): Action
    {
        return AcoesDeDocumento::verXml();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Apoio;

/**
 * Retornos da biblioteca fiscal para a consulta por RPS no GISS, no formato em
 * que chegam: INI com o XML do provedor numa linha so.
 *
 * O primeiro e o de uma NFS-e depois do cancelamento, com a assinatura e os
 * valores reduzidos ao que a leitura usa. O namespace padrao fica na raiz, como
 * o GISS manda, porque e ele que o recorte do `<CompNfse>` nao pode perder.
 */
final class RetornoDoGiss
{
    public static function nfseCancelada(): string
    {
        $xml = '<ConsultarNfseRpsResposta xmlns="http://www.giss.com.br/tipos-v2_04.xsd">'
            .'<CompNfse><Nfse versao="2.00"><InfNfse Id="3234688"><Numero>86</Numero>'
            .'<CodigoVerificacao>F4XBC8RJD</CodigoVerificacao>'
            .'<DataEmissao>2026-09-16T10:54:20.211-03:00</DataEmissao></InfNfse></Nfse>'
            .'<NfseCancelamento><Confirmacao><Pedido><InfPedidoCancelamento><IdentificacaoNfse>'
            .'<Numero>86</Numero></IdentificacaoNfse><CodigoCancelamento>0</CodigoCancelamento>'
            .'</InfPedidoCancelamento></Pedido><DataHora>2026-09-16T10:57:25.733-03:00</DataHora>'
            .'</Confirmacao></NfseCancelamento></CompNfse></ConsultarNfseRpsResposta>';

        return "[ConsultaNFSePorRps]\nCodVerificacao=F4XBC8RJD\nData=16/09/2026 10:54:20\n"
            ."NumNotaSubstituidora=\nNumRPS=1\nNumeroNota=86\nSerie=1\nTipo=1\n"
            ."XmlEnvio=<ConsultarNfseRpsEnvio/>\nXmlRetorno={$xml}\n";
    }

    public static function nfseAutorizada(): string
    {
        return str_replace(
            ['<NfseCancelamento><Confirmacao><Pedido><InfPedidoCancelamento><IdentificacaoNfse><Numero>86</Numero></IdentificacaoNfse><CodigoCancelamento>0</CodigoCancelamento></InfPedidoCancelamento></Pedido><DataHora>2026-09-16T10:57:25.733-03:00</DataHora></Confirmacao></NfseCancelamento>'],
            [''],
            self::nfseCancelada(),
        );
    }

    /** O que o GISS respondeu para um RPS que nao virou nota. */
    public static function semNota(): string
    {
        return "[Erro1]\nCodigo=V999\nCorrecao=\nDescricao=E89\n\n"
            ."[Erro2]\nCodigo=X203\nCorrecao=\nDescricao=Não foi retornado nenhuma NFSe.\n\n"
            ."[ConsultaNFSePorRps]\nCodVerificacao=\nData=30/12/1899\nNumRPS=1\nNumeroNota=\nSerie=1\nTipo=1\n";
    }
}

<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * Uma pagina da distribuicao DF-e do ADN.
 *
 * O retorno da distribuicao vem cru, como o das consultas, e nele o lote e um
 * JSON no meio do texto da biblioteca fiscal, entre `XmlRetorno=` e a primeira
 * secao `[ArquivoN]`. Ler isso aqui e o preco de a API nao normalizar; a
 * alternativa seria a Action conhecer o formato da biblioteca.
 *
 * Cada `ArquivoXml` vem em base64 e, na pratica, gzipado por dentro. O gzip nao
 * esta no contrato, entao a decisao e pelo conteudo: comeca com a assinatura do
 * gzip, descompacta; nao comeca, e o XML direto.
 */
final readonly class LoteDeDocumentos
{
    private const MARCA_DO_RETORNO = 'XmlRetorno=';

    private const ASSINATURA_DO_GZIP = "\x1f\x8b";

    /**
     * @param  list<DocumentoDistribuido>  $documentos
     */
    public function __construct(public array $documentos) {}

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo): self
    {
        $documentos = [];

        foreach (self::loteDe((new LeitorDaResposta($corpo))->texto('resposta')) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lido = new LeitorDaResposta($item);

            $documentos[] = new DocumentoDistribuido(
                nsu: $lido->inteiro('NSU', 0),
                chave: $lido->texto('ChaveAcesso'),
                tipo: $lido->texto('TipoDocumento'),
                xml: self::documentoDe($lido->texto('ArquivoXml')),
            );
        }

        return new self($documentos);
    }

    public function vazio(): bool
    {
        return $this->documentos === [];
    }

    /**
     * O maior NSU visto, que e por onde a proxima pagina comeca. O cursor e de
     * quem chama: a API nao guarda onde se parou.
     */
    public function ultimoNsu(int $anterior): int
    {
        foreach ($this->documentos as $documento) {
            $anterior = max($anterior, $documento->nsu);
        }

        return $anterior;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function loteDe(string $texto): array
    {
        $inicio = strpos($texto, self::MARCA_DO_RETORNO);

        if ($inicio === false) {
            return [];
        }

        $json = substr($texto, $inicio + strlen(self::MARCA_DO_RETORNO));
        $fim = strpos($json, "\n[");

        $lote = json_decode($fim === false ? $json : substr($json, 0, $fim), true);

        return is_array($lote) && is_array($lote['LoteDFe'] ?? null) ? $lote['LoteDFe'] : [];
    }

    private static function documentoDe(string $emBase64): string
    {
        $conteudo = base64_decode($emBase64, true);

        if (! is_string($conteudo)) {
            return '';
        }

        if (! str_starts_with($conteudo, self::ASSINATURA_DO_GZIP)) {
            return $conteudo;
        }

        return gzdecode($conteudo) ?: '';
    }
}

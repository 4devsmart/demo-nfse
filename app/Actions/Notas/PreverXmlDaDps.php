<?php

declare(strict_types=1);

namespace App\Actions\Notas;

use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Traducao\MontadorDaDps;
use App\Models\Nota;
use DOMDocument;

/**
 * O XML da DPS para conferir, sem consequencia nenhuma.
 *
 * Usa a mesma rota que `GerarDps`, `POST /v1/nfse/xml`, e a diferenca esta no
 * que NAO acontece depois: aqui nada e gravado e o rascunho nao muda de status.
 * A rota e segura para isso pelo proprio contrato do gateway, que diz o que ela
 * faz e o que nao faz: monta a DPS, nao assina, nao fala com a prefeitura e nao
 * pede certificado. E o `id_dps` e deterministico, entao chamar duas vezes
 * devolve o mesmo documento.
 *
 * Existe como caso de uso, e nao como metodo da tela, porque falar com a API e
 * assunto de `Actions`: a tela nao alcanca o gateway.
 */
final readonly class PreverXmlDaDps
{
    public function __construct(
        private GatewayFiscal $gateway,
        private MontadorDaDps $montador,
    ) {}

    public function executar(Nota $nota): string
    {
        $gerada = $this->gateway->gerarDps($this->montador->montar($nota));

        return $this->indentado($gerada->xml());
    }

    /**
     * A API devolve o XML numa linha so, que e o certo para assinar e errado
     * para ler: e a indentacao que torna a conferencia possivel.
     *
     * `formatOutput` nao mexe no conteudo, so em espaco entre elementos, e a
     * copia indentada nunca volta para a API: quem transmite e `GerarDps`, com
     * o base64 original.
     */
    private function indentado(string $xml): string
    {
        if ($xml === '') {
            return '';
        }

        $documento = new DOMDocument;
        $documento->preserveWhiteSpace = false;
        $documento->formatOutput = true;

        if (! @$documento->loadXML($xml)) {
            return $xml;
        }

        return $documento->saveXML() ?: $xml;
    }
}

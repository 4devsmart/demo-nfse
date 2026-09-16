<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * O que a consulta de uma NFS-e ABRASF respondeu, lido em vez de despejado.
 *
 * O retorno cru mostrava 400 caracteres de INI e cortava justo o que importava:
 * uma NFS-e do GISS estava cancelada, e o cancelamento so aparece no
 * `<NfseCancelamento>` do XML devolvido, depois de tudo. O INI da biblioteca
 * traz numero, codigo de verificacao, data e RPS; o XML do provedor traz o
 * cancelamento, a substituicao e o documento em si.
 *
 * Le so o que encontra, e o que nao encontra fica vazio: nao ha deducao sobre
 * o que o provedor nao disse.
 */
final readonly class NfseConsultada
{
    /** O TDateTime zerado, que a biblioteca escreve quando nao ha data. */
    private const DATA_VAZIA = '30/12/1899';

    private function __construct(
        public string $numero,
        public string $codigoDeVerificacao,
        public string $emitidaEm,
        public string $numeroDoRps,
        public string $serieDoRps,
        public bool $cancelada,
        public string $canceladaEm,
        public string $substituidaPor,
        public string $documento,
        public Mensagens $mensagens,
    ) {}

    public static function doRetorno(RespostaCrua $resposta): self
    {
        $secoes = self::secoes($resposta->resposta);
        $campos = self::camposDaConsulta($secoes);
        $xml = self::documentoDoRetorno($campos['XmlRetorno'] ?? '');

        $nfse = $xml === null ? null : self::primeiro($xml, 'CompNfse');
        $cancelamento = $nfse === null ? null : self::primeiro($nfse, 'NfseCancelamento');
        $substituidora = $nfse === null ? null : self::primeiro($nfse, 'NfseSubstituidora');

        return new self(
            numero: $campos['NumeroNota'] ?? '',
            codigoDeVerificacao: $campos['CodVerificacao'] ?? '',
            emitidaEm: self::dataDoIni($campos['Data'] ?? ''),
            numeroDoRps: $campos['NumRPS'] ?? '',
            serieDoRps: $campos['Serie'] ?? '',
            cancelada: $cancelamento !== null,
            canceladaEm: $cancelamento === null ? '' : self::dataDoXml(self::texto($cancelamento, 'DataHora')),
            substituidaPor: ($campos['NumNotaSubstituidora'] ?? '') ?: ($substituidora->textContent ?? ''),
            documento: $nfse === null ? '' : self::comoDocumento($nfse),
            mensagens: Mensagens::daLista(self::mensagensDe($secoes)),
        );
    }

    public function foiEncontrada(): bool
    {
        return $this->numero !== '';
    }

    /**
     * A nota tem registro de cancelamento ou de substituicao no provedor, e o
     * documento que o prova veio junto.
     */
    public function temDocumentoDeEvento(): bool
    {
        return ($this->cancelada || $this->substituidaPor !== '') && $this->documento !== '';
    }

    /**
     * O INI da biblioteca: `[Secao]` e `Chave=Valor`, com o XML inteiro numa
     * linha so. `parse_ini_string` nao serve, porque o valor tem `=`, aspas e
     * sinais que ele interpreta.
     *
     * @return array<string, array<string, string>>
     */
    private static function secoes(string $texto): array
    {
        $secoes = [];
        $atual = '';

        foreach (preg_split('/\R/', $texto) ?: [] as $linha) {
            $linha = trim($linha);

            if (preg_match('/^\[(.+)\]$/', $linha, $cabecalho) === 1) {
                $atual = $cabecalho[1];

                continue;
            }

            if ($atual === '' || ! str_contains($linha, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $linha, 2);
            $secoes[$atual][trim($chave)] = trim($valor);
        }

        return $secoes;
    }

    /**
     * A secao da consulta muda de nome conforme a chamada (`ConsultaNFSePorRps`,
     * `ConsultaNFSe`), e as de erro e alerta sao numeradas. Junta as que nao sao
     * mensagem.
     *
     * @param  array<string, array<string, string>>  $secoes
     * @return array<string, string>
     */
    private static function camposDaConsulta(array $secoes): array
    {
        $campos = [];

        foreach ($secoes as $nome => $valores) {
            if (! self::ehMensagem($nome)) {
                $campos = [...$campos, ...$valores];
            }
        }

        return $campos;
    }

    /**
     * @param  array<string, array<string, string>>  $secoes
     * @return list<array{codigo: string, descricao: string}>
     */
    private static function mensagensDe(array $secoes): array
    {
        $mensagens = [];

        foreach ($secoes as $nome => $valores) {
            if (self::ehMensagem($nome)) {
                $mensagens[] = ['codigo' => $valores['Codigo'] ?? '', 'descricao' => $valores['Descricao'] ?? ''];
            }
        }

        return $mensagens;
    }

    private static function ehMensagem(string $secao): bool
    {
        return preg_match('/^(Erro|Alerta)\d*$/', $secao) === 1;
    }

    private static function documentoDoRetorno(string $xml): ?DOMDocument
    {
        if (! str_starts_with($xml, '<')) {
            return null;
        }

        $documento = new DOMDocument;

        return @$documento->loadXML($xml, LIBXML_NONET) ? $documento : null;
    }

    private static function primeiro(DOMDocument|DOMElement $origem, string $nome): ?DOMElement
    {
        $encontrado = $origem->getElementsByTagNameNS('*', $nome)->item(0);

        return $encontrado instanceof DOMElement ? $encontrado : null;
    }

    private static function texto(DOMElement $origem, string $nome): string
    {
        return trim(self::primeiro($origem, $nome)->textContent ?? '');
    }

    /**
     * O `<CompNfse>` como documento proprio, e nao recortado como texto: o
     * namespace padrao esta declarado na raiz da resposta, e o recorte cru o
     * perderia. `importNode` declara de novo o que o elemento usa.
     */
    private static function comoDocumento(DOMElement $nfse): string
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->appendChild($documento->importNode($nfse, true));

        return (string) $documento->saveXML();
    }

    private static function dataDoIni(string $data): string
    {
        return $data === '' || str_starts_with($data, self::DATA_VAZIA) ? '' : $data;
    }

    /**
     * O provedor manda ISO 8601 com o fuso dele ("2026-09-16T10:57:25.733-03:00").
     * A hora mostrada e a do fuso que veio, que e a da prefeitura.
     */
    private static function dataDoXml(string $data): string
    {
        if ($data === '') {
            return '';
        }

        try {
            return Carbon::parse($data)->format('d/m/Y H:i:s');
        } catch (Throwable) {
            return $data;
        }
    }
}

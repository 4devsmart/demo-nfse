<?php

declare(strict_types=1);

namespace App\Fiscal\Respostas;

/**
 * O retorno de um evento (cancelamento, substituicao).
 *
 * `numero` e `codigoDeVerificacao` sao do documento que o evento criou, e so a
 * substituicao os traz: o provedor que devolve os dois esta dizendo qual NFS-e
 * nasceu no lugar da antiga. Descartar isso deixava a substituta autorizada sem
 * numero, e sem numero ela nao pode ser substituida de novo.
 */
final readonly class EventoRegistrado
{
    public function __construct(
        public string $tipo,
        public string $status,
        public string $chave,
        public string $protocolo,
        public string $dataHora,
        public string $xmlEmBase64,
        public Mensagens $mensagens,
        public string $numero = '',
        public string $codigoDeVerificacao = '',
    ) {}

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function doCorpoDaResposta(array $corpo): self
    {
        $resposta = new LeitorDaResposta($corpo);

        return new self(
            tipo: $resposta->texto('tipo'),
            status: $resposta->texto('status'),
            chave: $resposta->texto('chave'),
            protocolo: $resposta->texto('protocolo'),
            dataHora: $resposta->texto('data_hora'),
            xmlEmBase64: $resposta->texto('xml_b64'),
            mensagens: Mensagens::daLista($resposta->lista('mensagens')),
            numero: $resposta->texto('numero'),
            codigoDeVerificacao: $resposta->texto('codigo_verificacao'),
        );
    }

    public function foiConcluido(): bool
    {
        return in_array($this->status, ['concluido', 'autorizado', 'registrado'], true);
    }

    /**
     * O XML do evento, quando o que veio em `xml_b64` for mesmo um documento.
     *
     * O campo esta documentado como "XML do evento, em base64", mas nem sempre
     * e isso que chega: o cancelamento no Padrao Nacional volta com o evento
     * registrado, protocolo vazio e, no lugar do documento, a frase "Indice
     * informado nao encontrado", que e a biblioteca fiscal se explicando. E o
     * unico jeito de saber e olhar: guardar aquilo como XML entregaria ao
     * operador um arquivo `.xml` com uma frase de erro dentro.
     */
    public function documentoDoEvento(): ?string
    {
        $conteudo = base64_decode($this->xmlEmBase64, true);

        if (! is_string($conteudo) || ! str_starts_with(ltrim($conteudo), '<')) {
            return null;
        }

        return $this->xmlEmBase64;
    }
}

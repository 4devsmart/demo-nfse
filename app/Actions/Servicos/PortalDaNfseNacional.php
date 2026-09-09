<?php

declare(strict_types=1);

namespace App\Actions\Servicos;

use Illuminate\Http\Client\Factory as ClienteHttp;
use RuntimeException;

/**
 * A tabela `cTribNac` na origem: a pagina que o Portal Nacional da NFS-e
 * publica sob o titulo "Lista de Servicos Anexa a Lei Complementar nº 116". So
 * e usada quando alguem pede a atualizacao; a carga do dia a dia sai do arquivo
 * local.
 *
 * A pagina nao oferece CSV nem JSON, mas o que ela tem e melhor que uma tabela
 * HTML: cada codigo e uma linha de texto no formato `NNNNNN - descricao`, um
 * por paragrafo. Ler linha a linha sobrevive a remontagem do layout, que e o
 * que costuma quebrar quem depende da posicao das celulas.
 */
final readonly class PortalDaNfseNacional implements FonteDeCodigosDeTributacao
{
    /** Ver `WrapperFiscal`: o aperto de mao nao usa o timeout de leitura. */
    private const SEGUNDOS_PARA_CONECTAR = 3;

    private const LINHA = '/^(\d{6})\s*-\s*(.+)$/u';

    /**
     * O que de fato termina um paragrafo. O resto e marcacao dentro do texto.
     *
     * `<br>` ficou de fora, e essa e a diferenca que importa: e a marcacao mais
     * comum DENTRO da descricao, e trata-la como fim de bloco cortava o texto no
     * meio. O pedaco sobrevivente ainda casa "NNNNNN - descricao" e entra na
     * tabela por cima do texto inteiro, sem mudar a contagem de codigos, entao
     * nem a guarda de leitura parcial nem a tela percebem.
     */
    private const FIM_DE_BLOCO = '#</(?:p|div|li|tr|td|th|h[1-6]|section|article)\s*>#i';

    /** Quebra dentro do texto vira espaco, e nao fim de linha. */
    private const QUEBRA_NO_TEXTO = '#<br\s*/?>#i';

    private const BLOCO_DE_CODIGO = '#<(script|style)\b[^>]*>.*?</\1\s*>#is';

    /**
     * O portal fica atras de um WAF que responde 403 ao `User-Agent` padrao do
     * cliente HTTP ("GuzzleHttp/7"). Sem cabecalho nenhum ele deixa passar, e
     * com um de navegador tambem; e so o nome da biblioteca que ele recusa.
     * Anunciar um navegador e o que mantem a atualizacao funcionando.
     */
    private const NAVEGADOR = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function __construct(
        private ClienteHttp $http,
        private string $url,
        private int $segundosDeTimeout = 60,
    ) {}

    public function codigos(): array
    {
        $resposta = $this->http
            ->connectTimeout(self::SEGUNDOS_PARA_CONECTAR)
            ->timeout($this->segundosDeTimeout)
            ->withHeader('User-Agent', self::NAVEGADOR)
            ->get($this->url);

        if ($resposta->failed()) {
            throw new RuntimeException(__('O Portal Nacional respondeu :status.', ['status' => $resposta->status()]));
        }

        $codigos = $this->lerPagina($resposta->body());

        if ($codigos === []) {
            throw new RuntimeException(__('A página do Portal Nacional mudou: não há mais linhas no formato "010101 - descrição".'));
        }

        return $codigos;
    }

    /**
     * A pagina lista fora de ordem em alguns pontos, e repete codigo quando o
     * mesmo servico aparece em duas secoes. Ordenar e descartar repetido aqui
     * deixa a importacao com uma lista pronta.
     *
     * O controle de repetidos e um conjunto a parte, e nao a chave do resultado:
     * chave numerica de array PHP vira inteiro, e "140101" deixaria de ser texto
     * no meio do caminho.
     *
     * @return list<array{codigo: string, item_lista_servico: string, descricao: string}>
     */
    private function lerPagina(string $html): array
    {
        $texto = html_entity_decode($this->emLinhas($this->emUtf8($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $codigos = [];
        $vistos = [];

        foreach (explode("\n", $texto) as $linha) {
            $limpa = trim((string) preg_replace('/\s+/u', ' ', $linha));

            if (preg_match(self::LINHA, $limpa, $partes) !== 1 || isset($vistos[$partes[1]])) {
                continue;
            }

            $vistos[$partes[1]] = true;

            $codigos[] = [
                'codigo' => $partes[1],
                'item_lista_servico' => substr($partes[1], 0, 4),
                'descricao' => trim($partes[2]),
            ];
        }

        usort($codigos, static fn (array $um, array $outro): int => $um['codigo'] <=> $outro['codigo']);

        return $codigos;
    }

    /**
     * Um paragrafo por linha. So o fim de bloco quebra a linha; o resto da
     * marcacao some sem deixar quebra nenhuma.
     *
     * A diferenca importa e e silenciosa: quebrar em toda tag parte a descricao
     * no primeiro `<b>` ou `<a>` que a pagina venha a ter dentro do texto, e o
     * que sobra ("Análise e") entra na tabela por cima da descricao inteira que
     * ja estava la. Um negrito acrescentado no portal corromperia a linha.
     */
    private function emLinhas(string $html): string
    {
        // Script e estilo saem inteiros: sem isto o corpo deles vira texto solto
        // e pode passar por linha da tabela.
        $limpo = (string) preg_replace(self::BLOCO_DE_CODIGO, ' ', $html);
        $limpo = (string) preg_replace(self::QUEBRA_NO_TEXTO, ' ', $limpo);
        $limpo = (string) preg_replace(self::FIM_DE_BLOCO, "\n", $limpo);

        return (string) preg_replace('/<[^>]+>/', '', $limpo);
    }

    /**
     * A leitura toda depende do modificador `u`, e `preg_replace` com `u`
     * devolve `null` diante de byte que nao seja UTF-8 valido. Com o `(string)`
     * na frente isso vira string vazia, e a linha some sem erro nenhum: numa
     * pagina Latin-1, que ainda e comum em .gov.br, sumiriam justamente as
     * linhas com acento, que sao quase todas.
     *
     * Converter antes resolve os dois casos de uma vez. Pagina que ja e UTF-8
     * passa intacta.
     */
    private function emUtf8(string $html): string
    {
        return mb_check_encoding($html, 'UTF-8')
            ? $html
            : (string) mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }
}

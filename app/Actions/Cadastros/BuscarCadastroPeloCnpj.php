<?php

declare(strict_types=1);

namespace App\Actions\Cadastros;

use App\Actions\Enderecos\EnderecoEncontrado;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\DocumentoFederal;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as ClienteHttp;
use InvalidArgumentException;
use RuntimeException;

/**
 * Traz o cadastro da Receita pelo CNPJ para preencher emitente e tomador pessoa
 * juridica. Como a busca de CEP, e conveniencia de cadastro e nao regra fiscal:
 * servico fora do ar vira mensagem, e o formulario continua preenchivel a mao.
 */
final readonly class BuscarCadastroPeloCnpj
{
    /** Ver `WrapperFiscal`: o aperto de mao nao usa o timeout de leitura. */
    private const SEGUNDOS_PARA_CONECTAR = 3;

    /**
     * Cadastro da Receita muda pouco, mas muda. Um dia cobre o preenchimento de
     * um cadastro inteiro e ja poupa o limite de requisicoes do servico, que
     * responde 429 quando o mesmo CNPJ e pedido varias vezes seguidas.
     */
    private const HORAS_EM_CACHE = 24;

    public function __construct(
        private ClienteHttp $http,
        private Cache $cache,
        private string $url,
        private int $segundosDeTimeout = 8,
    ) {}

    /**
     * O cache guarda o corpo da resposta, e nao o objeto pronto. O
     * `unserialize()` do PHP nao dispara autoload, porque o
     * `unserialize_callback_func` vem vazio, e o acerto de cache e justamente o
     * caminho em que a classe do DTO ainda nao foi carregada no processo: o
     * objeto voltava como `__PHP_Incomplete_Class` e estourava no tipo de
     * retorno. Array nao tem esse problema, e ainda sobrevive a renomear campo.
     *
     * A chave carrega a origem porque o formato do corpo e dela: trocar de
     * servico invalida o que estava guardado, sem depender de alguem limpar o
     * cache na hora do deploy.
     */
    public function executar(string $cnpj): CadastroEncontrado
    {
        $digitos = $this->digitosDoCnpj($cnpj);

        $corpo = $this->cache->remember(
            "cnpj:brasilapi:{$digitos}",
            now()->addHours(self::HORAS_EM_CACHE),
            fn (): array => $this->consultar($digitos),
        );

        return $this->cadastroDe((array) $corpo);
    }

    /**
     * O documento e conferido antes de sair: CNPJ com digito verificador errado
     * so voltaria como "nao encontrado", que manda procurar o erro no lugar
     * errado.
     */
    private function digitosDoCnpj(string $cnpj): string
    {
        try {
            $documento = DocumentoFederal::deCpfOuCnpj($cnpj);
        } catch (InvalidArgumentException) {
            throw new RuntimeException(__('Informe um CNPJ válido para consultar.'));
        }

        if (! $documento->ehCnpj()) {
            throw new RuntimeException(__('A consulta é pelo CNPJ. Não há cadastro público de CPF.'));
        }

        return $documento->digitos;
    }

    /**
     * @return array<string, mixed>
     */
    private function consultar(string $digitos): array
    {
        try {
            $resposta = $this->http
                ->connectTimeout(self::SEGUNDOS_PARA_CONECTAR)
                ->timeout($this->segundosDeTimeout)
                ->acceptJson()
                ->get(str_replace('{cnpj}', $digitos, $this->url));
        } catch (ConnectionException $falha) {
            throw new RuntimeException(__('A consulta de CNPJ não respondeu: :motivo', ['motivo' => $falha->getMessage()]));
        }

        if ($resposta->tooManyRequests()) {
            throw new RuntimeException(__('A consulta de CNPJ atingiu o limite do serviço. Tente de novo em instantes.'));
        }

        if ($resposta->failed()) {
            throw new RuntimeException(__('CNPJ :cnpj não encontrado na Receita.', ['cnpj' => $digitos]));
        }

        return (array) $resposta->json();
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function cadastroDe(array $corpo): CadastroEncontrado
    {
        return new CadastroEncontrado(
            razaoSocial: $this->texto($corpo, 'razao_social'),
            nomeFantasia: $this->texto($corpo, 'nome_fantasia'),
            cnaePrincipal: $this->texto($corpo, 'cnae_fiscal'),
            telefone: $this->digitos($this->texto($corpo, 'ddd_telefone_1')),
            email: mb_strtolower($this->texto($corpo, 'email')),
            situacao: $this->texto($corpo, 'descricao_situacao_cadastral'),
            endereco: new EnderecoEncontrado(
                // A Receita guarda o tipo em campo separado: o logradouro do
                // Carrefour e "TUCUNARE", com "AVENIDA" ao lado.
                logradouro: trim($this->texto($corpo, 'descricao_tipo_de_logradouro').' '.$this->texto($corpo, 'logradouro')),
                bairro: $this->texto($corpo, 'bairro'),
                localidade: $this->texto($corpo, 'municipio'),
                uf: $this->texto($corpo, 'uf'),
                municipio: $this->municipioDe($corpo),
                cep: $this->digitos($this->texto($corpo, 'cep')),
                numero: $this->texto($corpo, 'numero'),
                complemento: $this->texto($corpo, 'complemento'),
            ),
        );
    }

    /**
     * `codigo_municipio_ibge` e o codigo de sete digitos, o mesmo das cidades
     * daqui. O `codigo_municipio`, sem sufixo, e o codigo da Receita e nao
     * serve: sao 6213 para Barueri, contra 3505708.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function municipioDe(array $corpo): ?CodigoIbge
    {
        $codigo = $this->texto($corpo, 'codigo_municipio_ibge');

        return CodigoIbge::ehValido($codigo) ? CodigoIbge::deSeteDigitos($codigo) : null;
    }

    /**
     * O CNAE principal chega como inteiro, o telefone e o CEP como texto, e o
     * que nao existe chega como `null`. Uma leitura so evita repetir o cast.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function texto(array $corpo, string $campo): string
    {
        $valor = $corpo[$campo] ?? null;

        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Domain\Enums\TipoPessoa;
use App\Domain\ValueObjects\Dinheiro;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Rules\DocumentoFederalValido;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\RawJs;

/**
 * Campos que aparecem em mais de um formulario. A mascara e a limpeza andam
 * juntas: o usuario digita com pontuacao e o banco recebe so os digitos, sem
 * depender de cada tela lembrar de limpar.
 */
final class Campos
{
    /**
     * A máscara escolhe o molde pela contagem de dígitos, e não pelo tamanho do
     * texto. Quem hidrata o campo é a coluna, que guarda só dígito, e
     * "45543915000181" tem os mesmos 14 caracteres de um CPF já mascarado:
     * medindo caracteres, o CNPJ gravado era escrito no molde de CPF, perdia os
     * dois dígitos verificadores no caminho e a tela recusava o próprio
     * cadastro ao salvar, sem ninguém ter digitado nada.
     *
     * O `formatStateUsing` fecha o mesmo buraco do outro lado, como
     * `comoMoeda()` faz com o valor: o campo já recebe o documento escrito, e a
     * máscara do navegador só confirma o que está lá.
     */
    public static function documentoFederal(string $nome = 'cpf_cnpj'): TextInput
    {
        return self::somenteDigitos(TextInput::make($nome))
            ->label(fn (Get $get): string => self::ehPessoaFisica($get) ? __('CPF') : __('CNPJ'))
            ->mask(RawJs::make(<<<'JS'
                $input.replace(/\D/g, '').length > 11 ? '99.999.999/9999-99' : '999.999.999-99'
            JS))
            ->formatStateUsing(fn (?string $state): ?string => blank($state)
                ? null
                : DocumentoFederal::mascarado($state))
            ->placeholder('00.000.000/0000-00')
            ->required()
            ->unique()
            ->rule(new DocumentoFederalValido);
    }

    public static function cnpj(string $nome = 'cnpj'): TextInput
    {
        return self::somenteDigitos(TextInput::make($nome))
            ->label(__('CNPJ'))
            ->mask('99.999.999/9999-99')
            ->placeholder('00.000.000/0000-00')
            ->required()
            ->unique()
            ->rule(new DocumentoFederalValido);
    }

    /**
     * Conta dígitos pelo mesmo motivo do documento: o celular gravado tem 11 e o
     * fixo tem 10, nenhum dos dois volta do banco com pontuação. Medindo
     * caracteres, o celular caía no molde do fixo e perdia o último dígito
     * calado, que aqui não há validação para denunciar.
     */
    public static function telefone(string $nome = 'telefone'): TextInput
    {
        return self::somenteDigitos(TextInput::make($nome))
            ->label(__('Telefone'))
            ->tel()
            ->mask(RawJs::make(<<<'JS'
                $input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-9999'
            JS))
            ->placeholder('(11) 99999-9999');
    }

    /**
     * Valor monetario com mascara brasileira: o usuario digita numeros e o campo
     * vai montando "1.500,50" da direita para a esquerda. O que chega ao banco e
     * sempre o numero, quem faz a traducao e Dinheiro, dos dois lados.
     */
    public static function dinheiro(string $nome, string $rotulo): TextInput
    {
        return TextInput::make($nome)
            ->label($rotulo)
            ->prefix(__('R$'))
            ->inputMode('decimal')
            ->placeholder('0,00')
            ->default(0)
            // Sem `stripCharacters`: ele apagaria tambem o ponto decimal de um
            // valor vindo do banco ("5.0000" viraria 50000). Quem interpreta os
            // dois formatos e Dinheiro, num lugar so.
            ->mask(RawJs::make("\$money(\$input, ',', '.', 2)"))
            ->rule(self::valorLegivel(__('Informe um valor como 1.500,50.')))
            ->formatStateUsing(fn (mixed $state): ?string => self::comoMoeda($state))
            ->dehydrateStateUsing(fn (mixed $state): float => Dinheiro::deTexto(
                is_string($state) || is_float($state) || is_int($state) ? $state : null
            )->emReais());
    }

    /**
     * Percentual guarda quatro casas no banco, mas mostrar "5,0000" so atrapalha
     * quem le. Aparece "5"; aparece "2,75" quando ha fracao.
     */
    public static function percentual(string $nome, string $rotulo): TextInput
    {
        return TextInput::make($nome)
            ->label($rotulo)
            ->suffix('%')
            ->inputMode('decimal')
            ->placeholder('0')
            ->default(0)
            ->mask(RawJs::make("\$money(\$input, ',', '.', 4)"))
            ->rule(self::valorLegivel(__('Informe um percentual como 5 ou 2,75.')))
            ->rule(self::ateCem())
            ->formatStateUsing(fn (mixed $state): ?string => self::comoPercentual($state))
            ->dehydrateStateUsing(fn (mixed $state): float => Dinheiro::numeroDoTexto((string) $state));
    }

    /**
     * Aceita as duas escritas que circulam no formulario: a mascarada
     * ("1.500,50") e a crua que veio do banco ("1500.50").
     */
    private static function valorLegivel(string $mensagem): Closure
    {
        return static fn (): Closure => static function (string $atributo, mixed $valor, Closure $falhar) use ($mensagem): void {
            $texto = trim((string) $valor);

            if ($texto === '') {
                return;
            }

            if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$|^\d+([.,]\d+)?$/', $texto) !== 1) {
                $falhar($mensagem);
            }
        };
    }

    /**
     * Piso de um campo de dinheiro. O `->minValue()` do Filament não serve aqui:
     * ele vira a regra `min:` do Laravel, que só compara número quando o campo
     * também carrega a regra `numeric`. Estes campos são mascarados e não a
     * carregam, então o `min` mede comprimento de texto, e "0,00" tem
     * quatro caracteres. O piso passava a valer sempre.
     *
     * Campo em branco é assunto do `required()`, como em `valorLegivel()`.
     */
    public static function valorMinimo(float $minimo, string $mensagem): Closure
    {
        return static fn (): Closure => static function (string $atributo, mixed $valor, Closure $falhar) use ($minimo, $mensagem): void {
            if (trim((string) $valor) === '') {
                return;
            }

            $lido = Dinheiro::deTexto(
                is_string($valor) || is_float($valor) || is_int($valor) ? $valor : null
            );

            if ($lido->centavos < Dinheiro::deReais($minimo)->centavos) {
                $falhar($mensagem);
            }
        };
    }

    private static function ateCem(): Closure
    {
        return static fn (): Closure => static function (string $atributo, mixed $valor, Closure $falhar): void {
            if (Dinheiro::numeroDoTexto((string) $valor) > 100) {
                $falhar(__('O percentual não pode passar de 100.'));
            }
        };
    }

    /**
     * O texto que estes campos mostram, no formato brasileiro.
     *
     * É público porque quem preenche um campo mascarado por código precisa
     * dele: `formatStateUsing` só roda na hidratação, então um `$set()` vindo
     * de um botão entrega o valor cru ao Livewire, e daí em diante quem manda é
     * a máscara do navegador. Ela lê `0.65` como dígitos e mostra `65`, que foi
     * exatamente o que aconteceu no botão dos percentuais de lei.
     *
     * O caminho de volta já funciona: `dehydrateStateUsing` lê "0,65" com
     * `Dinheiro::numeroDoTexto()`, a mesma leitura do que o usuário digita.
     */
    public static function comoMoeda(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        $texto = is_string($state) || is_float($state) || is_int($state) ? $state : null;

        return number_format(Dinheiro::deTexto($texto)->emReais(), 2, ',', '.');
    }

    /**
     * O par de `comoMoeda()` para percentual, e público pelo mesmo motivo.
     */
    public static function comoPercentual(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        $numero = Dinheiro::numeroDoTexto((string) $state);
        $texto = number_format($numero, 4, ',', '.');

        return rtrim(rtrim($texto, '0'), ',');
    }

    /**
     * O estado do seletor de tipo chega ora como enum, ora como string crua,
     * dependendo de o valor vir do default, do banco ou do que foi digitado.
     */
    public static function ehPessoaFisica(Get $get): bool
    {
        return $get->enum('tipo_pessoa', TipoPessoa::class, isNullable: true) === TipoPessoa::Fisica;
    }

    /**
     * A mascara e enfeite de digitacao; o que vai para o banco sao os digitos.
     * Sem isto um CNPJ mascarado tem 18 caracteres e a coluna tem 14.
     *
     * A validacao le o mesmo corte, e nao o texto mascarado. O `unique` compara
     * o estado do campo com a coluna: "45.543.915/0001-81" nunca batia com os
     * digitos gravados, entao o documento repetido passava pela validacao e ia
     * estourar no indice do banco, como erro de servidor em vez de mensagem no
     * campo.
     */
    private static function somenteDigitos(TextInput $campo): TextInput
    {
        $corte = fn (?string $state): ?string => $state === null ? null : DocumentoFederal::somenteDigitos($state);

        return $campo
            ->dehydrateStateUsing($corte)
            ->mutateStateForValidationUsing($corte);
    }
}

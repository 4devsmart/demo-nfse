<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ItemDaListaDeServicosFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um subitem da lista anexa a LC 116/2003. E o que os provedores ABRASF leem no
 * campo `ItemListaServico`, e o que o Padrao Nacional desdobra no `cTribNac`.
 *
 * Tabela de leitura: quem a preenche e a importacao.
 *
 * @property int $id
 * @property string $codigo quatro dígitos sem ponto ("0107")
 * @property string $descricao
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['codigo', 'descricao'])]
class ItemDaListaDeServicos extends Model
{
    /** @use HasFactory<ItemDaListaDeServicosFactory> */
    use HasFactory;

    protected $table = 'itens_da_lista_de_servicos';

    /**
     * A forma que a lista de servicos usa e que a nota guarda: "01.07". O banco
     * guarda "0107" para casar com o prefixo do cTribNac, e a conversao fica
     * num lugar so.
     */
    public static function pontuar(string $codigo): string
    {
        return strlen($codigo) === 4 ? substr($codigo, 0, 2).'.'.substr($codigo, 2) : $codigo;
    }

    /**
     * Os quatro digitos por tras do subitem escrito de qualquer forma valida:
     * "01.07", "1.07" e "0107" sao o mesmo "0107". Devolve `null` para tudo que
     * nao fecha, e e essa recusa que importa.
     *
     * Contar digito em fila, como se fazia aqui, perde a posicao do ponto:
     * "10.7" virava "0107", e a tela passava a afirmar suporte em informatica
     * para um registro que diz corretagem de seguros. Entre mostrar o servico
     * errado e nao mostrar nada, nao ha duvida.
     */
    public static function normalizar(mixed $item): ?string
    {
        if (! is_string($item) && ! is_int($item)) {
            return null;
        }

        $limpo = trim((string) $item);

        if (! str_contains($limpo, '.')) {
            return preg_match('/^\d{4}$/', $limpo) === 1 ? $limpo : null;
        }

        return preg_match('/^(\d{1,2})\.(\d{2})$/', $limpo, $partes) === 1
            ? str_pad($partes[1], 2, '0', STR_PAD_LEFT).$partes[2]
            : null;
    }

    /**
     * O subitem como a lista o escreve. Nao se chama `item()`: metodo publico
     * sem argumento num model e lido pelo Eloquent como relacao, entao
     * `$item->item` estouraria com "must return a relationship instance" em vez
     * de devolver "01.07". O nome esta ocupado de verdade uma classe adiante,
     * onde `CodigoDeTributacaoNacional::item()` e uma relacao mesmo.
     */
    public function pontuado(): string
    {
        return self::pontuar($this->codigo);
    }
}

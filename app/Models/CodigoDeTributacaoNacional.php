<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CodigoDeTributacaoNacionalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um codigo do `cTribNac`, o codigo de tributacao nacional que a DPS declara em
 * `serv.cServ`. Os quatro primeiros digitos sao o subitem da LC 116; os dois
 * ultimos, o desdobramento que o Padrao Nacional deu a ele.
 *
 * Tabela de leitura: quem a preenche e a importacao.
 *
 * @property int $id
 * @property string $codigo seis dígitos com zero à esquerda
 * @property string $item_lista_servico quatro dígitos sem ponto
 * @property string $descricao
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ItemDaListaDeServicos|null $item
 */
#[Fillable(['codigo', 'item_lista_servico', 'descricao'])]
class CodigoDeTributacaoNacional extends Model
{
    /** @use HasFactory<CodigoDeTributacaoNacionalFactory> */
    use HasFactory;

    protected $table = 'codigos_de_tributacao_nacional';

    /**
     * Nem todo codigo tem item: o 9901 ("serviços sem a incidência de ISSQN e
     * ICMS") existe so na tabela nacional, e nao na lista da LC 116.
     *
     * @return BelongsTo<ItemDaListaDeServicos, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemDaListaDeServicos::class, 'item_lista_servico', 'codigo');
    }

    /**
     * Os seis digitos do cTribNac, ou `null`. Aceita inteiro porque chave
     * numerica de array PHP vira inteiro e o formulario devolve "140101" assim;
     * "010701" nao passa por isso, porque zero a esquerda nao e chave canonica e
     * o valor continua texto. Seis digitos ja chegam completos, entao nao ha o
     * que completar.
     *
     * Completar era pior que inutil. "10701", que o campo de texto livre antigo
     * deixava gravar, virava "010701" na leitura: a tela mostrava um codigo
     * valido, a nota gravava sem erro e a DPS saia com os cinco digitos
     * originais, que nao existem em tabela nenhuma. Recusar aqui e o que faz o
     * defeito aparecer onde ainda da para corrigi-lo.
     */
    public static function normalizar(mixed $codigo): ?string
    {
        if (! is_string($codigo) && ! is_int($codigo)) {
            return null;
        }

        $limpo = trim((string) $codigo);

        return preg_match('/^\d{6}$/', $limpo) === 1 ? $limpo : null;
    }

    public function itemPontuado(): string
    {
        return ItemDaListaDeServicos::pontuar($this->item_lista_servico);
    }
}

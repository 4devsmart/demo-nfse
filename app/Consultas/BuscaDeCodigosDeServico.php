<?php

declare(strict_types=1);

namespace App\Consultas;

use App\Models\CodigoDeTributacaoNacional;
use App\Models\ItemDaListaDeServicos;
use App\Models\ItemDaNbs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * As duas listas do bloco "Serviço" da nota: o `cTribNac`, que vai na DPS, e o
 * subitem da LC 116, que so os provedores ABRASF leem.
 *
 * Sao 335 codigos e 200 subitens: cabe abrir a lista inteira, e a busca por
 * texto e feita no banco para alcancar a descricao completa, que no rotulo vem
 * cortada.
 */
final readonly class BuscaDeCodigosDeServico
{
    private const LIMITE = 50;

    private const CORTE_DO_ROTULO = 90;

    /**
     * A revisao mostra o codigo numa grade de colunas de 9rem, e ali o corte do
     * seletor renderia seis linhas numa celula. Quarenta caracteres cabem em
     * duas e dizem qual e o servico, que e o que a conferencia precisa.
     */
    private const CORTE_NA_REVISAO = 40;

    /**
     * As chaves saem daqui como o PHP as guarda: "140101" vira inteiro num
     * indice de array, "010701" nao. E dai que o formulario devolve ora um, ora
     * outro, e por isso a leitura passa por `CodigoDeTributacaoNacional::normalizar`.
     *
     * @return array<int|string, string>
     */
    public function codigosParaSelecao(mixed $jaGravado = null): array
    {
        $lista = $this->comoRotulos(CodigoDeTributacaoNacional::query()->orderBy('codigo'));
        $rotulo = $this->rotuloDoValorAntigo($jaGravado, $jaGravado);

        return $this->comOValorAntigo($lista, $jaGravado, $rotulo);
    }

    /**
     * @return array<int|string, string>
     */
    public function procurarCodigos(string $termo): array
    {
        $busca = $this->termo($termo);

        if ($busca === '') {
            return [];
        }

        $prefixo = $this->prefixoDoSubitem($busca);

        return $this->comoRotulos(
            CodigoDeTributacaoNacional::query()
                ->where(function ($consulta) use ($busca, $prefixo): void {
                    $consulta->where('descricao', 'like', "%{$busca}%");

                    if ($prefixo !== '') {
                        $consulta->orWhere('codigo', 'like', "{$prefixo}%")
                            ->orWhere('item_lista_servico', 'like', "{$prefixo}%");
                    }
                })
                ->orderBy('codigo')
                ->limit(self::LIMITE)
        );
    }

    public function rotuloDeCodigo(mixed $codigo, mixed $jaGravado = null): ?string
    {
        $encontrado = $this->codigo($codigo);

        return $encontrado instanceof CodigoDeTributacaoNacional
            ? $this->rotulo($encontrado->codigo, $encontrado->descricao)
            : $this->rotuloDoValorAntigo($codigo, $jaGravado);
    }

    /**
     * O codigo com a descricao curta, para o cartao da revisao. Ali os vizinhos,
     * indicador da operacao e classificacao, ja aparecem como "codigo ·
     * descricao"; o codigo do servico aparecia como numero seco, e era o campo
     * mais importante dos tres na ultima tela antes de emitir.
     */
    public function rotuloCurtoDeCodigo(mixed $codigo): ?string
    {
        $encontrado = $this->codigo($codigo);

        return $encontrado instanceof CodigoDeTributacaoNacional
            ? "{$encontrado->codigo} · ".Str::limit($encontrado->descricao, self::CORTE_NA_REVISAO)
            : null;
    }

    /**
     * A descricao inteira do codigo escolhido, para aparecer embaixo do seletor:
     * o rotulo vem cortado, e e justamente a cauda que diz o que o codigo exclui.
     */
    public function descricaoDoCodigo(mixed $codigo): ?string
    {
        return $this->codigo($codigo)?->descricao;
    }

    /**
     * O subitem da LC 116 a que o codigo pertence, ja pontuado ("01.07"). E a
     * ligacao entre os dois campos: o `cTribNac` carrega o item dentro dele, e a
     * tela nao tem por que fazer o usuario digitar de novo o que ja escolheu.
     *
     * Nem todo codigo tem subitem, e o formato nao basta para saber: o 990101,
     * "serviços sem a incidência de ISSQN e ICMS", carrega 9901, que nao existe
     * na lista da lei. Preencher pelo formato punha "99.01" num campo que so
     * aceita subitem, e esse item chegaria assim a provedor ABRASF.
     */
    public function itemDoCodigo(mixed $codigo): ?string
    {
        $encontrado = $this->codigo($codigo);

        return $encontrado?->item instanceof ItemDaListaDeServicos
            ? $encontrado->itemPontuado()
            : null;
    }

    /**
     * O item que um codigo implica, para a tela saber se o que esta no campo foi
     * ela quem pos. Codigo antigo e o proprio subitem, entao ele implica a si
     * mesmo pontuado: sem isso, trocar um codigo antigo por um valido deixava
     * para tras o item do anterior, e os dois campos passavam a descrever
     * servicos diferentes sem nada na tela dizendo isso.
     */
    public function itemImplicadoPor(mixed $codigo): ?string
    {
        $derivado = $this->itemDoCodigo($codigo);

        if ($derivado !== null) {
            return $derivado;
        }

        $subitem = ItemDaListaDeServicos::normalizar($codigo);

        return $subitem !== null && ItemDaListaDeServicos::query()->where('codigo', $subitem)->exists()
            ? ItemDaListaDeServicos::pontuar($subitem)
            : null;
    }

    /**
     * @return array<int|string, string>
     */
    public function itensParaSelecao(mixed $jaGravado = null): array
    {
        $lista = $this->comoRotulosPontuados(ItemDaListaDeServicos::query()->orderBy('codigo'));
        $rotulo = $this->rotuloDoItemAntigo($jaGravado, $jaGravado);

        return $this->comOValorAntigo($lista, $jaGravado, $rotulo);
    }

    /**
     * @return array<string, string>
     */
    public function procurarItens(string $termo): array
    {
        $busca = $this->termo($termo);

        if ($busca === '') {
            return [];
        }

        $prefixo = $this->prefixoDoSubitem($busca);

        return $this->comoRotulosPontuados(
            ItemDaListaDeServicos::query()
                ->where(function ($consulta) use ($busca, $prefixo): void {
                    $consulta->where('descricao', 'like', "%{$busca}%");

                    if ($prefixo !== '') {
                        $consulta->orWhere('codigo', 'like', "{$prefixo}%");
                    }
                })
                ->orderBy('codigo')
                ->limit(self::LIMITE)
        );
    }

    public function rotuloDeItem(mixed $item, mixed $jaGravado = null): ?string
    {
        $codigo = ItemDaListaDeServicos::normalizar($item);

        $encontrado = $codigo === null
            ? null
            : ItemDaListaDeServicos::query()->where('codigo', $codigo)->first();

        return $encontrado instanceof ItemDaListaDeServicos
            ? $this->rotulo($encontrado->pontuado(), $encontrado->descricao)
            : $this->rotuloDoItemAntigo($item, $jaGravado);
    }

    /**
     * Os itens da NBS que descrevem o subitem do codigo escolhido.
     *
     * Filtrar pelo subitem e o que torna o campo respondivel: sao 676 itens de
     * NBS na tabela, e o servico da nota costuma ter menos de cinco. Sem codigo
     * escolhido nao ha o que oferecer, e a lista vem vazia de proposito.
     *
     * @return array<string, string>
     */
    public function nbsParaSelecao(mixed $codigoDoServico): array
    {
        $subitem = $this->subitemDoCodigo($codigoDoServico);

        if ($subitem === null) {
            return [];
        }

        return ItemDaNbs::query()
            ->where('item_lista_servico', $subitem)
            ->orderBy('nbs')
            ->toBase()
            ->pluck('descricao', 'nbs')
            ->map(fn (string $descricao, string $nbs): string => $this->rotulo($nbs, $descricao))
            ->all();
    }

    /**
     * O item da NBS quando ele e o unico do subitem, e `null` quando ha escolha
     * a fazer. Dos 200 subitens, 82 tem um so; nos outros o maximo chega a
     * setenta e cinco, e apontar um seria decidir conteudo fiscal no lugar de
     * quem emite.
     */
    public function nbsUnicaDoCodigo(mixed $codigoDoServico): ?string
    {
        $opcoes = array_keys($this->nbsParaSelecao($codigoDoServico));

        return count($opcoes) === 1 ? $opcoes[0] : null;
    }

    public function rotuloDaNbs(mixed $nbs): ?string
    {
        if (! is_string($nbs) || trim($nbs) === '') {
            return null;
        }

        $encontrado = ItemDaNbs::query()->where('nbs', trim($nbs))->first();

        return $encontrado instanceof ItemDaNbs
            ? $this->rotulo($encontrado->nbs, $encontrado->descricao)
            : null;
    }

    private function subitemDoCodigo(mixed $codigoDoServico): ?string
    {
        $codigo = $this->codigo($codigoDoServico);

        if ($codigo instanceof CodigoDeTributacaoNacional) {
            return $codigo->item_lista_servico;
        }

        // Codigo antigo gravado como subitem ainda responde: e o formato que o
        // campo tinha antes da tabela existir.
        return ItemDaListaDeServicos::normalizar($codigoDoServico);
    }

    public function totalDeCodigos(): int
    {
        return CodigoDeTributacaoNacional::query()->count();
    }

    public function totalDeItens(): int
    {
        return ItemDaListaDeServicos::query()->count();
    }

    /**
     * O valor chega como o formulario o devolveu, e nao como a coluna o guarda:
     * `140101` volta inteiro do Livewire, e `010701` voltaria sem o zero. E aqui
     * que ele vira codigo de novo.
     */
    private function codigo(mixed $codigo): ?CodigoDeTributacaoNacional
    {
        $normalizado = CodigoDeTributacaoNacional::normalizar($codigo);

        if ($normalizado === null) {
            return null;
        }

        return CodigoDeTributacaoNacional::query()->where('codigo', $normalizado)->first();
    }

    /**
     * O que sobra do que foi digitado para virar `LIKE`.
     *
     * `%` e `_` sao curingas do SQL, e ninguem os digita querendo isso: sem
     * tira-los, um `%` sozinho devolve a lista inteira como se fosse resultado
     * de busca. Nenhuma das descricoes das duas tabelas tem qualquer um dos
     * dois, entao remove-los nao perde texto de ninguem.
     *
     * O minusculo nao e enfeite. O `LIKE` do SQLite so dobra caixa em ASCII,
     * entao "A" acha "a" mas "Á" nao acha "á": quem digitava "ANÁLISE", ou tinha
     * o teclado do celular capitalizando por conta propria, recebia lista vazia
     * para uma palavra que esta em dez descricoes. Baixar a caixa aqui, com
     * `mb_strtolower`, resolve porque o texto guardado e sentenca: maiuscula so
     * na primeira letra, que e justamente a que o ASCII ja dobra.
     */
    private function termo(string $digitado): string
    {
        return mb_strtolower(trim(str_replace(['%', '_'], '', $digitado)));
    }

    /**
     * O prefixo do subitem por tras do que foi digitado. Sao tres grafias para a
     * mesma coisa: a LC 116 escreve "1.07", a tela mostra "01.07" e a coluna
     * guarda "0107".
     *
     * O zero so e acrescentado quando ha ponto, porque so ai se sabe onde o item
     * termina. Sem ponto o que foi digitado ja e prefixo e completar destruiria a
     * busca: "01" viraria "0100" e deixaria de casar com o item 01.07.
     */
    public function prefixoDoSubitem(string $termo): string
    {
        if (! str_contains($termo, '.')) {
            return (string) preg_replace('/\D/', '', $termo);
        }

        [$item, $subitem] = array_pad(explode('.', $termo, 2), 2, '');

        return str_pad((string) preg_replace('/\D/', '', $item), 2, '0', STR_PAD_LEFT)
            .(string) preg_replace('/\D/', '', $subitem);
    }

    /**
     * Sao 335 codigos e 200 subitens, e o formulario remonta as duas listas a
     * cada redesenho. Hidratar model para isso custava 44 ms por vez; `toBase`
     * devolve as duas colunas cruas, que e tudo que um rotulo de `<select>` usa,
     * e o mesmo trabalho cai para 2 ms.
     *
     * @param  Builder<CodigoDeTributacaoNacional>  $consulta
     * @return array<int|string, string>
     */
    private function comoRotulos(Builder $consulta): array
    {
        return $consulta->toBase()
            ->pluck('descricao', 'codigo')
            ->map(fn (string $descricao, int|string $codigo): string => $this->rotulo((string) $codigo, $descricao))
            ->all();
    }

    /**
     * @param  Builder<ItemDaListaDeServicos>  $consulta
     * @return array<string, string>
     */
    private function comoRotulosPontuados(Builder $consulta): array
    {
        return $consulta->toBase()
            ->pluck('descricao', 'codigo')
            ->mapWithKeys(function (string $descricao, int|string $codigo): array {
                $item = ItemDaListaDeServicos::pontuar(str_pad((string) $codigo, 4, '0', STR_PAD_LEFT));

                return [$item => $this->rotulo($item, $descricao)];
            })
            ->all();
    }

    /**
     * O valor que o registro ja guarda entra na lista quando nao esta nela, com
     * o mesmo rotulo que responde por ele em `getOptionLabelUsing`. Sao os dois
     * lados da mesma coisa: e o rotulo que o Filament usa para decidir se a
     * escolha vale, e a lista que a mostra aberta.
     *
     * @param  array<int|string, string>  $lista
     * @return array<int|string, string>
     */
    private function comOValorAntigo(array $lista, mixed $jaGravado, ?string $rotulo): array
    {
        if ($rotulo === null || (! is_string($jaGravado) && ! is_int($jaGravado)) || array_key_exists($jaGravado, $lista)) {
            return $lista;
        }

        return [$jaGravado => $rotulo] + $lista;
    }

    /**
     * O rotulo de um codigo gravado antes de a tabela existir. Ate esta mudanca
     * o campo era texto livre, e o que se escrevia nele era o subitem da LC 116.
     *
     * Sem rotulo, o seletor abre em branco e a validacao que o proprio Filament
     * monta recusa a gravacao do registro inteiro, inclusive dos campos que
     * ninguem tocou: nao da para corrigir a razao social de um emitente por
     * causa do codigo do servico.
     *
     * Duas amarras, e as duas custaram caro. So vale para o valor que o registro
     * ja guarda, porque o Filament abandona a regra `in:` assim que este metodo
     * devolve rotulo, e sem a comparacao dava para gravar nota nova com um
     * subitem no lugar do cTribNac. E so vale para subitem de verdade, porque
     * enquanto bastava "parecer subitem" dava para gravar `cServ` "9999".
     */
    private function rotuloDoValorAntigo(mixed $valor, mixed $jaGravado): ?string
    {
        if (! $this->ehOValorGravado($valor, $jaGravado)) {
            return null;
        }

        $subitem = ItemDaListaDeServicos::normalizar($valor);

        $encontrado = $subitem === null
            ? null
            : ItemDaListaDeServicos::query()->where('codigo', $subitem)->first();

        if (! $encontrado instanceof ItemDaListaDeServicos) {
            return null;
        }

        return __(':valor · código antigo (:servico): escolha o desdobramento', [
            'valor' => $valor,
            'servico' => Str::limit($encontrado->descricao, self::CORTE_DO_ROTULO),
        ]);
    }

    /**
     * O rotulo de um item gravado fora do formato. Aqui a exigencia e menor que
     * a do codigo, e de proposito: o item e opcional e so provedor ABRASF o le,
     * entao recusa-lo trava a gravacao do registro inteiro sem nada em troca. O
     * escopo continua sendo o valor que o registro ja guarda.
     */
    private function rotuloDoItemAntigo(mixed $item, mixed $jaGravado): ?string
    {
        if (! $this->ehOValorGravado($item, $jaGravado) || (! is_string($item) && ! is_int($item))) {
            return null;
        }

        $escrito = trim((string) $item);

        if ($escrito === '') {
            return null;
        }

        return (string) __(':valor · fora da lista da LC 116: escolha o subitem', ['valor' => $escrito]);
    }

    /**
     * A saida so existe para o que ja esta no banco, e nunca para o que acabou
     * de ser escolhido na tela. Sem emissor a comparar, como na criacao, nao ha
     * valor gravado e nao ha saida.
     */
    private function ehOValorGravado(mixed $valor, mixed $jaGravado): bool
    {
        return (is_string($jaGravado) || is_int($jaGravado))
            && (string) $valor === (string) $jaGravado;
    }

    /**
     * O rotulo vem cortado porque a descricao chega a passar de oitocentos
     * caracteres. Quem precisa dela inteira le `descricaoDoCodigo`, que e o que
     * aparece embaixo do seletor depois da escolha.
     */
    private function rotulo(string $codigo, string $descricao): string
    {
        return "{$codigo} · ".Str::limit($descricao, self::CORTE_DO_ROTULO);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Dinheiro;
use App\Fiscal\Dps\TotaisAproximados;
use Database\Factories\CargaTributariaAproximadaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um item da LC 116 na TabelaIBPTax, para uma UF. E a carga tributaria
 * aproximada que a Lei da Transparencia (12.741/2012) manda destacar.
 *
 * O percentual e sobre o preco, e nao um imposto a recolher: ele estima quanto
 * de tributo ja esta embutido no que o tomador paga. Por isso nao entra em
 * base, nem em liquido, nem em guia nenhuma.
 *
 * @property int $id
 * @property string $codigo item da LC 116, sem ponto ("0107")
 * @property string $uf
 * @property string $descricao
 * @property string $percentual_federal
 * @property string $percentual_federal_importado
 * @property string $percentual_estadual
 * @property string $percentual_municipal
 * @property Carbon|null $vigencia_inicio
 * @property Carbon|null $vigencia_fim
 * @property string $versao
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'codigo', 'uf', 'descricao',
    'percentual_federal', 'percentual_federal_importado',
    'percentual_estadual', 'percentual_municipal',
    'vigencia_inicio', 'vigencia_fim', 'versao',
])]
class CargaTributariaAproximada extends Model
{
    /** @use HasFactory<CargaTributariaAproximadaFactory> */
    use HasFactory;

    protected $table = 'cargas_tributarias_aproximadas';

    protected function casts(): array
    {
        return [
            'percentual_federal' => 'decimal:4',
            'percentual_federal_importado' => 'decimal:4',
            'percentual_estadual' => 'decimal:4',
            'percentual_municipal' => 'decimal:4',
            'vigencia_inicio' => 'date',
            'vigencia_fim' => 'date',
        ];
    }

    /**
     * O codigo como a tabela do IBPT o escreve: os quatro digitos do subitem da
     * LC 116. A nota guarda o cTribNac, que sao seis ("010701"), e o cadastro
     * antigo guardava o subitem pontuado ("01.07"); os dois chegam aqui e saem
     * como "0107".
     *
     * O corte em quatro e o que junta os dois lados: o desdobramento do Padrao
     * Nacional e mais fino que a tabela do IBPT, que nunca desceu abaixo do
     * subitem.
     */
    public static function normalizarCodigo(string $codigo): string
    {
        return substr(preg_replace('/\D/', '', $codigo) ?? '', 0, 4);
    }

    public function federal(): Aliquota
    {
        return Aliquota::deQuatroCasas($this->percentual_federal);
    }

    public function estadual(): Aliquota
    {
        return Aliquota::deQuatroCasas($this->percentual_estadual);
    }

    public function municipal(): Aliquota
    {
        return Aliquota::deQuatroCasas($this->percentual_municipal);
    }

    /**
     * Os tres totais sobre um preco. A conta mora aqui, e nao no formulario,
     * para poder ser conferida sem subir tela nenhuma.
     *
     * O municipal aceita o ISSQN da propria operacao no lugar do percentual da
     * tabela, e e assim que deve ser usado numa NFS-e. O art. 5o do Decreto
     * 8.264/2014 diz que o valor "sera apurado sobre cada operacao" e que a
     * tabela semestral de instituicao nacional e alternativa "a criterio das
     * empresas vendedoras". Ou seja, a tabela e o atalho de quem nao sabe o
     * numero; numa nota de servico o ISS e sabido, e ele e a apuracao.
     *
     * A diferenca nao e pequena: o percentual municipal da tabela e a media do
     * item entre os municipios do estado, e vai de 2% a 5% conforme a UF. Um
     * prestador na capital do Rio deve 5%, e a media do estado e 2,95%.
     *
     * O ISS zerado de operacao imune, isenta ou de nao incidencia entra zerado
     * aqui, e isso satisfaz o parag. 1o do art. 3o do mesmo decreto, que manda
     * nao computar o que foi eximido. Com o percentual da tabela, uma operacao
     * imune declararia carga municipal que nao existe.
     */
    public function totaisSobre(Dinheiro $preco, ?Dinheiro $issqnDaOperacao = null): TotaisAproximados
    {
        return new TotaisAproximados(
            federais: $preco->multiplicarPor($this->federal()),
            estaduais: $preco->multiplicarPor($this->estadual()),
            municipais: $issqnDaOperacao ?? $preco->multiplicarPor($this->municipal()),
        );
    }

    /**
     * O IBPT publica tabela nova a cada poucos meses, e a antiga vence. Emitir
     * com percentual vencido nao invalida a nota, mas descumpre a lei que
     * mandou destaca-lo.
     */
    public function estaVencida(): bool
    {
        return $this->vigencia_fim !== null && $this->vigencia_fim->isBefore(now()->startOfDay());
    }
}

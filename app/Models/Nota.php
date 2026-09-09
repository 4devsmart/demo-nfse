<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RetencaoIssqn;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\Enums\StatusNota;
use App\Domain\Enums\TipoPessoa;
use App\Domain\Enums\TipoSuspensaoDeExigibilidade;
use App\Domain\Enums\TributacaoIssqn;
use App\Domain\Notas\ImpedimentosDaNota;
use App\Domain\ValueObjects\Aliquota;
use App\Domain\ValueObjects\Competencia;
use App\Domain\ValueObjects\Dinheiro;
use App\Fiscal\Dps\BeneficioMunicipal;
use App\Fiscal\Dps\ExigibilidadeSuspensa;
use App\Fiscal\Dps\RetencoesFederais;
use App\Fiscal\Dps\ServicoPrestado;
use App\Fiscal\Dps\TotaisAproximados;
use App\Fiscal\Dps\TributacaoIbsCbs;
use App\Fiscal\Dps\ValoresDoServico;
use App\Fiscal\Respostas\Mensagens;
use Database\Factories\NotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A NFS-e deste sistema: é ela que guarda a numeração, o `id_dps` e o XML
 * protocolado. A API fiscal não guarda nada, nem o XML, nem o certificado.
 *
 * Os campos monetários são `decimal`, e o cast do Laravel devolve string. Para
 * fazer conta com eles, use os métodos que devolvem `Dinheiro`.
 *
 * @property int $id
 * @property int $empresa_id
 * @property int $cliente_id
 * @property int|null $substitui_nota_id a nota que esta substitui
 * @property int $cidade_prestacao_id
 * @property string $referencia id externo, nosso; vai como `referencia` na API
 * @property string $serie
 * @property int $numero o número da DPS, controlado por este sistema
 * @property Carbon $competencia
 * @property Ambiente $ambiente
 * @property StatusNota $status
 * @property string $descricao_servico
 * @property string $codigo_servico
 * @property string|null $cnae
 * @property string|null $item_lista_servico
 * @property string|null $nbs
 * @property string $valor_servico
 * @property string $aliquota_iss
 * @property string $deducoes
 * @property string $desconto_incondicionado
 * @property string $desconto_condicionado
 * @property TributacaoIssqn $tributacao_issqn
 * @property RetencaoIssqn $retencao_issqn
 * @property SituacaoTributariaPisCofins|null $cst_pis_cofins
 * @property string $aliquota_pis
 * @property string $aliquota_cofins
 * @property string $aliquota_csll
 * @property string $aliquota_irrf
 * @property string $aliquota_previdenciaria
 * @property bool $retem_pis
 * @property bool $retem_cofins
 * @property bool $retem_csll
 * @property string|null $cst_ibs_cbs CST do IBS/CBS, três dígitos
 * @property string|null $indicador_de_operacao `cIndOp`, seis dígitos
 * @property string|null $classificacao_tributaria `cClassTrib`, seis dígitos
 * @property string|null $codigo_credito_presumido
 * @property TipoSuspensaoDeExigibilidade|null $tipo_suspensao
 * @property string|null $numero_processo_suspensao
 * @property string|null $numero_beneficio_municipal
 * @property string|null $percentual_reducao_base
 * @property string|null $total_tributos_federais
 * @property string|null $total_tributos_estaduais
 * @property string|null $total_tributos_municipais
 * @property string|null $percentual_simples_nacional alíquota efetiva, `pTotTribSN`
 * @property string|null $id_dps determinístico; recupera transmissão perdida
 * @property string|null $xml_dps a DPS montada, em base64
 * @property string|null $provedor
 * @property string|null $numero_nfse atribuído pelo provedor, não por nós
 * @property string|null $chave
 * @property string|null $codigo_verificacao
 * @property string|null $protocolo
 * @property string|null $xml_autorizado em base64. Não há segunda via
 * @property string|null $xml_evento cancelamento ou substituição, em base64; vem da fila DF-e
 * @property array<int, array{codigo: string, descricao: string}>|null $mensagens
 * @property Carbon|null $transmitida_em
 * @property Carbon|null $cancelada_em
 * @property string|null $motivo_cancelamento
 * @property-read Empresa $empresa
 * @property-read Cliente $cliente
 * @property-read Cidade $cidadePrestacao
 * @property-read Nota|null $substituida
 * @property-read Nota|null $substituta
 */
#[Fillable([
    'empresa_id', 'cliente_id', 'cidade_prestacao_id', 'substitui_nota_id',
    'referencia', 'serie', 'numero', 'competencia', 'ambiente', 'status',
    'descricao_servico', 'codigo_servico', 'cnae', 'item_lista_servico', 'nbs',
    'valor_servico', 'aliquota_iss', 'deducoes',
    'desconto_incondicionado', 'desconto_condicionado',
    'tributacao_issqn', 'retencao_issqn',
    'cst_pis_cofins', 'aliquota_pis', 'aliquota_cofins',
    'aliquota_csll', 'aliquota_irrf', 'aliquota_previdenciaria',
    'retem_pis', 'retem_cofins', 'retem_csll',
    'cst_ibs_cbs', 'indicador_de_operacao', 'classificacao_tributaria', 'codigo_credito_presumido',
    'tipo_suspensao', 'numero_processo_suspensao',
    'numero_beneficio_municipal', 'percentual_reducao_base',
    'total_tributos_federais', 'total_tributos_estaduais', 'total_tributos_municipais',
    'percentual_simples_nacional',
])]
class Nota extends Model
{
    /** @use HasFactory<NotaFactory> */
    use HasFactory;

    protected $table = 'notas';

    protected function casts(): array
    {
        return [
            'status' => StatusNota::class,
            'ambiente' => Ambiente::class,
            'tributacao_issqn' => TributacaoIssqn::class,
            'retencao_issqn' => RetencaoIssqn::class,
            'cst_pis_cofins' => SituacaoTributariaPisCofins::class,
            'aliquota_pis' => 'decimal:4',
            'aliquota_cofins' => 'decimal:4',
            'aliquota_csll' => 'decimal:4',
            'aliquota_irrf' => 'decimal:4',
            'aliquota_previdenciaria' => 'decimal:4',
            'retem_pis' => 'boolean',
            'retem_cofins' => 'boolean',
            'retem_csll' => 'boolean',
            'tipo_suspensao' => TipoSuspensaoDeExigibilidade::class,
            'percentual_reducao_base' => 'decimal:4',
            'total_tributos_federais' => 'decimal:2',
            'total_tributos_estaduais' => 'decimal:2',
            'total_tributos_municipais' => 'decimal:2',
            'percentual_simples_nacional' => 'decimal:4',
            'competencia' => 'date',
            'valor_servico' => 'decimal:2',
            'aliquota_iss' => 'decimal:4',
            'deducoes' => 'decimal:2',
            'desconto_incondicionado' => 'decimal:2',
            'desconto_condicionado' => 'decimal:2',
            'mensagens' => 'array',
            'transmitida_em' => 'datetime',
            'cancelada_em' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * A nota que esta substitui. A relacao inversa se le por `substituta`.
     */
    public function substituida(): BelongsTo
    {
        return $this->belongsTo(self::class, 'substitui_nota_id');
    }

    public function substituta(): HasOne
    {
        return $this->hasOne(self::class, 'substitui_nota_id');
    }

    public function cidadePrestacao(): BelongsTo
    {
        return $this->belongsTo(Cidade::class, 'cidade_prestacao_id');
    }

    public function competenciaDoServico(): Competencia
    {
        return Competencia::noMesDe($this->competencia);
    }

    public function valorDoServico(): Dinheiro
    {
        return Dinheiro::deReais($this->valor_servico);
    }

    public function aliquotaDoIss(): Aliquota
    {
        return Aliquota::deQuatroCasas($this->aliquota_iss);
    }

    public function servicoPrestado(): ServicoPrestado
    {
        return ServicoPrestado::prestadoEm($this->cidadePrestacao->codigo(), $this->codigo_servico, $this->descricao_servico)
            ->comCnae((string) $this->cnae)
            ->comItemDaListaDeServico((string) $this->item_lista_servico)
            ->comNbs((string) $this->nbs);
    }

    public function valoresDoServico(): ValoresDoServico
    {
        return ValoresDoServico::cobrados($this->valorDoServico(), $this->aliquotaDoIss())
            ->tributadosComo($this->tributacao_issqn, $this->retencao_issqn)
            ->comDeducoes(Dinheiro::deReais($this->deducoes))
            ->comDescontos(
                Dinheiro::deReais($this->desconto_incondicionado),
                Dinheiro::deReais($this->desconto_condicionado),
            )
            ->comExigibilidadeSuspensa($this->exigibilidadeSuspensa())
            ->comBeneficioMunicipal($this->beneficioMunicipal())
            ->comTotaisAproximados($this->totaisAproximados())
            ->comRetencoesFederais($this->retencoesFederais())
            ->comIssqnNoSimplesNacional($this->empresa->apuraIssqnPeloSimplesNacional());
    }

    /**
     * `valores.trib.tribFed`, ou `null` quando nao ha nada a declarar.
     *
     * Duas bases, e a razao esta em `RetencoesFederais::sobre`: a CSRF e o IRRF
     * incidem sobre o montante a ser pago, que ja desconta o incondicionado; a
     * previdenciaria, sobre o valor bruto da nota.
     */
    public function retencoesFederais(): ?RetencoesFederais
    {
        $retencoes = RetencoesFederais::sobre(
            $this->valorDoServico()->subtrair(Dinheiro::deReais($this->desconto_incondicionado)),
            $this->valorDoServico(),
        )
            ->tributadasComo($this->cst_pis_cofins ?? SituacaoTributariaPisCofins::TributavelAliquotaBasica)
            ->comPisCofins($this->aliquotaDe('aliquota_pis'), $this->aliquotaDe('aliquota_cofins'))
            ->comCsll($this->aliquotaDe('aliquota_csll'))
            ->comIrrf($this->aliquotaDe('aliquota_irrf'))
            ->comPrevidenciaria($this->aliquotaDe('aliquota_previdenciaria'))
            ->retidasPeloTomador(
                (bool) $this->retem_pis,
                (bool) $this->retem_cofins,
                (bool) $this->retem_csll,
            );

        return $retencoes->estaZerado() ? null : $retencoes;
    }

    /**
     * As colunas de retenção nasceram `NOT NULL` com padrão zero, mas o padrão
     * é do banco: um model montado sem elas, como o de uma factory ou de um
     * `make()`, tem `null` no atributo. Ler zero aqui evita repetir `?? 0` em
     * cinco chamadas e em toda linha que venha a usá-las.
     */
    private function aliquotaDe(string $coluna): Aliquota
    {
        $percentual = $this->getAttribute($coluna);

        return Aliquota::deQuatroCasas(is_numeric($percentual) ? $percentual : 0);
    }

    /**
     * `infDPS.ibscbs`. Enquanto o grupo nao e obrigatorio, nota sem
     * classificacao escolhida sai sem ele.
     *
     * O `indFinal` sai do tipo do tomador: pessoa fisica nao se credita do IBS
     * nem da CBS na etapa seguinte, entao e consumidor final; pessoa juridica,
     * no caso comum, se credita. E aproximacao, e nao regra: PJ nao
     * contribuinte tambem e consumidor final, e isso este cadastro nao sabe.
     * Aproximacao declarada ganha do padrao da wrapper, que assume `1` para
     * todo mundo quando o campo nao vem.
     */
    public function tributacaoIbsCbs(): ?TributacaoIbsCbs
    {
        $ibsCbs = TributacaoIbsCbs::classificadaComo((string) $this->cst_ibs_cbs, (string) $this->classificacao_tributaria)
            ->naOperacao($this->indicador_de_operacao)
            ->paraConsumidorFinal($this->cliente->tipo_pessoa === TipoPessoa::Fisica)
            ->comCreditoPresumido($this->codigo_credito_presumido);

        return $ibsCbs->estaZerado() ? null : $ibsCbs;
    }

    public function exigibilidadeSuspensa(): ?ExigibilidadeSuspensa
    {
        if ($this->tipo_suspensao === null) {
            return null;
        }

        return ExigibilidadeSuspensa::por($this->tipo_suspensao, (string) $this->numero_processo_suspensao);
    }

    public function beneficioMunicipal(): ?BeneficioMunicipal
    {
        if (blank($this->numero_beneficio_municipal)) {
            return null;
        }

        return BeneficioMunicipal::concedido(
            (string) $this->numero_beneficio_municipal,
            Aliquota::deQuatroCasas($this->percentual_reducao_base ?? 0),
        );
    }

    public function totaisAproximados(): ?TotaisAproximados
    {
        $totais = $this->declaraPeloSimplesNacional()
            ? TotaisAproximados::doSimplesNacional(Aliquota::deQuatroCasas($this->percentual_simples_nacional ?? 0))
            : new TotaisAproximados(
                federais: Dinheiro::deReais($this->total_tributos_federais ?? 0),
                estaduais: Dinheiro::deReais($this->total_tributos_estaduais ?? 0),
                municipais: Dinheiro::deReais($this->total_tributos_municipais ?? 0),
            );

        return $totais->estaZerado() ? null : $totais;
    }

    /**
     * Declara pelo Simples quem é optante E informou a alíquota efetiva.
     *
     * As duas condições juntas, e não só o regime: optante que deixou o campo
     * em branco não tem nada a declarar, e cair no `pTotTribSN` zerado seria
     * afirmar que não há tributo embutido no preço. Sem a alíquota, os três
     * valores continuam valendo, se houver algum.
     */
    private function declaraPeloSimplesNacional(): bool
    {
        return $this->empresa->optaPeloSimplesNacional()
            && (float) ($this->percentual_simples_nacional ?? 0) > 0;
    }

    /**
     * O que impede cada operação, e por quê. Uma frase só, dita num lugar só:
     * a tela usa para desabilitar o botão, o caso de uso usa para recusar.
     */
    public function impedimentos(): ImpedimentosDaNota
    {
        return new ImpedimentosDaNota($this);
    }

    /**
     * A frase que a tela mostra em destaque. Quando o próximo passo está
     * bloqueado, o que interessa é o bloqueio, não o passo.
     */
    public function orientacao(): string
    {
        if (! $this->status->permiteTransmitir()) {
            return $this->status->proximoPasso();
        }

        return $this->impedimentos()->paraEmitir() ?? $this->status->proximoPasso();
    }

    /**
     * O que o provedor devolveu, tipado. Sem isto cada tela reabria o array cru
     * e formatava `codigo` + `descricao` do seu jeito.
     */
    public function mensagensDoProvedor(): Mensagens
    {
        return Mensagens::daLista($this->mensagens ?? []);
    }

    public function identificacao(): string
    {
        return $this->numero_nfse !== null
            ? "NFS-e {$this->numero_nfse}"
            : "DPS {$this->serie}/{$this->numero}";
    }

    public function temDpsMontada(): bool
    {
        return filled($this->xml_dps);
    }

    public function temXmlAutorizado(): bool
    {
        return filled($this->xml_autorizado);
    }

    public function temXmlDoEvento(): bool
    {
        return filled($this->xml_evento);
    }
}

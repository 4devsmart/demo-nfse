<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RegimeDeApuracaoDoSimples;
use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\ValueObjects\CodigoIbge;
use App\Domain\ValueObjects\DocumentoFederal;
use App\Fiscal\Certificado\CertificadoDigital;
use App\Fiscal\Dps\Endereco;
use App\Fiscal\Dps\Pessoa;
use App\Fiscal\Dps\RegimeTributario;
use App\Fiscal\Excecoes\CertificadoInvalido;
use App\Fiscal\Pedidos\CredenciaisDaPrefeitura;
use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * O emitente da NFS-e: quem presta o serviço e assina com o certificado A1.
 *
 * @property int $id
 * @property string $razao_social
 * @property string|null $nome_fantasia
 * @property string $cnpj só dígitos
 * @property string|null $inscricao_municipal
 * @property int $cidade_id
 * @property string $cep
 * @property string $logradouro
 * @property string $numero
 * @property string|null $complemento
 * @property string $bairro
 * @property string|null $telefone
 * @property string|null $email
 * @property RegimeSimplesNacional $regime_simples_nacional
 * @property RegimeDeApuracaoDoSimples|null $regime_apuracao_simples
 * @property RegimeEspecialTributacao $regime_especial
 * @property Ambiente $ambiente
 * @property string $serie_dps
 * @property int $proximo_numero_dps o próximo a sair; a reserva o incrementa
 * @property string|null $codigo_servico_padrao
 * @property string|null $cnae_padrao
 * @property string|null $item_lista_servico_padrao
 * @property string|null $codigo_tributacao_municipio_padrao `cTribMun`, da tabela do município
 * @property string|null $nbs_padrao
 * @property string $aliquota_iss_padrao decimal:4 devolve string
 * @property SituacaoTributariaPisCofins|null $cst_pis_cofins_padrao
 * @property string $aliquota_pis_padrao decimal:4 devolve string
 * @property string $aliquota_cofins_padrao decimal:4 devolve string
 * @property string $aliquota_csll_padrao decimal:4 devolve string
 * @property string $aliquota_irrf_padrao decimal:4 devolve string
 * @property string $aliquota_previdenciaria_padrao decimal:4 devolve string
 * @property string|null $cst_ibs_cbs_padrao CST do IBS/CBS, três dígitos
 * @property string|null $indicador_de_operacao_padrao `cIndOp`, seis dígitos
 * @property string|null $classificacao_tributaria_padrao `cClassTrib`, seis dígitos
 * @property string|null $certificado_arquivo .pfx em base64, cifrado
 * @property string|null $certificado_senha cifrada
 * @property string|null $certificado_titular
 * @property Carbon|null $certificado_valido_ate
 * @property string|null $prefeitura_usuario cifrado
 * @property string|null $prefeitura_senha cifrada
 * @property string|null $prefeitura_token cifrado
 * @property-read Cidade $cidade
 * @property-read Collection<int, Nota> $notas
 */
#[Fillable([
    'razao_social', 'nome_fantasia', 'cnpj', 'inscricao_municipal',
    'cidade_id', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'telefone', 'email',
    'regime_simples_nacional', 'regime_apuracao_simples', 'regime_especial',
    'ambiente', 'serie_dps', 'proximo_numero_dps',
    'codigo_servico_padrao', 'cnae_padrao', 'item_lista_servico_padrao', 'codigo_tributacao_municipio_padrao',
    'nbs_padrao', 'aliquota_iss_padrao',
    'cst_pis_cofins_padrao', 'aliquota_pis_padrao', 'aliquota_cofins_padrao',
    'aliquota_csll_padrao', 'aliquota_irrf_padrao', 'aliquota_previdenciaria_padrao',
    'cst_ibs_cbs_padrao', 'indicador_de_operacao_padrao', 'classificacao_tributaria_padrao',
    'prefeitura_usuario', 'prefeitura_senha', 'prefeitura_token',
])]
#[Hidden(['certificado_arquivo', 'certificado_senha', 'prefeitura_usuario', 'prefeitura_senha', 'prefeitura_token'])]
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    protected $table = 'empresas';

    protected function casts(): array
    {
        return [
            'ambiente' => Ambiente::class,
            'regime_simples_nacional' => RegimeSimplesNacional::class,
            'regime_apuracao_simples' => RegimeDeApuracaoDoSimples::class,
            'regime_especial' => RegimeEspecialTributacao::class,
            'proximo_numero_dps' => 'integer',
            'aliquota_iss_padrao' => 'decimal:4',
            'cst_pis_cofins_padrao' => SituacaoTributariaPisCofins::class,
            'aliquota_pis_padrao' => 'decimal:4',
            'aliquota_cofins_padrao' => 'decimal:4',
            'aliquota_csll_padrao' => 'decimal:4',
            'aliquota_irrf_padrao' => 'decimal:4',
            'aliquota_previdenciaria_padrao' => 'decimal:4',
            'certificado_arquivo' => 'encrypted',
            'certificado_senha' => 'encrypted',
            'prefeitura_usuario' => 'encrypted',
            'prefeitura_senha' => 'encrypted',
            'prefeitura_token' => 'encrypted',
            'certificado_valido_ate' => 'date',
        ];
    }

    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    public function documentoFederal(): DocumentoFederal
    {
        return DocumentoFederal::deCpfOuCnpj($this->cnpj);
    }

    /**
     * O que impede apagar este emitente, ou `null` quando nada impede.
     *
     * A nota guarda o XML autorizado e o protocolo, e desses não há segunda
     * via: apagar o emitente em cascata levaria os dois junto. A FK recusa,
     * então sem esta frase a tela responderia com página de erro em vez de
     * dizer o motivo.
     */
    public function impedimentoParaApagar(): ?string
    {
        return $this->notas()->exists()
            ? 'Este emitente já tem nota emitida. Apagá-lo levaria junto o XML autorizado e o protocolo, que não têm segunda via.'
            : null;
    }

    public function municipio(): CodigoIbge
    {
        return $this->cidade->codigo();
    }

    public function optaPeloSimplesNacional(): bool
    {
        return $this->regime_simples_nacional->ehOptante();
    }

    /**
     * O ISSQN deste prestador sai na guia unica do Simples, e nao na nota.
     *
     * E o par que a rejeicao E0625 descreve: `opSimpNac` 3 com `regApTribSN` 1.
     * O MEI nao entra porque a biblioteca fiscal descarta o `regApTribSN` dele,
     * e sem essa declaracao nao ha como afirmar por onde o imposto sai.
     */
    public function apuraIssqnPeloSimplesNacional(): bool
    {
        return $this->regime_simples_nacional === RegimeSimplesNacional::OptanteMicroEmpresa
            && $this->regime_apuracao_simples === RegimeDeApuracaoDoSimples::FederaisEMunicipalPeloSimples;
    }

    public function temCertificado(): bool
    {
        return filled($this->certificado_arquivo);
    }

    /**
     * Negativo quando ja venceu. Null quando nao ha certificado ou a validade
     * nao pode ser lida.
     */
    public function diasAteOCertificadoVencer(): ?int
    {
        if ($this->certificado_valido_ate === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->certificado_valido_ate, false);
    }

    /**
     * Login de webservice da prefeitura. Provedores fora do Padrao Nacional o
     * exigem; o Padrao Nacional ignora. Null quando nao ha nada preenchido, para
     * que o grupo nem chegue a sair no corpo da requisicao.
     */
    public function credenciaisDaPrefeitura(): ?CredenciaisDaPrefeitura
    {
        $credenciais = new CredenciaisDaPrefeitura(
            usuario: (string) $this->prefeitura_usuario,
            senha: (string) $this->prefeitura_senha,
            token: (string) $this->prefeitura_token,
        );

        return $credenciais->estaVazia() ? null : $credenciais;
    }

    public function certificado(): CertificadoDigital
    {
        if (! $this->temCertificado()) {
            throw CertificadoInvalido::ausente($this->razao_social);
        }

        return CertificadoDigital::deBase64((string) $this->certificado_arquivo, (string) $this->certificado_senha);
    }

    /**
     * O prestador como a DPS o quer, ja com o regime tributario.
     */
    public function comoPrestador(): Pessoa
    {
        return Pessoa::identificadaPor($this->documentoFederal(), $this->razao_social)
            ->comInscricaoMunicipal((string) $this->inscricao_municipal)
            ->comContato((string) $this->email, (string) $this->telefone)
            ->em($this->endereco())
            ->sobRegime(new RegimeTributario(
                $this->regime_simples_nacional,
                $this->regime_especial,
                $this->regime_apuracao_simples,
            ));
    }

    public function endereco(): Endereco
    {
        return new Endereco(
            municipio: $this->municipio(),
            uf: $this->cidade->uf,
            cep: $this->cep,
            logradouro: $this->logradouro,
            numero: $this->numero,
            bairro: $this->bairro,
            complemento: (string) $this->complemento,
        );
    }
}

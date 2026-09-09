<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'razao_social' => fake()->company(),
            'nome_fantasia' => fake()->companySuffix(),
            'cnpj' => fake('pt_BR')->unique()->cnpj(false),
            'inscricao_municipal' => '1234567',
            'cidade_id' => fn (): int => CidadeFactory::rio()->getKey(),
            'cep' => '20040020',
            'logradouro' => 'Rua da Assembleia',
            'numero' => '10',
            'bairro' => 'Centro',
            'telefone' => '2133334444',
            'email' => 'fiscal@demo.test',
            'regime_simples_nacional' => RegimeSimplesNacional::NaoOptante,
            'regime_apuracao_simples' => null,
            'regime_especial' => RegimeEspecialTributacao::Nenhum,
            'ambiente' => Ambiente::Homologacao,
            'serie_dps' => '1',
            'proximo_numero_dps' => 1,
            'codigo_servico_padrao' => fn (): string => CodigoDeTributacaoNacionalFactory::suporteEmInformatica()->codigo,
            'item_lista_servico_padrao' => fn (): string => ItemDaListaDeServicosFactory::suporteEmInformatica()->pontuado(),
            'cnae_padrao' => '6201501',
            'aliquota_iss_padrao' => 5,
        ];
    }

    /**
     * Login de webservice, exigido por provedores fora do Padrao Nacional.
     */
    public function comCredenciaisDaPrefeitura(): static
    {
        return $this->state(fn (): array => [
            'prefeitura_usuario' => 'usuario-de-teste',
            'prefeitura_senha' => 'senha-de-teste',
        ]);
    }

    /**
     * Um .pfx que nao abre serve para os testes: o certificado so e lido na
     * transmissao, e nos testes quem transmite e um dublê.
     */
    public function comCertificado(): static
    {
        return $this->state(fn (): array => [
            'certificado_arquivo' => base64_encode('pfx-de-teste'),
            'certificado_senha' => 'senha',
            'certificado_titular' => 'DEMO LTDA:19131243000197',
            'certificado_valido_ate' => now()->addYear(),
        ]);
    }
}

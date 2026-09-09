<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\RegimeEspecialTributacao;
use App\Domain\Enums\RegimeSimplesNacional;
use App\Domain\Enums\SituacaoTributariaPisCofins;
use App\Domain\Enums\TipoPessoa;
use App\Models\Cidade;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * O minimo para abrir o painel e emitir: um usuario, um emitente e um tomador.
 * Rio de Janeiro por ser Padrao Nacional, o layout que a API monta por inteiro.
 */
class DemonstracaoSeeder extends Seeder
{
    private const MUNICIPIO_DA_DEMONSTRACAO = '3304557';

    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@nfse.test'],
            ['name' => 'Administrador', 'password' => Hash::make('senha-demo')],
        );

        $cidade = Cidade::query()->where('codigo_ibge', self::MUNICIPIO_DA_DEMONSTRACAO)->first();

        if (! $cidade instanceof Cidade) {
            $this->command?->warn('Rode o CidadeSeeder antes: a demonstração precisa do município.');

            return;
        }

        $this->criarEmitente($cidade);
        $this->criarTomador($cidade);

        $this->command?->info('Acesso: admin@nfse.test / senha-demo');
    }

    private function criarEmitente(Cidade $cidade): void
    {
        Empresa::query()->firstOrCreate(['cnpj' => '19131243000197'], [
            'razao_social' => 'Estúdio de Software Demonstração LTDA',
            'nome_fantasia' => 'Demo Software',
            'inscricao_municipal' => '1234567',
            'cidade_id' => $cidade->getKey(),
            'cep' => '20040020',
            'logradouro' => 'Rua da Assembleia',
            'numero' => '10',
            'bairro' => 'Centro',
            'telefone' => '2133334444',
            'email' => 'fiscal@demo.test',
            'regime_simples_nacional' => RegimeSimplesNacional::NaoOptante,
            'regime_especial' => RegimeEspecialTributacao::Nenhum,
            'ambiente' => Ambiente::Homologacao,
            'serie_dps' => '1',
            'proximo_numero_dps' => 1,
            'codigo_servico_padrao' => '010701',
            'item_lista_servico_padrao' => '01.07',
            'cnae_padrao' => '6201501',
            'aliquota_iss_padrao' => 5,

            // Regime normal: valem os percentuais da IN RFB 459/2004, que
            // somados sao a CSRF de 4,65%, e o IRRF de 1,5% do art. 714 do
            // RIR/2018. A previdenciaria fica zerada porque desenvolvimento de
            // software nao e cessao de mao de obra.
            //
            // Aliquota preenchida nao e retencao: quem diz o que o tomador
            // retem e cada nota, na pergunta "ha retencao de tributos federais".
            'cst_pis_cofins_padrao' => SituacaoTributariaPisCofins::TributavelAliquotaBasica,
            'aliquota_pis_padrao' => 0.65,
            'aliquota_cofins_padrao' => 3,
            'aliquota_csll_padrao' => 1,
            'aliquota_irrf_padrao' => 1.5,
            'aliquota_previdenciaria_padrao' => 0,

            // Reforma Tributaria: tributacao integral, e o servico se considera
            // ocorrido no domicilio do adquirente.
            'cst_ibs_cbs_padrao' => '000',
            'classificacao_tributaria_padrao' => '000001',
            'indicador_de_operacao_padrao' => '100301',
        ]);
    }

    private function criarTomador(Cidade $cidade): void
    {
        Cliente::query()->firstOrCreate(['cpf_cnpj' => '45543915000181'], [
            'tipo_pessoa' => TipoPessoa::Juridica,
            'razao_social' => 'Comércio Exemplo S.A.',
            'inscricao_municipal' => '7654321',
            'cidade_id' => $cidade->getKey(),
            'cep' => '20031170',
            'logradouro' => 'Avenida Rio Branco',
            'numero' => '100',
            'bairro' => 'Centro',
            'telefone' => '2144445555',
            'email' => 'contas@exemplo.test',
        ]);
    }
}

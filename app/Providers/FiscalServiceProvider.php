<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Cadastros\BuscarCadastroPeloCnpj;
use App\Actions\Cargas\ArquivoDeCargas;
use App\Actions\Cargas\FonteDeCargas;
use App\Actions\Cidades\ApiDoIbge;
use App\Actions\Cidades\ArquivoDeMunicipios;
use App\Actions\Cidades\FonteDeCidades;
use App\Actions\Classificacoes\ArquivoDeClassificacoes;
use App\Actions\Classificacoes\FonteDeClassificacoes;
use App\Actions\Classificacoes\PortalDaSvrs;
use App\Actions\Enderecos\BuscarEnderecoPeloCep;
use App\Actions\Indicadores\ArquivoDeIndicadores;
use App\Actions\Indicadores\FonteDeIndicadores;
use App\Actions\Servicos\ArquivoDaListaDeServicos;
use App\Actions\Servicos\ArquivoDaNbs;
use App\Actions\Servicos\ArquivoDeCodigosDeTributacao;
use App\Actions\Servicos\FonteDaListaDeServicos;
use App\Actions\Servicos\FonteDaNbs;
use App\Actions\Servicos\FonteDeCodigosDeTributacao;
use App\Actions\Servicos\ImportarCodigosDeTributacaoNacional;
use App\Actions\Servicos\PortalDaNfseNacional;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Http\WrapperFiscal;
use App\Http\Controllers\DocumentacaoFiscalController;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Config\Repository as Configuracao;
use Illuminate\Http\Client\Factory as ClienteHttp;
use Illuminate\Support\ServiceProvider;

/**
 * As unicas amarracoes do projeto. Quem depende de GatewayFiscal nao sabe se do
 * outro lado ha HTTP ou um dublê de teste.
 */
final class FiscalServiceProvider extends ServiceProvider
{
    /**
     * O estilo proprio do painel. Registrar como asset do Filament e o caminho
     * sem Node: `php artisan filament:assets` publica o arquivo e o painel o
     * carrega junto do CSS dele.
     */
    public function boot(): void
    {
        FilamentAsset::register([
            Css::make('nfse', resource_path('css/nfse.css')),
        ]);
    }

    public function register(): void
    {
        $this->app->singleton(GatewayFiscal::class, function ($app): GatewayFiscal {
            $configuracao = $app->make(Configuracao::class);

            return new WrapperFiscal(
                http: $app->make(ClienteHttp::class),
                urlBase: (string) $configuracao->get('fiscal.url'),
                token: (string) $configuracao->get('fiscal.token'),
                segundosDeTimeout: (int) $configuracao->get('fiscal.timeout'),
            );
        });

        // A carga do dia a dia sai do arquivo local, para funcionar sem rede.
        $this->app->bind(FonteDeCidades::class, ArquivoDeMunicipios::class);

        $this->app->when(ArquivoDeMunicipios::class)
            ->needs('$caminho')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.ibge.arquivo'));

        $this->app->when(DocumentacaoFiscalController::class)->needs('$urlBase')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.url'));

        $this->app->when(DocumentacaoFiscalController::class)->needs('$segundosDeTimeout')
            ->give(fn ($app): int => (int) $app->make(Configuracao::class)->get('fiscal.timeout'));

        $this->app->when(BuscarEnderecoPeloCep::class)->needs('$url')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.cep.url'));

        $this->app->when(BuscarCadastroPeloCnpj::class)->needs('$url')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.cnpj.url'));

        $this->app->when(ApiDoIbge::class)
            ->needs('$url')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.ibge.url'));

        // Mesma divisao das cidades: arquivo local no dia a dia, portal oficial
        // so quando alguem pede a atualizacao.
        $this->app->bind(FonteDeClassificacoes::class, ArquivoDeClassificacoes::class);

        $this->app->when(ArquivoDeClassificacoes::class)
            ->needs('$caminho')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.classificacoes.arquivo'));

        $this->app->when(PortalDaSvrs::class)
            ->needs('$url')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.classificacoes.url'));

        // Sem par remoto: o Anexo VII so existe em planilha. Ver
        // ImportarIndicadoresDeOperacao.
        $this->app->bind(FonteDeIndicadores::class, ArquivoDeIndicadores::class);

        $this->app->when(ArquivoDeIndicadores::class)
            ->needs('$caminho')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.indicadores.arquivo'));

        // Tambem sem par remoto: a tabela do IBPT sai sob cadastro. Ver
        // ImportarCargasTributarias.
        $this->app->bind(FonteDeCargas::class, ArquivoDeCargas::class);

        $this->app->when(ArquivoDeCargas::class)
            ->needs('$caminho')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.cargas.arquivo'));

        // Sem par remoto, como o Anexo VII: a origem e a lei consolidada, e
        // escolher a redacao que vale nao e trabalho de raspagem. Ver
        // ArquivoDaListaDeServicos.
        $this->app->bind(FonteDaListaDeServicos::class, ArquivoDaListaDeServicos::class);

        $this->app->when(ArquivoDaListaDeServicos::class)
            ->needs('$caminho')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.lista_de_servicos.arquivo'));

        // Tambem sem par remoto: o Anexo VIII e planilha. Ver ArquivoDaNbs.
        $this->app->bind(FonteDaNbs::class, ArquivoDaNbs::class);

        $this->app->when(ArquivoDaNbs::class)
            ->needs('$caminho')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.nbs.arquivo'));

        // Mesma divisao das classificacoes: arquivo local no dia a dia, Portal
        // Nacional so quando alguem pede a atualizacao.
        $this->app->bind(FonteDeCodigosDeTributacao::class, ArquivoDeCodigosDeTributacao::class);

        $this->app->when(ArquivoDeCodigosDeTributacao::class)
            ->needs('$caminho')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.codigos_de_tributacao.arquivo'));

        $this->app->when(PortalDaNfseNacional::class)
            ->needs('$url')
            ->give(fn ($app): string => (string) $app->make(Configuracao::class)->get('fiscal.codigos_de_tributacao.url'));

        // A referencia de tamanho da importacao e sempre o arquivo local, mesmo
        // quando a fonte e o portal. Ver ImportarCodigosDeTributacaoNacional.
        $this->app->when(ImportarCodigosDeTributacaoNacional::class)
            ->needs('$referencia')
            ->give(ArquivoDeCodigosDeTributacao::class);
    }
}

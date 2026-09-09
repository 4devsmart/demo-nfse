<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Api\ConsultarIdentificacaoDaApi;
use App\Actions\Municipios\ConsultarSuporteDoMunicipio;
use App\Domain\ValueObjects\CodigoIbge;
use App\Fiscal\Respostas\IdentificacaoDaApi;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * A ponte com a API fiscal, visivel. Mostra qual build esta rodando, o que ela
 * sabe fazer e quem atende um municipio, as tres perguntas que se faz antes de
 * culpar o proprio codigo.
 */
class Diagnostico extends Page
{
    protected string $view = 'filament.pages.diagnostico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 10;

    /**
     * O grupo e casado por rotulo: o `NavigationGroup` do painel nao tem id
     * separado. Os dois lados passam pelo mesmo `__()` para nunca divergirem.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('API fiscal');
    }

    public static function getNavigationLabel(): string
    {
        return __('Diagnóstico');
    }

    public function getTitle(): string
    {
        return __('Diagnóstico da API fiscal');
    }

    /** Codigo IBGE digitado na consulta de municipio. */
    public string $codigoDoMunicipio = '';

    /** @var array{codigo: string, provedor: string, layout: string, suportado: bool}|null */
    public ?array $municipioConsultado = null;

    private ConsultarIdentificacaoDaApi $consultarIdentificacao;

    private ?IdentificacaoDaApi $identificacaoDaApi = null;

    private ?string $falhaDaApi = null;

    private bool $jaConsultou = false;

    /**
     * O Livewire resolve as dependencias de `boot()` pelo container, e ele roda
     * a cada requisicao, inclusive nas que so hidratam o componente. E onde a
     * acao cabe: `identificacao()` e chamada pela view, sem argumento.
     */
    public function boot(ConsultarIdentificacaoDaApi $consultarIdentificacao): void
    {
        $this->consultarIdentificacao = $consultarIdentificacao;
    }

    public function getSubheading(): string
    {
        return __('A API não guarda o XML nem o certificado. Quem guarda é esta aplicação.');
    }

    /**
     * Consultada a cada render, nao guardada em propriedade publica: resposta de
     * API nao atravessa requisicoes do Livewire, entao a tela nunca mostra um
     * estado que ja passou.
     */
    public function identificacao(): ?IdentificacaoDaApi
    {
        if ($this->jaConsultou) {
            return $this->identificacaoDaApi;
        }

        $this->jaConsultou = true;

        try {
            return $this->identificacaoDaApi = $this->consultarIdentificacao->executar();
        } catch (Throwable $erro) {
            $this->falhaDaApi = $erro->getMessage();

            return null;
        }
    }

    public function falha(): ?string
    {
        return $this->falhaDaApi;
    }

    public function consultarMunicipio(ConsultarSuporteDoMunicipio $consultar): void
    {
        if (! CodigoIbge::ehValido($this->codigoDoMunicipio)) {
            Notification::make()->warning()->title(__('O código IBGE tem 7 dígitos'))->send();

            return;
        }

        try {
            $municipio = $consultar->executar(CodigoIbge::deSeteDigitos($this->codigoDoMunicipio));
        } catch (Throwable $erro) {
            $this->municipioConsultado = null;
            Notification::make()->danger()->title(__('Não deu para consultar'))->body($erro->getMessage())->send();

            return;
        }

        $this->municipioConsultado = [
            'codigo' => (string) $municipio->codigo,
            'provedor' => $municipio->provedor,
            'layout' => $municipio->layout,
            'suportado' => $municipio->suportado,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('documentacao')
                ->label(__('Abrir o Swagger'))
                ->icon(Heroicon::OutlinedBookOpen)
                ->color('gray')
                ->url(fn (): string => route('fiscal.docs'), shouldOpenInNewTab: true),
        ];
    }
}

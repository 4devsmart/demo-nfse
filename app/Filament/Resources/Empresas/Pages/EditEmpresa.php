<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empresas\Pages;

use App\Actions\Empresas\GuardarCertificado;
use App\Actions\Municipios\ConsultarSuporteDoMunicipio;
use App\Filament\Resources\Empresas\EmpresaResource;
use App\Models\Empresa;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class EditEmpresa extends EditRecord
{
    protected static string $resource = EmpresaResource::class;

    public function getTitle(): string
    {
        return $this->obterEmpresa()->razao_social;
    }

    public function getSubheading(): string
    {
        $empresa = $this->obterEmpresa();

        return "{$empresa->documentoFederal()->formatado()} · {$empresa->cidade->nomeComUf()}";
    }

    /**
     * O Filament preenche o formulário com `attributesToArray()`, que omite o
     * que o modelo declara em `#[Hidden]`. As três colunas da prefeitura são
     * `#[Hidden]` e `#[Fillable]` ao mesmo tempo: chegavam vazias à tela e
     * voltavam vazias no save, então qualquer edição no cadastro apagava o
     * login de webservice, sem erro nenhum. O emitente só parava de transmitir
     * na próxima nota, longe da tela que causou o apagamento.
     *
     * Isto as devolve ao preenchimento. Vale saber o preço: elas passam a
     * trafegar decifradas até o navegador, dentro do estado do Livewire, que é
     * exatamente o que o `#[Hidden]` evitava. É o preço de editá-las neste
     * formulário, que é o que os campos já prometiam ao existir nele. O
     * certificado não passa por aqui: ele não é `#[Fillable]`, sobe pela ação
     * própria e nunca chega ao navegador.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $empresa = $this->obterEmpresa();

        return [
            ...$data,
            'prefeitura_usuario' => $empresa->prefeitura_usuario,
            'prefeitura_senha' => $empresa->prefeitura_senha,
            'prefeitura_token' => $empresa->prefeitura_token,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->acaoDeCertificado(),

            ActionGroup::make([
                $this->acaoDeVerificarMunicipio(),
                $this->acaoDeApagar(),
            ])
                ->label(__('Mais'))
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * Desabilitada, e não escondida, pelo mesmo motivo de `OperacaoFiscal`: ação
     * que some deixa quem opera procurando o que não há. O `disabled` do
     * Filament é conferido no servidor ao montar e ao chamar a ação, então ele
     * também é a guarda, não só o aviso.
     */
    private function acaoDeApagar(): DeleteAction
    {
        return DeleteAction::make()
            ->disabled(fn (Empresa $record): bool => $record->impedimentoParaApagar() !== null)
            ->tooltip(fn (Empresa $record): ?string => $record->impedimentoParaApagar());
    }

    private function acaoDeCertificado(): Action
    {
        return Action::make('certificado')
            ->label(fn (): string => $this->obterEmpresa()->temCertificado() ? __('Trocar certificado') : __('Enviar certificado A1'))
            ->icon(Heroicon::OutlinedKey)
            ->color(fn (): string => $this->obterEmpresa()->temCertificado() ? 'gray' : 'primary')
            ->modalHeading(__('Certificado digital A1'))
            ->modalDescription(__('O .pfx é conferido aqui e guardado cifrado. A API fiscal não persiste certificado: ele viaja em cada chamada e morre com ela.'))
            ->modalSubmitActionLabel(__('Guardar'))
            ->modalWidth('lg')
            ->schema([
                Text::make(fn (): string => $this->descricaoDoCertificadoAtual())
                    ->color(fn (): string => $this->obterEmpresa()->temCertificado() ? 'success' : 'gray'),

                FileUpload::make('arquivo')
                    ->label(__('Arquivo .pfx'))
                    ->required()
                    ->storeFiles(false)
                    // Tres tipos para o mesmo arquivo, e a lista precisa dos
                    // tres. `application/x-pkcs12` e o que o navegador costuma
                    // mandar; `application/pkcs12` e o da RFC 7292, que o
                    // detector de MIME devolve para um A1 de verdade; e
                    // `application/octet-stream` sobra quando ninguem
                    // reconhece o conteudo.
                    ->acceptedFileTypes(['application/x-pkcs12', 'application/pkcs12', 'application/octet-stream'])
                    ->maxSize(8192)
                    ->helperText(__('O arquivo não chega a ser gravado em disco: vai cifrado direto para o banco.')),

                TextInput::make('senha')
                    ->label(__('Senha do certificado'))
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->action(fn (array $data, GuardarCertificado $guardar) => $this->guardarCertificado($data, $guardar));
    }

    private function descricaoDoCertificadoAtual(): string
    {
        $empresa = $this->obterEmpresa();

        if (! $empresa->temCertificado()) {
            return __('Ainda não há certificado. Sem ele dá para gerar a DPS, mas não para transmitir.');
        }

        $dias = $empresa->diasAteOCertificadoVencer();
        $validade = $empresa->certificado_valido_ate?->format('d/m/Y') ?? __('validade desconhecida');

        if ($dias !== null && $dias < 0) {
            return __('Atual: :titular — VENCIDO em :validade.', ['titular' => $empresa->certificado_titular, 'validade' => $validade]);
        }

        return __('Atual: :titular — válido até :validade.', ['titular' => $empresa->certificado_titular, 'validade' => $validade]);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function guardarCertificado(array $dados, GuardarCertificado $guardar): void
    {
        $arquivo = $dados['arquivo'];
        $arquivo = is_array($arquivo) ? reset($arquivo) : $arquivo;

        if (! $arquivo instanceof TemporaryUploadedFile) {
            Notification::make()->danger()->title(__('Arquivo não recebido'))->send();

            return;
        }

        $conteudo = $arquivo->get();

        if ($conteudo === false) {
            Notification::make()->danger()->title(__('Não deu para ler o arquivo enviado'))->send();

            return;
        }

        try {
            $lido = $guardar->executar($this->obterEmpresa(), $conteudo, (string) $dados['senha']);
        } catch (Throwable $falha) {
            Notification::make()->danger()->title(__('Certificado recusado'))->body($falha->getMessage())->send();

            return;
        }

        $this->refreshFormData(['certificado_titular', 'certificado_valido_ate']);

        Notification::make()
            ->success()
            ->title(__('Certificado guardado'))
            ->body(__(':titular — válido até :validade.', ['titular' => $lido->titular, 'validade' => $lido->validoAte->format('d/m/Y')]))
            ->send();
    }

    private function acaoDeVerificarMunicipio(): Action
    {
        return Action::make('verificarMunicipio')
            ->label(__('Verificar município'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->action(fn (ConsultarSuporteDoMunicipio $consultar) => $this->verificarMunicipio($consultar));
    }

    private function verificarMunicipio(ConsultarSuporteDoMunicipio $consultar): void
    {
        $empresa = $this->obterEmpresa();

        try {
            $municipio = $consultar->executar($empresa->municipio());
        } catch (Throwable $falha) {
            Notification::make()->danger()->title(__('Não deu para consultar'))->body($falha->getMessage())->send();

            return;
        }

        Notification::make()
            ->status($municipio->suportado ? 'success' : 'warning')
            ->title($empresa->cidade->nomeComUf())
            ->body($municipio->descricao())
            ->send();
    }

    /**
     * `getRecord()` é declarado como Model porque a página base serve a
     * qualquer recurso. Aqui sabemos qual é, e `assert` deixa isso escrito.
     */
    private function obterEmpresa(): Empresa
    {
        $registro = $this->getRecord();
        assert($registro instanceof Empresa);

        return $registro;
    }
}

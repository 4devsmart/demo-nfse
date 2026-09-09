<?php

declare(strict_types=1);

namespace App\Filament\Resources\CargasTributarias\Pages;

use App\Actions\Cargas\ImportarCargasTributarias;
use App\Actions\Cargas\TabelaIbptOficial;
use App\Consultas\BuscaDeCargasTributarias;
use App\Filament\Resources\CargasTributarias\CargaTributariaAproximadaResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListCargasTributarias extends ListRecords
{
    protected static string $resource = CargaTributariaAproximadaResource::class;

    public function getSubheading(): string
    {
        $consulta = app(BuscaDeCargasTributarias::class);
        $vigencia = $consulta->vigencia();

        if ($vigencia === null) {
            return __('Nenhuma tabela carregada.');
        }

        return __(':total linhas · TabelaIBPTax :versao, vigente até :ate. É o tributo embutido no preço, da Lei da Transparência.', [
            'total' => $consulta->total(),
            'versao' => $vigencia['versao'],
            'ate' => $vigencia['vigencia_fim'] ?? '—',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importarDoIbpt')
                ->label(__('Importar tabela do IBPT'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->modalDescription(__('O arquivo entra como o IBPT o distribui, sem conversão: o ZIP com um CSV por UF, ou o CSV de um estado só. Baixe em deolhonoimposto.ibpt.org.br com o CNPJ da empresa.'))
                ->schema([
                    FileUpload::make('arquivo')
                        ->label(__('TabelaIBPTax'))
                        ->required()
                        ->disk('local')
                        ->directory('ibpt')
                        ->storeFiles()
                        ->acceptedFileTypes([
                            'application/zip',
                            'application/x-zip-compressed',
                            'text/csv',
                            'text/plain',
                        ])
                        ->helperText(__('TabelaIBPTax_26.2.A.zip, ou TabelaIBPTaxRJ26.2.A.csv.')),
                ])
                ->action(fn (array $data) => $this->importar($data)),
        ];
    }

    /**
     * O arquivo enviado some depois da importação, dê ela certo ou não: o que
     * interessa dele já está na tabela, e guardá-lo só acumularia cópias de
     * cinco megabytes no storage.
     *
     * @param  array<string, mixed>  $data
     */
    private function importar(array $data): void
    {
        $enviado = is_string($data['arquivo'] ?? null) ? $data['arquivo'] : '';

        try {
            $total = (new ImportarCargasTributarias(
                new TabelaIbptOficial(Storage::disk('local')->path($enviado))
            ))->executar();

            Notification::make()->success()->title(__(':total linhas importadas do IBPT', ['total' => $total]))->send();
        } catch (Throwable $falha) {
            Notification::make()->danger()->title(__('Não deu para importar'))->body($falha->getMessage())->send();
        } finally {
            Storage::disk('local')->delete($enviado);
        }
    }
}

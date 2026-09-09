<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Pages;

use App\Actions\Notas\AbrirNota;
use App\Filament\Resources\Notas\NotaResource;
use App\Filament\Resources\Notas\Schemas\NotaForm;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Em etapas: partes, servico, valores. Quem emite pela primeira vez nao sabe o
 * que a nota precisa, e uma tela unica com vinte campos nao ensina.
 */
class CreateNota extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = NotaResource::class;

    public function getTitle(): string
    {
        return __('Nova NFS-e');
    }

    public function getSubheading(): string
    {
        return __('Isto cria um rascunho. Nada é transmitido até você mandar.');
    }

    /**
     * @return array<int, mixed>
     */
    protected function getSteps(): array
    {
        return NotaForm::passos();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Criar rascunho'));
    }

    /**
     * Quem cria a nota e o caso de uso, nao a pagina: e la que a numeracao e
     * reservada e a referencia nasce.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(AbrirNota::class)->executar($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

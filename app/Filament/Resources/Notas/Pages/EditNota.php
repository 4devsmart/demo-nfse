<?php

declare(strict_types=1);

namespace App\Filament\Resources\Notas\Pages;

use App\Actions\Notas\AlterarNota;
use App\Domain\Enums\StatusNota;
use App\Filament\Resources\Notas\NotaResource;
use App\Models\Nota;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditNota extends EditRecord
{
    protected static string $resource = NotaResource::class;

    public function getTitle(): string
    {
        return $this->obterNota()->identificacao();
    }

    public function getSubheading(): ?string
    {
        if ($this->obterNota()->status === StatusNota::Rascunho) {
            return null;
        }

        return __('Alterar os dados descarta a DPS já montada: ela descreve a nota anterior. A nota volta a rascunho.');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->outlined(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Nota);

        return app(AlterarNota::class)->executar($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * `getRecord()` é declarado como Model porque a página base serve a
     * qualquer recurso. Aqui sabemos qual é, e `assert` deixa isso escrito.
     */
    private function obterNota(): Nota
    {
        $registro = $this->getRecord();
        assert($registro instanceof Nota);

        return $registro;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empresas\Pages;

use App\Filament\Resources\Empresas\EmpresaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmpresa extends CreateRecord
{
    protected static string $resource = EmpresaResource::class;

    public function getTitle(): string
    {
        return __('Novo emitente');
    }

    public function getSubheading(): string
    {
        return __('O certificado A1 é enviado depois, na tela do emitente.');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

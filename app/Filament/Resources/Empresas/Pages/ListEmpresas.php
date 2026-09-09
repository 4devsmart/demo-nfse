<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empresas\Pages;

use App\Filament\Resources\Empresas\EmpresaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEmpresas extends ListRecords
{
    protected static string $resource = EmpresaResource::class;

    public function getSubheading(): string
    {
        return __('O município do emitente decide o provedor de NFS-e; o certificado A1 assina a transmissão.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Novo emitente'))->icon(Heroicon::Plus),
        ];
    }
}

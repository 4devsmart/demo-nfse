<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListClientes extends ListRecords
{
    protected static string $resource = ClienteResource::class;

    public function getSubheading(): string
    {
        return __('O tomador do serviço: quem recebe a nota.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Novo tomador'))->icon(Heroicon::Plus),
        ];
    }
}

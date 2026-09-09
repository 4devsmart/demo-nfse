<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cidades\Schemas;

use App\Rules\CodigoIbgeValido;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CidadeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(6)
            ->components([
                TextInput::make('codigo_ibge')
                    ->label(__('Código IBGE'))
                    ->placeholder('3550308')
                    ->required()
                    ->unique()
                    ->rule(new CodigoIbgeValido)
                    ->columnSpan(6)
                    ->helperText(__('São os 7 dígitos que decidem o provedor de NFS-e do município.')),

                TextInput::make('nome')
                    ->label(__('Município'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(4),

                TextInput::make('uf')
                    ->label(__('UF'))
                    ->required()
                    ->length(2)
                    ->columnSpan(2)
                    ->extraInputAttributes(['style' => 'text-transform: uppercase']),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Actions\Enderecos\BuscarEnderecoPeloCep;
use App\Actions\Enderecos\EnderecoEncontrado;
use App\Consultas\BuscaDeCidades;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Empresa e cliente tem o mesmo endereco, entao o bloco mora aqui em vez de
 * viver duplicado. A ordem segue o preenchimento real: o CEP vem primeiro
 * porque e ele que traz o resto.
 */
final class CamposDeEndereco
{
    public static function secao(): Section
    {
        return Section::make(__('Endereço'))
            ->icon(Heroicon::OutlinedMapPin)
            ->description(__('O município decide o provedor de NFS-e, e é por ele que a API resolve o layout. Buscar pelo CEP preenche logradouro, bairro e município de uma vez.'))
            ->aside()
            ->columns(12)
            ->schema(self::campos());
    }

    /**
     * @return array<int, mixed>
     */
    private static function campos(): array
    {
        $cidades = app(BuscaDeCidades::class);

        return [
            self::cep()->columnSpan(3),

            Select::make('cidade_id')
                ->label(__('Município'))
                ->placeholder(__('Nome ou código IBGE'))
                ->required()
                ->searchable()
                ->native(false)
                ->columnSpan(9)
                ->getSearchResultsUsing(fn (string $search): array => $cidades->procurar($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => $cidades->rotuloDe($value)),

            TextInput::make('logradouro')->label(__('Logradouro'))->required()->columnSpan(7),
            TextInput::make('numero')->label(__('Número'))->required()->maxLength(20)->columnSpan(2),
            TextInput::make('complemento')->label(__('Complemento'))->placeholder(__('Sala, andar…'))->columnSpan(3),

            TextInput::make('bairro')->label(__('Bairro'))->required()->columnSpan(4),
            Campos::telefone()->columnSpan(4),

            TextInput::make('email')
                ->label(__('E-mail'))
                ->email()
                ->placeholder('contato@empresa.com.br')
                ->columnSpan(4),
        ];
    }

    private static function cep(): TextInput
    {
        return TextInput::make('cep')
            ->label(__('CEP'))
            ->required()
            ->mask('99999-999')
            ->placeholder('00000-000')
            // A mascara e enfeite de digitacao; a coluna tem oito digitos. E o
            // mesmo corte que `Endereco::paraApi()` faz do outro lado.
            ->dehydrateStateUsing(fn (?string $state): string => preg_replace('/\D/', '', (string) $state) ?? '')
            ->suffixAction(
                Action::make('buscarCep')
                    ->icon(Heroicon::MagnifyingGlass)
                    ->label(__('Buscar endereço pelo CEP'))
                    ->action(fn (?string $state, Set $set) => self::preencherPeloCep((string) $state, $set)),
            );
    }

    private static function preencherPeloCep(string $cep, Set $set): void
    {
        try {
            $endereco = app(BuscarEnderecoPeloCep::class)->executar($cep);
        } catch (Throwable $falha) {
            Notification::make()->warning()->title(__('CEP não preenchido'))->body($falha->getMessage())->send();

            return;
        }

        self::preencher($endereco, $set);

        Notification::make()
            ->success()
            ->title(__('Endereço preenchido'))
            ->body("{$endereco->localidade}/{$endereco->uf}")
            ->send();
    }

    /**
     * Aplica ao formulario o endereco que veio de uma consulta, a do CEP ou a do
     * CNPJ. Campo que voltou vazio nao apaga o que ja esta na tela: a consulta
     * de CEP nao sabe numero nem complemento, e quem digitou sabe.
     *
     * E publico porque a consulta de CNPJ tambem devolve endereco: preenchido
     * em dois lugares, o formulario acaba divergindo de si mesmo.
     */
    public static function preencher(EnderecoEncontrado $endereco, Set $set): void
    {
        $campos = [
            'cep' => $endereco->cep,
            'logradouro' => $endereco->logradouro,
            'numero' => $endereco->numero,
            'complemento' => $endereco->complemento,
            'bairro' => $endereco->bairro,
        ];

        foreach ($campos as $campo => $valor) {
            if ($valor !== '') {
                $set($campo, $valor);
            }
        }

        if ($endereco->municipio === null) {
            return;
        }

        $cidade = app(BuscaDeCidades::class)->idPeloCodigoIbge($endereco->municipio);

        if ($cidade !== null) {
            $set('cidade_id', $cidade);
        }
    }
}

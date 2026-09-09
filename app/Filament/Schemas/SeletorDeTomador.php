<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Actions\Clientes\GravarTomador;
use App\Consultas\ClientesTomadores;
use App\Models\Cliente;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

/**
 * O tomador da nota, com o cadastro à mão. Quem está emitindo descobre que o
 * tomador não existe no meio da digitação, e mandá-lo para outra tela custa a
 * nota que estava sendo preenchida.
 *
 * Os dois modais montam o mesmo cadastro de `CamposDoTomador`, o da tela de
 * clientes. Em painel lateral porque o cadastro é alto: o modal centrado
 * empurraria a nota para fora da vista.
 */
final class SeletorDeTomador
{
    public static function campo(string $nome = 'cliente_id'): Select
    {
        $tomadores = app(ClientesTomadores::class);

        return Select::make($nome)
            ->label(__('Tomador'))
            ->placeholder(__('Busque por nome ou documento'))
            ->required()
            ->searchable()
            ->native(false)
            ->getSearchResultsUsing(fn (string $search): array => $tomadores->procurar($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => $tomadores->rotuloDe($value))

            // O seletor guarda `cliente_id` e não declara relacionamento, então
            // o Filament não tem de onde deduzir o model do modal: sem esta
            // linha o `unique` do CPF/CNPJ procuraria o documento na tabela de
            // notas. No modal de edição quem responde é o registro escolhido,
            // que é também o que o `unique` ignora para não brigar consigo.
            ->actionSchemaModel(Cliente::class)
            ->getSelectedRecordUsing(fn (mixed $state): ?Cliente => $tomadores->encontrar($state))

            ->manageOptionForm(fn (Schema $schema): Schema => $schema->columns(1)->components(CamposDoTomador::secoes(comExplicacao: false)))
            ->createOptionUsing(fn (array $data): int => app(GravarTomador::class)->executar($data)->getKey())
            ->fillEditOptionActionFormUsing(fn (Select $component): ?array => $component->getSelectedRecord()?->attributesToArray())
            ->updateOptionUsing(function (array $data, Schema $schema): void {
                $tomador = $schema->getRecord();
                assert($tomador instanceof Cliente);

                app(GravarTomador::class)->executar($data, $tomador);
            })
            ->manageOptionActions(fn (Action $action): Action => $action->slideOver()->modalWidth('3xl'))
            ->createOptionModalHeading(__('Novo tomador'))
            ->editOptionModalHeading(__('Editar tomador'));
    }
}

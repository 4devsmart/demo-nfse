<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Actions\Cadastros\BuscarCadastroPeloCnpj;
use App\Actions\Cadastros\CadastroEncontrado;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * A lupa do campo de CNPJ, irma da lupa do CEP: consulta o cadastro da Receita
 * e preenche o formulario com o que voltou.
 *
 * Razao social, contato e endereco existem nos dois cadastros. O que so existe
 * num deles, como o nome fantasia e o CNAE do emitente, entra pelo `$tambem`:
 * assim nenhum formulario recebe estado de campo que ele nao tem.
 */
final class ConsultaDeCnpj
{
    /**
     * @param  (Closure(CadastroEncontrado): array<string, string>)|null  $tambem
     */
    public static function botao(?Closure $tambem = null): Action
    {
        return Action::make('buscarCnpj')
            ->icon(Heroicon::MagnifyingGlass)
            ->label(__('Buscar cadastro pelo CNPJ'))
            // Formulario sem `tipo_pessoa` e de pessoa juridica, e a lupa vale
            // sempre. Onde o campo existe, CPF nao tem cadastro publico para
            // consultar, e botao que so sabe recusar e ruido na tela.
            ->visible(fn (Get $get): bool => ! Campos::ehPessoaFisica($get))
            ->action(fn (?string $state, Set $set) => self::preencher((string) $state, $set, $tambem));
    }

    /**
     * @param  (Closure(CadastroEncontrado): array<string, string>)|null  $tambem
     */
    private static function preencher(string $cnpj, Set $set, ?Closure $tambem): void
    {
        try {
            $cadastro = app(BuscarCadastroPeloCnpj::class)->executar($cnpj);
        } catch (Throwable $falha) {
            Notification::make()->warning()->title(__('Cadastro não preenchido'))->body($falha->getMessage())->send();

            return;
        }

        $campos = [
            'razao_social' => $cadastro->razaoSocial,
            'telefone' => $cadastro->telefone,
            'email' => $cadastro->email,
            ...($tambem === null ? [] : $tambem($cadastro)),
        ];

        foreach ($campos as $campo => $valor) {
            if ($valor !== '') {
                $set($campo, $valor);
            }
        }

        CamposDeEndereco::preencher($cadastro->endereco, $set);

        self::avisar($cadastro);
    }

    private static function avisar(CadastroEncontrado $cadastro): void
    {
        if (! $cadastro->estaAtiva()) {
            Notification::make()
                ->warning()
                ->title(__('Cadastro preenchido, com ressalva'))
                ->body(__('A situação de :razao na Receita é :situacao.', [
                    'razao' => $cadastro->razaoSocial,
                    'situacao' => $cadastro->situacao,
                ]))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('Cadastro preenchido'))
            ->body("{$cadastro->razaoSocial}, {$cadastro->endereco->localidade}/{$cadastro->endereco->uf}")
            ->send();
    }
}

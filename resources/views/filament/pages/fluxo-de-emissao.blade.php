@use('Filament\Support\Icons\Heroicon')

<x-filament-panels::page>
    <x-filament::section :icon="Heroicon::OutlinedArrowsRightLeft">
        <x-slot name="heading">{{ __('A emissão são duas chamadas') }}</x-slot>
        <x-slot name="description">
            {!! __('A primeira devolve o <code>id_dps</code> sem enviar nada ao provedor.') !!}
            {{ __('Se a transmissão der timeout, é por esse identificador que se consulta o que aconteceu, em vez de reenviar e duplicar o documento.') }}
        </x-slot>

        <div class="nfse-passos">
            @foreach ($passos as $indice => $passo)
                <div class="nfse-passo">
                    <p class="nfse-passo__titulo">
                        <span class="nfse-passo__ordem">{{ $indice + 1 }}</span>
                        {{ $passo['titulo'] }}
                    </p>
                    <code class="nfse-codigo">{{ $passo['chamada'] }}</code>
                    <p class="nfse-passo__texto">{{ $passo['explicacao'] }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :icon="Heroicon::OutlinedFlag">
        <x-slot name="heading">{{ __('Estados da nota') }}</x-slot>
        <x-slot name="description">{{ __('O que cada estado significa e qual chamada avança a partir dele.') }}</x-slot>

        <div class="nfse-tabela__rolagem">
            <table class="nfse-tabela">
                <thead>
                    <tr>
                        <th>{{ __('Situação') }}</th>
                        <th>{{ __('O que significa') }}</th>
                        <th>{{ __('Próximo passo') }}</th>
                        <th>{{ __('Chamada na API') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($estados as $estado)
                        <tr>
                            <td>
                                <x-filament::badge :color="$estado['situacao']->getColor()" :icon="$estado['situacao']->getIcon()">
                                    {{ $estado['situacao']->getLabel() }}
                                </x-filament::badge>
                            </td>
                            <td>{{ $estado['significado'] }}</td>
                            <td>{{ $estado['proximoPasso'] }}</td>
                            <td>
                                @if ($estado['chamada'])
                                    <code class="nfse-codigo">{{ $estado['chamada'] }}</code>
                                @else
                                    <span class="nfse-tabela__nulo">{{ __('estado final') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section :icon="Heroicon::OutlinedExclamationTriangle">
        <x-slot name="heading">{{ __('Códigos de erro') }}</x-slot>
        <x-slot name="description">
            {!! __('A API identifica cada falha pelo campo <code>codigo</code>. Trate por ele, não pela mensagem, que pode mudar entre versões.') !!}
            {{ __('Repetir a chamada só é seguro quando se sabe que nada saiu.') }}
        </x-slot>

        <div class="nfse-tabela__rolagem">
            <table class="nfse-tabela">
                <thead>
                    <tr>
                        <th>{{ __('Código') }}</th>
                        <th>{{ __('O que aconteceu') }}</th>
                        <th>{{ __('O que fazer') }}</th>
                        <th>{{ __('Pode repetir?') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($falhas as $falha)
                        <tr>
                            <td><code class="nfse-codigo">{{ $falha['codigo']->value }}</code></td>
                            <td>{{ $falha['significado'] }}</td>
                            <td>{{ $falha['oQueFazer'] }}</td>
                            <td>
                                <x-filament::badge :color="$falha['podeRepetir'] ? 'success' : 'danger'">
                                    {{ $falha['podeRepetir'] ? __('sim') : __('não') }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

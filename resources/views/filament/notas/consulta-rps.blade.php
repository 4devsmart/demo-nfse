{{--
    A resposta da consulta por RPS, lida. O estado no provedor vem primeiro,
    porque e a pergunta de quem consulta: virou nota, e ela ainda vale? A
    conferencia com esta nota vem logo depois, e o que nao bate e o que pede acao.
--}}
<div class="nfse-consulta">
    <div @class(['nfse-situacao', 'nfse-situacao--'.$resultado['estado']])>
        <span class="nfse-situacao__rotulo">{{ __('No provedor') }}</span>
        <span class="nfse-situacao__titulo">{{ $resultado['titulo'] }}</span>
        <span class="nfse-situacao__apoio">{{ $resultado['apoio'] }}</span>
    </div>

    @if ($resultado['campos'] !== [])
        <div class="nfse-resumo__grade">
            @foreach ($resultado['campos'] as $rotulo => $valor)
                <div class="nfse-resumo__parte">
                    <span class="nfse-resumo__rotulo">{{ $rotulo }}</span>
                    <span class="nfse-resumo__valor">{{ $valor }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if (! $resultado['eh_da_nota'])
        <p class="nfse-resumo__apoio">{{ __('Este não é o RPS desta nota, então não há o que conferir com ela.') }}</p>
    @elseif ($resultado['divergencias'] === [])
        <p class="nfse-conferencia nfse-conferencia--confere">
            {{ __('Confere com esta nota, que aqui está :situacao.', ['situacao' => $resultado['situacao_aqui']]) }}
        </p>
    @else
        <div class="nfse-conferencia nfse-conferencia--diverge">
            <strong>{{ __('Não confere com esta nota') }}</strong>
            <ul class="nfse-conferencia__lista">
                @foreach ($resultado['divergencias'] as $divergencia)
                    <li>{{ $divergencia }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($resultado['pode_guardar_evento'])
        <p class="nfse-resumo__apoio">{{ __('O registro do cancelamento veio nesta resposta. Para guardá-lo na nota e baixar o XML, use "Buscar XML do evento".') }}</p>
    @endif

    @if ($resultado['mensagens'] !== [])
        <div class="nfse-resumo__parte">
            <span class="nfse-resumo__rotulo">{{ __('Mensagens do provedor') }}</span>
            <ul class="nfse-conferencia__lista">
                @foreach ($resultado['mensagens'] as $mensagem)
                    <li>{{ $mensagem }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

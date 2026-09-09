{{--
    Quem recebe a DPS sai do municipio do emitente. E resposta do sistema, e nao
    campo a preencher: por isso caixa com marca de estado, e nao mais uma linha
    de formulario igual as de cima.
--}}
<div @class([
    'nfse-provedor',
    'nfse-provedor--'.$estado,
])>
    <x-filament::icon :icon="$icone" class="nfse-provedor__icone" />

    <div class="nfse-provedor__corpo">
        <span class="nfse-provedor__rotulo">{{ __('Provedor de NFS-e') }}</span>
        <span class="nfse-provedor__valor">{{ $descricao }}</span>
        <span class="nfse-provedor__apoio">{{ $explicacao }}</span>
    </div>
</div>

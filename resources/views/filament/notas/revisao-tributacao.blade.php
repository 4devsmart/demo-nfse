<div class="nfse-resumo">
    @foreach ($linhas as $rotulo => $valor)
        <div class="nfse-resumo__parte">
            <span class="nfse-resumo__rotulo">{{ $rotulo }}</span>
            <span class="nfse-resumo__valor">{{ $valor }}</span>
        </div>
    @endforeach
</div>

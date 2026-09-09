{{-- Uma conta, na ordem em que ela é feita: o líquido fecha embaixo da régua. --}}
<div class="nfse-conta">
    @foreach ($linhas as $linha)
        <div @class([
            'nfse-conta__linha',
            'nfse-conta__linha--destaque' => $linha['papel'] === 'destaque',
            'nfse-conta__linha--total' => $linha['papel'] === 'total',
        ])>
            <span class="nfse-conta__rotulo">{{ $linha['rotulo'] }}</span>
            <span class="nfse-conta__condutor"></span>
            <span class="nfse-conta__valor">{{ $linha['valor'] }}</span>
        </div>
    @endforeach
</div>

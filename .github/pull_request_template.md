## O que muda

<!-- Uma ou duas frases. O que o PR faz, do ponto de vista de quem usa. -->

## Por que

<!-- O problema que isso resolve. Se houver issue, referencie com "Resolve #N". -->

## Norma citada

<!--
Só para mudança que toca cálculo, campo do XML ou regra de validação fiscal.
Lei, artigo e parágrafo, ou nota técnica. Apague esta seção se não se aplica.
-->

## Como conferir

<!-- Os passos na tela, ou o comando de teste que exercita a mudança. -->

## Checklist

- [ ] `make qualidade` passa (estilo, tipos, camadas e testes)
- [ ] Comportamento novo ou alterado tem teste
- [ ] Ação nova com impedimento entra em `AcoesFiscaisNaTelaTest` e `AcoesRecusamForaDaTelaTest`
- [ ] Texto de tela passa por `__()`
- [ ] README atualizado, se a mudança altera o que ele descreve

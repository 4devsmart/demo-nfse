# Como contribuir

Contribuição é bem-vinda. Este documento diz o que o projeto espera de um PR e como rodar as
mesmas verificações que a esteira roda.

## Antes de abrir um PR

Abra uma issue primeiro quando a mudança for grande, mudar comportamento fiscal ou acrescentar
dependência. Correção de bug, texto de tela e teste podem ir direto para o PR.

## Subindo o ambiente

```bash
git clone git@github.com:4devsmart/demo-nfse.git
cd demo-nfse
make up
```

Docker é o único requisito. PHP e Composer rodam dentro do container.

## O que precisa passar

```bash
make qualidade
```

Roda, nesta ordem, o que a esteira também roda:

| Verificação | Comando isolado |
|---|---|
| Estilo (Pint) | `make pint` |
| Tipos (PHPStan nível 8) | `make analise` |
| Camadas (Deptrac) | `make arquitetura` |
| Testes (PHPUnit) | `make teste` |

PR com a esteira vermelha não é revisado.

## Regras que as ferramentas cobram

Estão descritas no README, na seção *O que as ferramentas cobram*. As que mais aparecem em
revisão:

- **Tela não consulta o banco.** Um componente Filament chama uma `Action` (escrita) ou uma
  `Consulta` (leitura). Query montada à mão dentro da tela é recusada pelo
  `TelasNaoConsultamOBancoTest`.
- **Classe de CSS própria começa por `nfse-`**, e o arquivo é `resources/css/nfse.css`. Não há
  build de front-end.
- **Ícone é enum**, no PHP e na Blade: `Heroicon::OutlinedClock`, nunca `'heroicon-o-clock'`.
- **Regra dita uma vez.** O que impede uma operação mora em `ImpedimentosDaNota`, e vale para
  a tela e para a chamada programática. Ação nova com impedimento entra nos dois testes:
  `AcoesFiscaisNaTelaTest` e `AcoesRecusamForaDaTelaTest`.
- **Fronteira de camada** está no `deptrac.php`. Só uma `Action` alcança `Fiscal/Contracts`.

## Testes

Teste de feature por padrão; unitário só para lógica que não usa o framework.

Os casos de uso dependem de `GatewayFiscal`, não do cliente HTTP, então a emissão inteira é
testável sem rede, com o `tests/Apoio/GatewayFiscalFalso.php`. O `TestCase` chama
`Http::preventStrayRequests()`: teste que esquece o `Http::fake()` falha em vez de sair
batendo na internet.

```bash
make teste                    # a suíte inteira
make teste f=EmissaoDeNota    # um arquivo só
```

## Código e idioma

Nome de classe, de método, de variável, de coluna e comentário em pt-BR. O inglês fica onde o
nome não é escolha do projeto, como ganchos de framework e pastas do esqueleto do Laravel.

Texto que a pessoa lê na tela passa por `__()`, com a chave sendo o próprio texto em
português.

Comentário explica o porquê, não o quê. Comentário que descreve a linha abaixo não entra.

## Mensagem de commit

Em português, no imperativo, dizendo o que a mudança faz:

```
Guarda o XML do evento de cancelamento
Corrige a base da CSRF quando há desconto incondicionado
```

Sem prefixo de convenção, sem emoji e sem assinatura de ferramenta.

## Mudança que toca legislação

Cite a norma na descrição do PR, com artigo e parágrafo: lei, lei complementar, decreto ou
nota técnica. Regra tributária sem fonte não entra, mesmo quando o cálculo parece óbvio.

## Escopo

O que está fora está listado no README, em *Limites desta demonstração*. Se o seu PR muda um
desses limites, diga isso na descrição.

# NFS-e Demo

[![Qualidade](https://github.com/4devsmart/demo-nfse/actions/workflows/qualidade.yml/badge.svg)](https://github.com/4devsmart/demo-nfse/actions/workflows/qualidade.yml)

Sistema de exemplo que emite NFS-e pela [wrapper-api](https://github.com/4devsmart/wrapper-api).
Cada tela exercita uma rota da API.

Laravel 13, Filament 5, Livewire 4, SQLite, Docker.

---

## Requisitos

Docker, e mais nada. PHP e Composer rodam dentro do container. Não há Node nem build de
front-end: o CSS próprio é um arquivo estático em `resources/css/nfse.css`, registrado pelo
Filament.

| O que | Versão mínima |
|---|---|
| Docker Engine | 23 |
| Docker Compose | 2.24.4 |

```bash
docker --version
docker compose version
```

`docker-compose version 1.x` não serve: a v1 não lê arquivo chamado `compose.yaml`. Uma v2
anterior à 2.24.4 não reconhece a tag `!override` do `compose.override.yaml` e a subida para
com erro de YAML. Nos dois casos, atualize o plugin do Docker.

As imagens da API fiscal são públicas no GitHub Container Registry, sem `docker login`.

### Processador ARM (Apple Silicon e Windows ARM)

A aplicação roda nativa nas duas arquiteturas. A API fiscal não: a imagem
`ghcr.io/4devsmart/wrapper-api/api:v1.3.0` é publicada só para `linux/amd64`, e o `compose.yaml`
fixa `platform: linux/amd64` nos serviços `fiscal-api` e `fiscal-worker`. Esses dois sobem
emulados.

Em Mac com chip M1 ou mais novo, ligue a Rosetta em Settings > General > "Use Rosetta for
x86_64/amd64 emulation on Apple Silicon".

---

## Subir

O `.env` não vem no repositório. A subida cria ele a partir do `.env.example`.

### Linux

```bash
git clone <url-do-repositorio> nfse-demo
cd nfse-demo
make up
```

Na primeira subida o `make up` grava o seu `id -u` e `id -g` no `.env`. Existindo `.env`, ele
não encosta no arquivo: ali está a `APP_KEY`, que cifra o certificado A1 guardado no banco.
Trocando o UID depois, edite as duas linhas à mão.

Sem `make`:

```bash
[ -f .env ] || sed -e "s/^USER_ID=.*/USER_ID=$(id -u)/" \
                   -e "s/^GROUP_ID=.*/GROUP_ID=$(id -g)/" .env.example > .env
docker compose up -d --build
```

Não use `cp .env.example .env` direto: por cima de um `.env` existente, isso troca a
`APP_KEY` e o certificado cifrado com a chave antiga não abre mais.

Em Fedora, RHEL, Rocky e derivados, o `:z` do SELinux já está no `compose.override.yaml`.

### macOS

```bash
git clone <url-do-repositorio> nfse-demo
cd nfse-demo
make up
```

O `make` vem com as Command Line Tools do Xcode. Se ainda não estiverem instaladas, o
primeiro `make` abre a janela pedindo. Para adiantar: `xcode-select --install`.

A Apple distribui o GNU Make 3.81. Os alvos funcionam nela; os comandos que passam pelo
`make exec` rodam sem TTY, e `make shell`, `make tinker` e `make usuario` continuam
interativos.

Em Apple Silicon, leia a seção de processador ARM acima.

### Windows

**WSL2, recomendado.** Instale o WSL2, ligue a integração no Docker Desktop em Settings >
Resources > WSL Integration e clone dentro do sistema de arquivos do Linux, em algo como
`~/projetos/nfse-demo`. Dali em diante é igual ao Linux:

```bash
make up
```

Não clone em `C:\` para montar por `/mnt/c`: o `composer install` da primeira subida fica
lento e o SQLite sofre com o travamento de arquivo.

**PowerShell, sem make.**

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
docker compose up -d --build
```

E no dia a dia:

```powershell
docker compose exec app php artisan route:list
docker compose exec app composer show --direct
docker compose exec app php artisan test --compact
docker compose exec app vendor/bin/pint
```

É o que cada alvo do Makefile faz. A tabela de atalhos abaixo mostra a correspondência.

Usando o Git Bash, `choco install make` ou `scoop install make` habilitam os atalhos.

---

## O que a subida faz

Sobe três containers e prepara o resto: dependências, `APP_KEY`, banco SQLite, migrations,
as tabelas oficiais e um emitente e um tomador de exemplo. A primeira vez demora alguns
minutos.

| Endereço | O que é |
|---|---|
| <http://localhost:8080/admin> | o painel. Entre com `admin@nfse.test` e `senha-demo` |
| <http://localhost:8080/docs> | o Swagger da API fiscal, espelhado por esta aplicação |
| <http://localhost:8081/docs> | o mesmo Swagger, direto da API. Só em desenvolvimento |

As portas saem de `APP_PORT` e `FISCAL_PORT`, no `.env`.

`make logs` mostra os três containers. `make pail` mostra só o log da aplicação, formatado.

---

## Atalhos

`make ajuda` lista todos.

| Atalho | O que roda |
|---|---|
| `make up` | `docker compose up -d --build` |
| `make down` | `docker compose down` |
| `make logs` | `docker compose logs -f` |
| `make shell` | `docker compose exec app bash` |
| `make migrate` | `docker compose exec app php artisan migrate --force` |
| `make seed` | `docker compose exec app php artisan db:seed --force` |
| `make fresh` | `docker compose exec app php artisan migrate:fresh --seed` |
| `make usuario` | `docker compose exec app php artisan make:filament-user` |
| `make teste` | `docker compose exec app php artisan test --compact` |
| `make pint` | `docker compose exec app vendor/bin/pint` |
| `make analise` | `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G` |
| `make arquitetura` | `docker compose exec app vendor/bin/deptrac analyse --no-progress` |
| `make atualizar-api` | `docker compose pull` e `up -d` dos serviços `fiscal-api` e `fiscal-worker` |
| `make prod-build` | constrói a imagem de produção. Do zero: `make prod-build sem-cache=1` |
| `make prod-up` | `docker compose -f compose.yaml up -d` |
| `make prod-down` | derruba a stack de produção |
| `make prod-logs` | acompanha os logs de produção |

Para o que não tem atalho há três curingas:

```bash
make artisan route:list
make composer update
make exec vendor/bin/phpstan
```

Comando com opções vai entre aspas em `c="..."`:

```bash
make artisan  c="route:list --path=api --except-vendor"
make composer c="show --direct"
make exec     c="vendor/bin/pint --dirty"
```

---

## O que o sistema faz

### Emissão

Assistente em quatro etapas: partes, serviço, valores e revisão. A base de cálculo, o ISSQN e
o líquido aparecem enquanto se digita, e a revisão repete tudo com a conta fechada.

O tomador é cadastrado e editado num painel lateral, sem sair da nota.

```
Rascunho ──> POST /v1/nfse/xml ──> DPS gerada ──> POST /v1/nfse/transmissao ──> Autorizada
                (id_dps)                              (chave, protocolo, XML)
```

São duas chamadas. A primeira devolve o `id_dps`, determinístico, antes de qualquer byte
sair; é ele que recupera uma transmissão sem resposta.

**`502 desfecho_indeterminado` e timeout não autorizam repetir.** A nota vai para o status
`Desfecho indeterminado`, e a saída é consultar: Consultar DPS (pelo `id_dps`), Consultar no
provedor (pela chave) ou Consultar por RPS (pelo par série e número). As três rotas que
gravam documento no provedor, transmissão, cancelamento e substituição, tratam ausência de
resposta como indeterminada. Consultar, montar a DPS e desenhar o DANFSE não gravam nada, e
nelas repetir é seguro.

O `docker/php.*.ini` fixa `max_execution_time` acima do `FISCAL_API_TIMEOUT`, e o
`LimitesDeTempoTest` falha se essa margem sumir.

Quem decide o layout é o município, pelo código IBGE. A ação **Verificar município** no
cadastro da empresa e a página **Diagnóstico** chamam `GET /v1/nfse/municipios/{codigo}`.

**Ver JSON da DPS** mostra o corpo exato que iria para a API, montado a partir do cadastro,
sem enviar nada. **Ver XML da DPS** mostra o XML que a API monta a partir desse JSON.

### Numeração

Este sistema numera a DPS. A prefeitura numera a NFS-e. A tela mostra as duas separadas:
`série/nº` é a DPS, `Número da NFS-e` é o que o provedor devolveu.

A reserva acontece em `ReservarNumeroDaDps`, com incremento feito pelo banco
(`SET col = col + 1`) dentro de uma transação. O índice único `(empresa_id, série, número)`
impede número repetido, e o cadastro recusa voltar o contador para trás.

Números são reservados quando o rascunho nasce e não voltam.

### Tributação

O formulário pergunta antes de mostrar: "há retenção do ISSQN?", e só então "por quem". Vale
para retenção, suspensão da exigibilidade (tipo e nº do processo), benefício municipal (nº e
% de redução da base), retenções federais (PIS, COFINS, CSLL, IRRF e contribuição
previdenciária), IBS/CBS e os totais aproximados da Lei 12.741/2012.

O benefício municipal reduz a base, logo reduz o ISSQN devido. Os dois descontos saem do
líquido; só o incondicionado sai da base.

A CSRF (PIS, COFINS e CSLL) segue o piso de R$ 5.000,00 do art. 31 da Lei 10.833/2003. A
contribuição previdenciária incide sobre o valor bruto da nota, e não sobre o montante a
pagar. Prestador ME/EPP optante pelo Simples que apura o ISSQN por dentro não declara
alíquota, conforme a regra E0625 do Padrão Nacional.

### Depois de emitida

| Ação | O que faz |
|---|---|
| DANFSE | o impresso da API, aberto na aba a partir do XML autorizado |
| Baixar XML | a DPS enviada, a NFS-e autorizada e o evento, quando houver |
| Consultar DPS | pergunta pelo `id_dps` se a DPS virou nota |
| Consultar no provedor | pergunta o estado da nota pela chave |
| Consultar por RPS | pergunta pelo par série e número |
| Cancelar | evento de cancelamento, com motivo de 15 a 255 caracteres |
| Substituir | emite a substituta identificando a nota antiga |
| Buscar XML do evento | traz da fila DF-e o documento assinado do cancelamento ou da substituição |

O DANFSE imprime `SITUAÇÃO DA NFS-E: NFS-e Gerada` mesmo em nota cancelada, porque é
desenhado do XML autorizado, anterior ao evento. O que registra o evento é o XML dele, que a
resposta do cancelamento não devolve: ele vem da fila DF-e, por **Buscar XML do evento**.

### Cadastros

CPF/CNPJ, CEP e telefone com máscara; o banco recebe só os dígitos. O CEP preenche
logradouro, bairro e município pelo código IBGE. A lupa do CNPJ traz da Receita razão social,
endereço completo e, no emitente, nome fantasia e CNAE principal. As duas consultas saem pela
BrasilAPI; fora do ar, viram aviso e o formulário continua preenchível à mão.

O emitente guarda padrões que a nota herda: cidade, código de tributação, CNAE, item da lista,
NBS, alíquota do ISSQN, alíquotas de retenção federal e a classificação de IBS/CBS.

### Tabelas oficiais

Seleção por busca, com o texto oficial ao lado do código.

| Tabela | Linhas | Fonte |
|---|---|---|
| Municípios | 5.571 | IBGE |
| Itens da lista de serviços | 200 | Anexo da LC 116/2003 |
| Códigos de tributação nacional | 335 | Portal da NFS-e Nacional |
| Itens da NBS | 903 correlações | Anexo VIII do RTC |
| Classificações tributárias | 71 | LC 214/2025 |
| Indicadores de operação | 36 | Leiaute do Padrão Nacional |
| Carga tributária aproximada | 5.346 | IBPT, Lei 12.741/2012 |

### Páginas de referência

**Fluxo da emissão** traz a tabela de situação, o que ela significa, qual o próximo passo e
qual chamada da API o faz, mais os códigos de erro e o que fazer com cada um.
**Diagnóstico** pergunta à API o ping, as capacidades e o provedor de um município.

---

## Produção

Produção é o mesmo `compose.yaml`, sem o arquivo de override:

```bash
make prod-up            # docker compose -f compose.yaml up -d
```

O estágio `prod` copia o código e cacheia config, rotas e views; o `dev` monta o código do
host. A porta da API fiscal deixa de existir: quem chama o serviço fiscal é a aplicação, pelo
nome do serviço na rede interna do compose.

Duas variáveis precisam estar no `.env` antes de subir, e o entrypoint recusa subir sem elas.

`APP_KEY` fixa, porque ela cifra o certificado A1 guardado no banco:

```bash
docker compose run --rm app php artisan key:generate --show
```

`FISCAL_API_TOKEN` trocado, porque o valor de exemplo está publicado neste repositório. Gere
com `openssl rand -hex 32` e use o mesmo valor para a aplicação e para o container
`fiscal-api`.

Em produção não há usuário nem empresa de exemplo, só as tabelas oficiais. Crie o primeiro
acesso com:

```bash
docker compose -f compose.yaml exec app php artisan make:filament-user
```

### O TLS não está aqui

Esta stack serve HTTP puro na porta 8080. O `SERVER_NAME=":8080"` do `docker/Dockerfile` é só
a porta, sem nome de host, e é isso que desliga o HTTPS automático do Caddy que vem dentro do
FrankenPHP.

Por aqui trafegam a senha do certificado A1, o login de webservice da prefeitura e o cookie
de sessão do painel. Numa exposição real, ponha um proxy reverso com TLS na frente e ajuste
o `.env`:

```bash
APP_URL=https://seu-dominio
SESSION_SECURE_COOKIE=true
```

O `SESSION_SECURE_COOKIE` fica desligado por padrão: ligado sem HTTPS, o navegador não
devolve o cookie e o login para de funcionar. Ele é o segundo passo, depois do TLS.

### Memória

O FrankenPHP abre `2 × núcleos` threads, e a conta é `threads × memory_limit < memória
disponível`. Com os 256M do `docker/php.prod.ini`, oito núcleos pedem 4 GB no pior caso.

---

## Certificado digital

O A1 (`.pfx`) é enviado na tela da empresa, no botão **Certificado A1**, conferido na hora
com `openssl_pkcs12_read` e guardado cifrado com a `APP_KEY`. A API fiscal não persiste
certificado: ele vai na requisição, é usado na sessão nativa e morre com ela.

A imagem sobe com o provider `legacy` do OpenSSL habilitado, no `RUN sed` do
`docker/Dockerfile`. Sem ele, o `openssl_pkcs12_read` recusa `.pfx` que usam RC2 e 3DES.

Sem certificado dá para fazer tudo até **Gerar DPS**, que não assina, não fala com a
prefeitura e não pede certificado.

---

## Como o código está organizado

200 arquivos e cerca de 17.300 linhas em `app/`. Cada pasta responde a uma pergunta:

| Pasta | A pergunta que ela responde |
|---|---|
| `Domain/` | O que essas palavras significam? (enums, value objects, impedimentos) |
| `Fiscal/` | Como se conversa com a API fiscal? |
| `Actions/` | O que o sistema faz? Uma classe, um verbo, um `executar()` |
| `Consultas/` | O que as telas leem? |
| `Models/` | O que fica guardado? |
| `Filament/` | Como isso aparece na tela? |

```
app/
├── Domain/            o vocabulário: o que essas palavras significam
│   ├── Enums/         Ambiente, StatusNota, TributacaoIssqn, RetencaoIssqn...
│   ├── ValueObjects/  DocumentoFederal, CodigoIbge, Dinheiro, Aliquota, Competencia
│   └── Notas/         ImpedimentosDaNota, TributacaoRespondida
│
├── Fiscal/            tudo que sabe que existe uma API fiscal
│   ├── Contracts/     GatewayFiscal, a porta
│   ├── Http/          WrapperFiscal, a única classe que conhece rota, verbo e erro
│   ├── Dps/           ConstrutorDps (montagem fluida), Pessoa, Servico, Valores
│   ├── Pedidos/       ContextoDoProvedor, PayloadDps, MotivoDoCancelamento
│   ├── Respostas/     DTOs tipados do que a API devolve
│   ├── Certificado/   o A1 e a leitura do .pfx
│   ├── Traducao/      Eloquent para fiscal (MontadorDaDps, ContextoDaEmpresa)
│   └── Excecoes/      FalhaFiscal (por código) e DesfechoIndeterminado
│
├── Actions/           os casos de uso: uma classe, um verbo, um executar()
├── Consultas/         read models, é daqui que as telas tiram dados
├── Models/            Cidade, Empresa (emitente), Cliente (tomador), Nota
├── Rules/             CodigoIbgeValido, DocumentoFederalValido
└── Filament/          as telas: só chamam Actions e Consultas
```

Uma migration por tabela nova, com o esquema à vista.

### A montagem da DPS é fluida

```php
ConstrutorDps::novo()
    ->noAmbiente($empresa->ambiente)
    ->emitidaPor($empresa->comoPrestador())->naCidade($empresa->municipio())
    ->para($cliente->comoTomador())
    ->numerada($serie, $numero)->naCompetencia($competencia)
    ->doServico($servico)->comValores($valores)
    ->identificadaPor($referencia)
    ->montar();                      // PayloadDps
```

Cada passo devolve uma instância nova. `montar()` recusa uma DPS incompleta e poda os campos
vazios.

`ContextoDoProvedor` junta o que toda rota de NFS-e repete: município, ambiente, emitente e
certificado.

### O código é em português

Nome de classe, de método, de variável, de coluna de banco e comentário estão em pt-BR. O
inglês fica onde o nome não é escolha do projeto: `Http/`, `Models/`, `Providers/`, `Rules/`,
`Filament/`, `Actions/`, `Domain/`, e os ganchos de framework, como `getLabel()`,
`getIterator()` e `handleRecordCreation()`.

O texto da tela passa por `__()`, com a chave sendo o próprio texto em português. São 857
chamadas. O `lang/pt_BR.json` traduz as mensagens do Laravel, cujas chaves são em inglês.

---

## O que as ferramentas cobram

| Regra | Quem cobra |
|---|---|
| Classe de CSS própria começa por `nfse-` | `EstiloProprioTest` |
| Ícone escrito como enum, no PHP e na Blade | regra do PHPStan e `IconesDasViewsTest` |
| Raiz de formulário com `->columns(1)` | `LayoutDosFormulariosTest` |
| Tela não consulta o banco nem escreve SQL | `TelasNaoConsultamOBancoTest` e Deptrac |
| Tabela com `deferLoading()`, relação carregada junto, sem coluna demais | regras do PHPStan |
| Lista longa é buscável | regra do PHPStan |
| `unique()` de formulário com `ignoreRecord: true` | regra do PHPStan |
| Fronteira de camada | `deptrac.php` |
| Impedimento dito uma vez, valendo na tela e fora dela | `AcoesFiscaisNaTelaTest` e `AcoesRecusamForaDaTelaTest` |
| Margem entre `max_execution_time` e `FISCAL_API_TIMEOUT` | `LimitesDeTempoTest` |

As sete regras próprias do PHPStan estão em `tests/Apoio/PhpStan` e são registradas no
`phpstan.neon`.

Erro da API se trata pelo código, nunca pela mensagem: `FalhaFiscal` preserva o `codigo` e
sabe dizer se é seguro repetir.

---

## Qualidade

```bash
make qualidade      # estilo, análise estática, arquitetura e testes
make cobertura      # cobertura de linhas (pcov)
make mutacao        # testes de mutação, uns 30 min
```

| Ferramenta | O que responde |
|---|---|
| Pint (`make pint`) | o estilo está uniforme? |
| PHPStan nível 8 (`make analise`) | os tipos fecham em `app/`, `database/` e `tests/`? |
| Deptrac (`make arquitetura`) | as camadas continuam separadas? |
| PHPUnit (`make teste`) | o comportamento está correto? São 694 testes |
| Infection (`make mutacao`) | a suíte perceberia se o comportamento mudasse? Cobertura de mutação 100% e MSI 93% no código coberto, em `Domain/`, `Fiscal/`, `Actions/` e `Consultas/` |

O driver de cobertura é o pcov, instalado só no estágio `dev` da imagem. O escopo do Infection
está no `infection.json5` e exclui `Filament/` e `Providers/`. Os relatórios saem em
`storage/infection/`.

---

## Testes

```bash
make teste                    # a suíte inteira
make teste f=EmissaoDeNota    # um arquivo só
```

Os casos de uso dependem de `GatewayFiscal`, não do cliente HTTP, então a emissão inteira é
testável sem rede, com o `tests/Apoio/GatewayFiscalFalso.php`. O cliente HTTP tem testes
próprios contra `Http::fake()`, incluindo o `502 desfecho_indeterminado` e a rejeição do
provedor, que volta `422` com o corpo do documento.

---

## O que da API é usado

| Rota NFS-e | No sistema |
|---|---|
| `POST /v1/nfse/xml` | Só gerar a DPS, Ver XML da DPS |
| `POST /v1/nfse/transmissao` | Transmitir, Emitir |
| `POST /v1/nfse/consulta-dps` | Consultar DPS (recuperação após 502) |
| `POST /v1/nfse/consulta` | Consultar no provedor (pela chave) |
| `POST /v1/nfse/consultas/rps` | Consultar por RPS (pelo par série e número) |
| `POST /v1/nfse/eventos/cancelamento` | Cancelar |
| `POST /v1/nfse/eventos/substituicao` | Substituir |
| `POST /v1/nfse/distribuicao` | Buscar XML do evento |
| `POST /v1/nfse/pdf` | DANFSE |
| `GET /v1/nfse/municipios/{codigo}` | Verificar município, Diagnóstico |
| `GET /v1/ping` e `/v1/capacidades` | Diagnóstico |

Ficaram de fora `consultas/numero`, `faixa`, `situacao` e `lote-rps`: são consultas ABRASF, e
os provedores testados devolvem `operacao_nao_suportada`.

> **Nem toda rota funciona em todo provedor.** A substituição por webservice devolve
> `operacao_nao_suportada` nos cinco provedores testados: Padrão Nacional, ISSSalvador,
> ISSNet, ISSSãoPaulo e ISSCampinas. Quando o provedor não atende, a nota substituta fica como
> rascunho com as correções já digitadas, e a original continua valendo.

---

## Por onde começar a ler

1. `app/Fiscal/Contracts/GatewayFiscal.php`, todo o contrato com a API em uma tela.
2. `app/Fiscal/Dps/ConstrutorDps.php`, como a DPS é montada, passo a passo.
3. `app/Actions/Notas/EmitirNota.php`, três linhas que mostram por que são duas chamadas.
4. `app/Fiscal/Http/WrapperFiscal.php`, a única classe que conhece rota, verbo e erro.
5. Na tela: **Ver JSON da DPS** e **Fluxo da emissão**.

---

## Limites desta demonstração

- **Um serviço por nota.** É o que o Padrão Nacional espera na DPS.
- **Sem multi-tenant.** Várias empresas emitentes convivem; separação por usuário não.
- **Sem fila.** A emissão é síncrona. `EmitirNota` vira job sem alteração.
- **Distribuição DF-e sem cursor persistido.** A busca do XML do evento percorre a fila desde
  o começo a cada chamada, com teto de páginas. Consumo contínuo do DF-e pede guardar o NSU.
- **`tribFed` não é escrito no XML.** Esta build da API aceita o grupo de tributação federal
  (CST, PIS/COFINS, IRRF, CSLL, CP) e não o grava. Verificado em cinco variações de payload.
- **Total de tributos é conta local.** A API não devolve total calculado em rota nenhuma. Em
  município de layout `proprio` o número pode divergir do provedor, e lá quem tem razão é o
  XML autorizado.
- Homologação **não tem valor fiscal**. Produção exige certificado válido e, em muitos
  municípios fora do Padrão Nacional, também credenciais da prefeitura
  (`CredenciaisDaPrefeitura` já existe para isso).

---

## Licença e contribuição

MIT, no `LICENSE`.

| Documento | O que traz |
|---|---|
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | o que um PR precisa passar e as regras que as ferramentas cobram |
| [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) | código de conduta |
| [`SECURITY.md`](SECURITY.md) | como relatar vulnerabilidade, e o que já é conhecido |
| [`AVISO.md`](AVISO.md) | responsabilidade fiscal, dados e uso |

O `main` só recebe código por pull request, e a esteira roda estilo, tipos, camadas e testes
em cada PR.

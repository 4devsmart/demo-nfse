# Aviso de uso

Este repositório é uma demonstração técnica de integração com a
[wrapper-api](https://github.com/4devsmart/wrapper-api). Ele emite NFS-e de verdade quando
apontado para produção, e quem opera responde pelo que emite.

## Responsabilidade fiscal

O software é distribuído sob a licença MIT, sem garantia de qualquer espécie, conforme o
texto do `LICENSE`. Isso inclui, e vale dizer explicitamente:

- **A apuração é responsabilidade do emitente.** As contas de ISSQN, retenções federais,
  IBS/CBS e totais aproximados de tributos seguem a legislação citada no código e são
  conferíveis, mas não substituem a análise do contador responsável.
- **O número que vale é o do XML autorizado.** O total calculado aqui é local. A API fiscal
  não devolve total apurado em rota nenhuma, e em município de layout próprio os dois podem
  divergir.
- **Homologação não tem valor fiscal.** Nota emitida em homologação não gera obrigação nem
  crédito.
- **Cancelamento e substituição têm prazo**, definido por cada município. O sistema não
  controla esse prazo.

## Dados e segurança

- O certificado A1 é guardado cifrado com a `APP_KEY` da instalação. Perder a chave torna o
  certificado ilegível.
- A stack serve HTTP puro. Numa exposição real, ponha um proxy reverso com TLS na frente
  antes de cadastrar qualquer certificado ou credencial de prefeitura.
- O `FISCAL_API_TOKEN` de exemplo está publicado neste repositório e precisa ser trocado
  antes de qualquer uso fora da máquina local.

## Marcas e dados de terceiros

NFS-e, DPS, DANFSE e os leiautes do Padrão Nacional são especificações públicas do Comitê
Gestor da NFS-e. As tabelas de referência (municípios do IBGE, itens da LC 116/2003, códigos
de tributação nacional, NBS, classificações da LC 214/2025 e carga tributária do IBPT) são
dados públicos, redistribuídos aqui com a fonte indicada no README.

# Política de segurança

## Versões suportadas

Este repositório é uma demonstração e não mantém versões antigas. Correção de segurança entra
no `main`.

## Como relatar

Não abra issue pública para vulnerabilidade.

Use o [reporte privado do GitHub](https://github.com/4devsmart/demo-nfse/security/advisories/new).
O relato chega só aos mantenedores.

Ajuda ter no relato:

- o que a falha permite fazer
- o caminho para reproduzir, com o mínimo de passos
- a versão, o commit ou a data do clone
- se a exploração exige acesso ao painel ou não

A resposta sai em até 15 dias. Corrigido, o crédito vai no advisory, salvo pedido em
contrário.

## O que já é conhecido, e não é vulnerabilidade

Está documentado no README e no `AVISO.md`:

- **A stack serve HTTP puro na porta 8080.** O TLS é responsabilidade de quem expõe, por proxy
  reverso. Está no README, em *O TLS não está aqui*.
- **O `FISCAL_API_TOKEN` de exemplo está publicado neste repositório.** Ele existe para a
  máquina local e o entrypoint recusa subir em produção sem que ele seja trocado.
- **As credenciais do usuário de demonstração** (`admin@nfse.test`) só são criadas fora de
  produção, pelo `DemonstracaoSeeder`.
- **O certificado A1 é guardado cifrado com a `APP_KEY`.** Quem tem acesso ao banco e ao `.env`
  ao mesmo tempo tem acesso ao certificado. É o modelo esperado.

## Escopo

Vale como vulnerabilidade o que estiver no código deste repositório. Falha na
[wrapper-api](https://github.com/4devsmart/wrapper-api), no Laravel, no Filament ou em
qualquer dependência deve ser relatada ao projeto de origem.

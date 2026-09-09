<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API fiscal (wrapper-api)
    |--------------------------------------------------------------------------
    |
    | Em producao o servico nao tem router: quem o chama e esta aplicacao, pelo
    | nome do servico na rede interna do compose. Nao publique a porta.
    |
    */

    'url' => env('FISCAL_API_URL', 'http://fiscal-api:8080'),

    'token' => env('FISCAL_API_TOKEN', ''),

    // Transmissao fala com a prefeitura: a espera e maior que a de um CRUD.
    'timeout' => (int) env('FISCAL_API_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Consultas de cadastro (BrasilAPI)
    |--------------------------------------------------------------------------
    |
    | Conveniencia de cadastro. Fora do ar, os campos continuam preenchiveis a
    | mao, entao falha aqui vira aviso na tela e nunca bloqueio.
    |
    | O CEP usa a v2, que devolve o codigo IBGE do municipio junto do endereco:
    | e o codigo que liga o CEP ao provedor de NFS-e. O CNPJ traz o cadastro da
    | Receita, incluindo o CNAE principal.
    |
    */

    'cep' => [
        'url' => env('CEP_URL', 'https://brasilapi.com.br/api/cep/v2/{cep}'),
    ],

    'cnpj' => [
        'url' => env('CNPJ_URL', 'https://brasilapi.com.br/api/cnpj/v1/{cnpj}'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Municipios do IBGE
    |--------------------------------------------------------------------------
    |
    | O arquivo acompanha o projeto para que a demonstracao suba sem internet;
    | a URL so e usada quando alguem pede a atualizacao pela tela.
    |
    */

    'ibge' => [
        'arquivo' => database_path('data/municipios.json'),
        'url' => env('IBGE_MUNICIPIOS_URL', 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Classificacao tributaria do IBS e da CBS (cClassTrib)
    |--------------------------------------------------------------------------
    |
    | A tabela oficial da Reforma Tributaria, publicada pela SVRS no portal que
    | o Portal Nacional da NF-e indica. Como o arquivo de municipios, a copia
    | local acompanha o projeto e a URL so e usada quando alguem pede a
    | atualizacao pela tela.
    |
    | Nao ha CSV oficial a apontar: os botoes de exportacao da pagina montam o
    | arquivo no navegador. Quem le a pagina e extrai a tabela e PortalDaSvrs.
    |
    */

    'classificacoes' => [
        'arquivo' => database_path('data/classificacoes-tributarias.json'),
        'url' => env('SVRS_CLASSIFICACOES_URL', 'https://dfe-portal.svrs.rs.gov.br/DFE/TabelaClassificacaoTributaria'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Indicadores da operacao (cIndOp)
    |--------------------------------------------------------------------------
    |
    | O Anexo VII da NT 007, baseado no art. 11 da LC 214/2025. So arquivo
    | local: o anexo oficial e publicado em .xlsx, e nao ha pagina nem JSON de
    | onde ler. A origem esta anotada em ImportarIndicadoresDeOperacao.
    |
    */

    'indicadores' => [
        'arquivo' => database_path('data/indicadores-de-operacao.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Carga tributaria aproximada (TabelaIBPTax)
    |--------------------------------------------------------------------------
    |
    | A tabela do IBPT para a Lei da Transparencia (12.741/2012), reduzida aos
    | itens da LC 116. So arquivo local: o IBPT distribui sob cadastro, um
    | arquivo por UF, e nao ha endereco publico a apontar. Como atualizar esta
    | anotado em ImportarCargasTributarias.
    |
    */

    'cargas' => [
        'arquivo' => database_path('data/cargas-tributarias-aproximadas.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lista de servicos e codigos de tributacao nacional
    |--------------------------------------------------------------------------
    |
    | Duas tabelas, uma dentro da outra. A lista anexa a LC 116/2003 e o que os
    | provedores ABRASF leem em `ItemListaServico`; o `cTribNac` do Padrao
    | Nacional e o subitem dessa lista mais o desdobramento que a NFS-e nacional
    | deu a ele, e e o que vai em `serv.cServ`.
    |
    | A lista da LC 116 so tem arquivo local: a origem e a lei consolidada no
    | Planalto, com redacao original e alterada lado a lado. Por que isso nao
    | vira raspagem esta anotado em ArquivoDaListaDeServicos.
    |
    | Os codigos de tributacao tem os dois: o Portal Nacional publica a tabela
    | inteira em pagina, uma linha por codigo, e a URL so e usada quando alguem
    | pede a atualizacao pela tela.
    |
    */

    'lista_de_servicos' => [
        'arquivo' => database_path('data/itens-da-lista-de-servicos.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlacao com a NBS (cNBS)
    |--------------------------------------------------------------------------
    |
    | O Anexo VIII do RTC: quais itens da Nomenclatura Brasileira de Servicos
    | descrevem cada subitem da LC 116. A rejeicao E0322 exige o `cNBS` sempre
    | que a DPS declara IBS/CBS, e a relacao e de um para muitos, entao o campo
    | e escolha de quem emite; a tabela serve para reduzir a escolha.
    |
    | So arquivo local: o anexo e publicado em .xlsx. A origem esta anotada em
    | ArquivoDaNbs.
    |
    */

    'nbs' => [
        'arquivo' => database_path('data/correlacao-nbs.json'),
    ],

    'codigos_de_tributacao' => [
        'arquivo' => database_path('data/codigos-de-tributacao-nacional.json'),
        'url' => env(
            'NFSE_CODIGOS_TRIBUTACAO_URL',
            'https://www.gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs',
        ),
    ],

];

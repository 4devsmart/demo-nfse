<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\AnalyserConfig;
use Deptrac\Deptrac\Contract\Config\Collector\ClassLikeConfig;
use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\EmitterType;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

/*
 * O teste de arquitetura.
 *
 * O README descreve em prosa o que cada pasta pode conhecer. Aqui a mesma coisa
 * esta escrita de um jeito que a maquina cobra, porque prosa nao quebra o build
 * quando alguem escreve um `use` no arquivo errado.
 *
 * A regra que importa e a direcao das setas. Uma camada so pode alcancar o que
 * esta declarado no `accesses()` dela, e dependencia dentro da mesma camada e
 * sempre livre. O que nao aparece em `accesses()` e proibido de proposito, nao
 * por esquecimento: ao afrouxar uma linha daqui, escreva o motivo junto.
 */
return static function (DeptracConfig $config): void {
    /*
     * O vocabulario do sistema: enums, value objects e as regras que se dizem
     * em uma frase.
     *
     * Conhece o framework, e isso e decisao, nao descuido. Estes enums existem
     * para serem lidos na tela: eles implementam `HasLabel` e `HasIcon`,
     * devolvem `Heroicon` e passam o texto por `__()`, como qualquer outro
     * texto do sistema. A alternativa seria repetir rotulo, cor e icone numa
     * tabela dentro de `Filament/`, longe do enum que os define, e o caso novo
     * entraria no enum sem entrar na tabela.
     *
     * O dominio tambem enxerga o model, porque `ImpedimentosDaNota` responde
     * sobre uma nota concreta, e a nota deste sistema e a linha do banco.
     *
     * O que ele NAO alcanca continua sendo regra: a API fiscal, os casos de
     * uso, as telas e SQL. As setas seguem apontando para ca.
     */
    $dominio = Layer::withName('Dominio')->collectors(
        DirectoryConfig::create('app/Domain/.*'),
    );

    /*
     * A porta para a API fiscal: o contrato e a unica implementacao dele. Quem
     * alcanca isto esta prestes a fazer uma chamada de rede, entao a lista de
     * quem pode e curta: caso de uso e raiz de composicao.
     */
    $porta = Layer::withName('FiscalPorta')->collectors(
        DirectoryConfig::create('app/Fiscal/(Contracts|Http)/.*'),
    );

    /*
     * O resto do que sabe que existe uma API fiscal: montagem da DPS, DTOs de
     * pedido e de resposta, certificado e excecoes. Sao tipos, nao chamadas.
     */
    $fiscal = Layer::withName('Fiscal')->collectors(
        DirectoryConfig::create('app/Fiscal/(?!Contracts/|Http/|Traducao/).*'),
    );

    /*
     * A ponte de Eloquent para o fiscal, separada do resto de `Fiscal/` porque
     * e a unica parte dele que pode conhecer model.
     */
    $traducao = Layer::withName('FiscalTraducao')->collectors(
        DirectoryConfig::create('app/Fiscal/Traducao/.*'),
    );

    /** Os casos de uso: uma classe, um verbo, um `executar()`. */
    $acoes = Layer::withName('Acoes')->collectors(
        DirectoryConfig::create('app/Actions/.*'),
    );

    /** Os read models de onde as telas tiram dado. */
    $consultas = Layer::withName('Consultas')->collectors(
        DirectoryConfig::create('app/Consultas/.*'),
    );

    $modelos = Layer::withName('Modelos')->collectors(
        DirectoryConfig::create('app/Models/.*'),
    );

    $regras = Layer::withName('Regras')->collectors(
        DirectoryConfig::create('app/Rules/.*'),
    );

    /** As telas do painel. Chamam Acao para escrever e Consulta para ler. */
    $telas = Layer::withName('Telas')->collectors(
        DirectoryConfig::create('app/Filament/.*'),
    );

    $http = Layer::withName('Http')->collectors(
        DirectoryConfig::create('app/Http/.*'),
    );

    /** Raiz de composicao: o unico lugar que amarra interface a implementacao. */
    $providers = Layer::withName('Providers')->collectors(
        DirectoryConfig::create('app/Providers/.*'),
    );

    /*
     * O framework como camada de destino. Nao ha arquivo de vendor em `paths`,
     * entao ela nunca e origem de nada: existe para as outras camadas poderem dizer
     * "isto aqui nao entra".
     */
    $framework = Layer::withName('Framework')->collectors(
        ClassLikeConfig::create('^(Illuminate|Filament|Livewire|Symfony|Laravel|Carbon)\\.*'),
    );

    /*
     * O que escreve SQL: a fachada `DB`, o query builder cru, a conexao e o
     * schema. Fica separado do resto do framework para a tela nao alcancar.
     *
     * `Eloquent\Builder` e `Eloquent\Model` NAO entram aqui, e a razao e que
     * sao os tipos dos ganchos do proprio Filament: `modifyQueryUsing`, o
     * `query()` de um filtro, a assinatura de `handleRecordCreation`. Proibi-los
     * seria proibir o comportamento padrao do framework. O que impede a tela de
     * montar consulta a mao com eles e o `TelasNaoConsultamOBancoTest`, que le o
     * codigo: chamada estatica de model o Deptrac nao distingue de type hint.
     */
    $persistencia = Layer::withName('Persistencia')->collectors(
        ClassLikeConfig::create('^Illuminate\\(Support\\Facades\\DB$|Database\\(Query|Schema|Connection|Capsule))'),
    );

    /*
     * Classe de vendor que foi aposentada e ainda existe. Ninguem alcanca, e o
     * erro do Deptrac vem com o nome do arquivo e a linha, que e mais util que
     * um `@deprecated` que so aparece na IDE de quem tem a IDE certa.
     *
     * Isto e regra de nome de classe, e por isso cabe aqui. Depreciacao de
     * ARGUMENTO ou de valor, como `unique(ignoreRecord: true)`, nao: para o
     * Deptrac a classe referenciada e a mesma. Essas moram em `phpstan.neon`.
     */
    /*
     * As factories. Ficam fora de `paths`, entao nunca sao origem: existem como
     * camada so para o model poder cita-las no `@use HasFactory<...>` sem virar
     * dependencia sem classificacao. Uncovered em zero e o que garante que
     * nada passa despercebido por nao estar em camada nenhuma.
     */
    $fabricas = Layer::withName('Fabricas')->collectors(
        ClassLikeConfig::create('^Database\\Factories\\'),
    );

    $depreciado = Layer::withName('Depreciado')->collectors(
        // Consolidada em `Filament\Actions\Action` na v4.
        ClassLikeConfig::create('^Filament\\Notifications\\Actions\\Action$'),
    );

    /*
     * `USE_TOKEN` nao vem ligado, e sem ele o Deptrac nao enxerga `implements`.
     * Foi assim que onze enums de `Domain/` implementavam contrato do Filament
     * sem a ferramenta acusar nada, e foi esse achado que levou a assumir que o
     * dominio conhece o framework em vez de fingir que nao.
     */
    $config
        ->analyser(AnalyserConfig::create([
            EmitterType::CLASS_TOKEN,
            EmitterType::FUNCTION_TOKEN,
            EmitterType::USE_TOKEN,
        ]))
        ->paths('./app')
        ->layers(
            $dominio,
            $porta,
            $fiscal,
            $traducao,
            $acoes,
            $consultas,
            $modelos,
            $regras,
            $telas,
            $http,
            $providers,
            $framework,
            $persistencia,
            $fabricas,
            $depreciado,
        )
        ->rulesets(
            // Fundo do grafo. Alcanca framework e model, e nada alem disso:
            // sem API fiscal, sem caso de uso, sem tela e sem SQL.
            Ruleset::forLayer($dominio)->accesses($modelos, $framework),

            // A porta fala com a rede. Nao conhece caso de uso nem tela: o
            // fluxo entra por ela, nunca sai por ela.
            Ruleset::forLayer($porta)->accesses($dominio, $fiscal, $framework),

            Ruleset::forLayer($fiscal)->accesses($dominio, $framework),
            Ruleset::forLayer($traducao)->accesses($dominio, $fiscal, $modelos, $framework, $persistencia),

            // O unico lugar que junta model, fiscal e a porta na mesma
            // operacao. E tambem o unico que pode transmitir.
            Ruleset::forLayer($acoes)->accesses($dominio, $porta, $fiscal, $traducao, $modelos, $framework, $persistencia),

            // Leitura para a tela. Alcanca `Fiscal` pelos tipos de calculo e de
            // erro, que sao a mesma conta que a emissao usa, e nao a porta:
            // consulta que sai para a rede e Acao, como `ConsultarSuporteDoMunicipio`.
            Ruleset::forLayer($consultas)->accesses($dominio, $fiscal, $modelos, $framework, $persistencia),

            // O model expoe o proprio conteudo em tipo fiscal (`comoPrestador`,
            // `servicoPrestado`) para a montagem da DPS nao remontar campo a
            // campo. Isso e o oposto de conhecer a porta: e so vocabulario.
            Ruleset::forLayer($modelos)->accesses($dominio, $fiscal, $framework, $persistencia, $fabricas),

            Ruleset::forLayer($regras)->accesses($dominio, $framework),

            // A tela chama Acao para escrever e Consulta para ler. Alcanca
            // `Fiscal` para traduzir erro em notificacao e para montar pedido,
            // e nao alcanca a porta: tela nao transmite por conta propria.
            //
            // Nem `Persistencia`: SQL escrito na tela e consulta que ninguem
            // mais reaproveita e que so da para testar subindo o Livewire.
            Ruleset::forLayer($telas)->accesses($acoes, $consultas, $dominio, $fiscal, $traducao, $modelos, $regras, $framework),

            // Alcanca `Fiscal` pelo mesmo motivo que `Telas`: traduzir erro em
            // resposta. Falha da API e desfecho normal de quem baixa o DANFSE,
            // e sem o tipo do erro o controlador so saberia devolver a pagina de
            // erro do Laravel com a pilha inteira. Continua sem a porta: rota
            // nao transmite por conta propria.
            Ruleset::forLayer($http)->accesses($acoes, $fiscal, $modelos, $framework),

            Ruleset::forLayer($providers)->accesses(
                $acoes, $consultas, $dominio, $porta, $fiscal,
                $traducao, $modelos, $regras, $telas, $http, $framework, $persistencia,
            ),

            Ruleset::forLayer($framework),
            Ruleset::forLayer($persistencia),
            Ruleset::forLayer($fabricas),

            // Sem `accesses()` e sem aparecer no `accesses()` de ninguem: e
            // exatamente isso que torna a classe inalcancavel.
            Ruleset::forLayer($depreciado),
        );
};

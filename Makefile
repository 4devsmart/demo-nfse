# Atalhos. Tudo roda em container: o host nao precisa de PHP nem de Composer.

COMPOSE := docker compose
PROD    := docker compose -f compose.yaml

# `docker compose exec` aloca um TTY por padrao, e isso quebra em CI ("the input
# device is not a TTY"). O GNU Make 4.1+ define MAKE_TERMOUT quando a saida e um
# terminal: quando nao ha, passamos -T e o mesmo alvo serve para os dois mundos.
TTY  := $(if $(MAKE_TERMOUT),,-T)
EXEC := $(COMPOSE) exec $(TTY) app

# A porta sai do .env, nao do ambiente do shell: quem trocou APP_PORT la e quem
# precisa ver a URL certa impressa aqui.
APP_PORT := $(shell sed -n 's/^APP_PORT=//p' .env 2>/dev/null | tail -1)
APP_PORT := $(if $(APP_PORT),$(APP_PORT),8080)
FISCAL_PORT := $(shell sed -n 's/^FISCAL_PORT=//p' .env 2>/dev/null | tail -1)
FISCAL_PORT := $(if $(FISCAL_PORT),$(FISCAL_PORT),8081)

.DEFAULT_GOAL := ajuda

# Curingas de linha livre: `make artisan route:list`, `make composer update`.
#
# O Make le cada palavra da linha como um alvo, entao `update` sozinho daria
# "Sem regra para processar o alvo". `.DEFAULT` da a esses restos uma regra
# vazia, e ARGS os recolhe para repassar ao container.
#
# A guarda importa: o `.DEFAULT` so existe quando o PRIMEIRO alvo e um curinga.
# Sem ela, `make tset` deixa de ser erro e passa a nao fazer nada em silencio,
# que e a pior resposta possivel a um alvo digitado errado.
CURINGAS := artisan composer exec
ARGS     := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))

ifneq ($(filter $(firstword $(MAKECMDGOALS)),$(CURINGAS)),)
.DEFAULT:
	@:
endif

.PHONY: ajuda up down atualizar-api logs pail shell artisan composer exec tinker migrate seed usuario \
        teste cobertura mutacao pint analise arquitetura qualidade fresh \
        prod-build prod-up prod-down prod-logs

ajuda: ## Lista os alvos
	@grep -E '^[a-z][a-z-]*:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-13s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "  O Make nao repassa opcoes soltas. Com flags, passe a linha em c=\"...\":"
	@echo "    make artisan  c=\"route:list --path=api\""
	@echo "    make composer c=\"show --direct\""
	@echo "    make exec     c=\"vendor/bin/pint --dirty\""


# --------------------------------------------------------------- desenvolvimento

# O .env nasce do .env.example com o UID e o GID do host ja gravados. No Linux
# isso e o que faz o bind mount funcionar: o container roda com usuario proprio,
# e se o UID nao bater com o dono dos arquivos o `composer install` da subida nao
# escreve em vendor/. No Mac e no Windows o Docker Desktop remapeia a posse
# sozinho, e o valor nao muda nada.
up: ## Sobe a stack de desenvolvimento (app + fiscal-api + fiscal-worker)
	@[ -f .env ] || sed -e "s/^USER_ID=.*/USER_ID=$$(id -u)/" \
	                    -e "s/^GROUP_ID=.*/GROUP_ID=$$(id -g)/" .env.example > .env
	$(COMPOSE) up -d --build
	@echo "Painel:  http://localhost:$(APP_PORT)/admin"
	@echo "Swagger: http://localhost:$(FISCAL_PORT)/docs (direto da API, so em dev)"

down: ## Derruba a stack
	$(COMPOSE) down

# O `pull` nao e redundante, e esquece-lo custa caro. A tag `v1` da wrapper-api e
# movel: quando a imagem e reconstruida la, a tag passa a apontar para outro
# digest, mas a copia local continua satisfazendo `v1` e o `up` nao busca nada.
# O container sobe com a versao velha sem um aviso sequer, e o defeito que voce
# esperava ver corrigido continua ali.
#
# Os dois servicos entram no comando porque compartilham a mesma imagem: o
# `fiscal-worker` e quem fala com o ACBr, e atualizar so o `fiscal-api` deixaria
# os dois em versoes diferentes.
#
# A listagem no fim mostra o IMAGE ID de cada servico. E por ele que se confere
# que a troca aconteceu: `pull` que nao encontrou nada novo devolve o mesmo id.
atualizar-api: ## Atualiza a imagem da wrapper-api (fiscal-api + fiscal-worker)
	$(COMPOSE) pull fiscal-api fiscal-worker
	$(COMPOSE) up -d fiscal-api fiscal-worker
	@echo ""
	@echo "No ar agora:"
	@$(COMPOSE) images fiscal-api fiscal-worker

logs: ## Acompanha os logs dos containers
	$(COMPOSE) logs -f

pail: ## Acompanha o log da aplicacao, ja formatado (laravel/pail)
	$(EXEC) php artisan pail --timeout=0

shell: ## Abre um shell no container da aplicacao
	$(COMPOSE) exec app bash

# O Make nao repassa opcao solta para o alvo, e nao ha como consertar isso aqui
# dentro. Uma flag sem `=` (`--force`) ele transforma em alvo; uma com `=`
# (`--path=api`) ele guarda em MAKEFLAGS; as duas na mesma linha se separam nos
# dois lugares e a ordem original se perde. Nem o separador `--` devolve. Por
# isso a linha com opcoes vai inteira em c="...", onde o Make nao a interpreta.
artisan: ## make artisan route:list · com opcoes: c="route:list --path=api"
	$(EXEC) php artisan $(if $(ARGS),$(ARGS),$(c))

composer: ## make composer update · com opcoes: c="show --direct"
	$(EXEC) composer $(if $(ARGS),$(ARGS),$(c))

exec: ## Qualquer comando no container: make exec c="vendor/bin/pint --dirty"
	$(EXEC) $(if $(ARGS),$(ARGS),$(c))

tinker: ## Abre o tinker
	$(COMPOSE) exec app php artisan tinker

migrate: ## Roda as migrations pendentes
	$(EXEC) php artisan migrate --force

seed: ## Carrega municipios do IBGE e os dados de demonstracao
	$(EXEC) php artisan db:seed --force

usuario: ## Cria um usuario do painel (interativo)
	$(COMPOSE) exec app php artisan make:filament-user

fresh: ## Recria o banco e recarrega os dados
	$(EXEC) php artisan migrate:fresh --seed

# --------------------------------------------------------------- qualidade

# O `config:clear` vem antes de todo alvo que roda teste. Config cacheada e
# lida do arquivo, e ai o `<env force>` do phpunit.xml nao troca o banco por
# `:memory:`: a suite rodaria no banco de desenvolvimento e o RefreshDatabase o
# apagaria. O tests/bootstrap.php recusa rodar nesse estado; isto evita chegar
# la. Ate hoje so o `composer test` limpava.
teste: ## Roda a suite. Um teste so: make teste f=NomeDoTeste
	@$(EXEC) php artisan config:clear -q
	$(EXEC) php artisan test --compact $(if $(f),--filter=$(f))

cobertura: ## Cobertura de linhas (pcov), no terminal
	@$(EXEC) php artisan config:clear -q
	$(EXEC) php vendor/bin/phpunit --coverage-text --coverage-filter=app

mutacao: ## Testes de mutacao: a suite notaria a mudanca? (Infection)
	@$(EXEC) php artisan config:clear -q
	$(EXEC) php -d memory_limit=-1 vendor/bin/infection --threads=max --no-interaction $(a)

pint: ## Formata o codigo
	$(EXEC) vendor/bin/pint

analise: ## Analise estatica (PHPStan nivel 8)
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G

# As camadas estao descritas no README e cobradas no deptrac.php. O alvo falha
# quando um `use` cruza uma fronteira que a configuracao nao declara.
arquitetura: ## Confere as dependencias entre camadas (Deptrac)
	$(EXEC) vendor/bin/deptrac analyse --no-progress

qualidade: ## Estilo + analise estatica + testes, tudo de uma vez
	$(EXEC) composer qualidade

# --------------------------------------------------------------- producao

prod-build: ## Constroi a imagem de producao. Do zero: make prod-build sem-cache=1
	$(PROD) build $(if $(sem-cache),--no-cache)

prod-up: ## Sobe so o compose.yaml (producao)
	$(PROD) up -d

prod-down: ## Derruba a stack de producao
	$(PROD) down

prod-logs: ## Acompanha os logs de producao
	$(PROD) logs -f

# Atalhos para o ciclo de vida do projeto.
#
# Nada aqui esconde o docker compose: cada alvo é praticamente uma linha, e o
# README mostra o comando equivalente logo abaixo de cada um. Quem prefere
# digitar o caminho completo não perde nada — e quem clonou o repositório sem
# ter make instalado também não.
#
# `install` usa `up -d` e não `up -d --build`, e isso é deliberado: numa
# máquina limpa não há imagem, então o compose constrói de qualquer forma, e o
# `--build` só acrescentaria um caminho a mais para dar errado. Ele exige
# resolver `docker/dockerfile:1` no registry, o que passa pelo helper de
# credenciais do Docker — que no WSL com Docker Desktop é um `.exe` e pode
# falhar com "exec format error" mesmo com a stack inteira funcionando.
# Para reconstruir de propósito depois de mexer num Dockerfile:
# `docker compose up -d --build`.

COMPOSE := docker compose

# Dois prefixos para o mesmo container, e a diferença importa: `-T` desliga a
# alocação de TTY. Sem ele, alvo rodando fora de um terminal — CI, pipe,
# subshell — morre com "the input device is not a TTY". Com ele, um shell
# interativo fica sem eco de teclado. Por isso `shell` e `logs` usam a versão
# sem `-T`, e todo o resto usa a com.
PHP := $(COMPOSE) exec -T php
FRONTEND := $(COMPOSE) exec -T frontend

.DEFAULT_GOAL := help
.PHONY: help install up down logs shell test coverage seed seed-volume fresh lint explain wait-migrations

help: ## Lista os alvos disponíveis
	@printf '\n  \033[1mGerador de Relatórios\033[0m — alvos disponíveis\n\n'
	@awk 'BEGIN {FS = ":.*## "} /^[a-z][a-z-]*:.*## / {printf "    \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf '\n'

install: ## Do clone à aplicação usável, em um comando só
	$(COMPOSE) up -d
	@$(MAKE) --no-print-directory wait-migrations
	$(PHP) php artisan db:seed --force
	@printf '\n  \033[1mPronto.\033[0m\n\n'
	@printf '    Aplicação   http://localhost:3000\n'
	@printf '    API         http://localhost:8000\n'
	@printf '    Acesso      admin@billing.test / password\n\n'
	@printf '  Para gerar volume de medição: make seed-volume\n\n'

up: ## Sobe os serviços já construídos
	$(COMPOSE) up -d

down: ## Derruba os serviços, preservando o banco
	$(COMPOSE) down

logs: ## Acompanha os logs de todos os serviços
	$(COMPOSE) logs -f

shell: ## Abre um shell no container do PHP
	$(COMPOSE) exec php sh

test: ## Roda a suíte do backend
	$(PHP) php artisan test

coverage: ## Roda a suíte com relatório de cobertura
	$(PHP) php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text

# Passa opções pelo ARGS, porque o make não repassa flags soltas:
# make explain ARGS="--start=2026-01-01 --end=2026-12-31 --analyze"
explain: ## EXPLAIN das consultas do relatório (use ARGS="--analyze")
	$(PHP) php artisan report:explain $(ARGS)

seed: ## Cria o usuário de acesso
	$(PHP) php artisan db:seed --force

seed-volume: ## Gera 2.000.000 de cobranças para medição (demorado)
	$(PHP) php artisan db:seed --class=BillingVolumeSeeder --force

fresh: ## Recria o schema do zero e semeia o usuário
	$(PHP) php artisan migrate:fresh --seed --force

lint: ## Pint no backend, typecheck e ESLint no frontend
	$(PHP) ./vendor/bin/pint --test
	$(FRONTEND) npx tsc --noEmit
	$(FRONTEND) npx eslint

# Alvo interno, sem `##` para não aparecer no help.
#
# `up -d` devolve o controle assim que os containers sobem, mas o entrypoint do
# php roda as migrations DEPOIS disso. Semear sem esperar falharia com "table
# users doesn't exist" — e falharia justamente na primeira subida, que é a
# única em que `make install` importa.
#
# A espera é longa de propósito: no first-init o MySQL leva ~10min para criar o
# datadir em disco lento, e o entrypoint fica retentando a migration nesse
# intervalo.
wait-migrations:
	@printf '  esperando as migrations do entrypoint'
	@attempt=1; \
	until status=$$($(PHP) php artisan migrate:status 2>/dev/null) \
		&& printf '%s' "$$status" | grep -q 'Ran' \
		&& ! printf '%s' "$$status" | grep -q 'Pending'; do \
		if [ $$attempt -ge 180 ]; then \
			printf '\n  as migrations não concluíram; veja `make logs`\n'; \
			exit 1; \
		fi; \
		printf '.'; \
		sleep 5; \
		attempt=$$((attempt + 1)); \
	done; \
	printf ' ok\n'

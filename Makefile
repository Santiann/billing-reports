# Shortcuts for the project's lifecycle.
#
# Nothing here hides docker compose: each target is practically one line, and the
# README shows the equivalent command right beside it. Whoever would rather type
# the full path loses nothing — and neither does whoever cloned the repository
# without make installed.
#
# `install` uses `up -d` and not `up -d --build`, and that is deliberate: on a
# clean machine there is no image, so Compose builds anyway, and `--build` would
# only add one more way to go wrong. It has to resolve `docker/dockerfile:1` from
# the registry, which goes through Docker's credential helper — which on WSL with
# Docker Desktop is an `.exe` and can fail with "exec format error" with the whole
# stack working. To rebuild deliberately after touching a Dockerfile:
# `docker compose up -d --build`.

COMPOSE := docker compose

# Two prefixes for the same container, and the difference matters: `-T` turns off
# TTY allocation. Without it, a target running outside a terminal — CI, a pipe, a
# subshell — dies with "the input device is not a TTY". With it, an interactive
# shell loses keyboard echo. That is why `shell` and `logs` use the version
# without `-T`, and everything else uses the one with it.
PHP := $(COMPOSE) exec -T php
FRONTEND := $(COMPOSE) exec -T frontend

.DEFAULT_GOAL := help
.PHONY: help install up down logs shell test coverage e2e seed seed-volume fresh lint explain wait-migrations

help: ## Lists the available targets
	@printf '\n  \033[1mBilling Reports\033[0m — available targets\n\n'
	@awk 'BEGIN {FS = ":.*## "} /^[a-z][a-z-]*:.*## / {printf "    \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf '\n'

install: ## From clone to a usable application, in one command
	$(COMPOSE) up -d
	@$(MAKE) --no-print-directory wait-migrations
	$(PHP) php artisan db:seed --force
	@printf '\n  \033[1mReady.\033[0m\n\n'
	@printf '    Application   http://localhost:3000\n'
	@printf '    API           http://localhost:8000\n'
	@printf '    Access        admin@billing.test / password\n\n'
	@printf '  To generate measurement volume: make seed-volume\n\n'

up: ## Starts the already built services
	$(COMPOSE) up -d

down: ## Stops the services, preserving the database
	$(COMPOSE) down

logs: ## Follows every service's logs
	$(COMPOSE) logs -f

shell: ## Opens a shell in the PHP container
	$(COMPOSE) exec php sh

test: ## Runs the backend suite
	$(PHP) php artisan test

coverage: ## Runs the suite with a coverage report
	$(PHP) php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text

# Options go through ARGS, because make does not pass loose flags along:
# make explain ARGS="--start=2026-01-01 --end=2026-12-31 --analyze"
explain: ## EXPLAIN of the report's queries (use ARGS="--analyze")
	$(PHP) php artisan report:explain $(ARGS)

# `run --rm` and not `up`: the service runs to completion, so Playwright's exit
# code becomes make's exit code. The first run downloads Playwright's official
# image, which is large.
e2e: ## End-to-end tests (Playwright) against the running stack
	$(COMPOSE) --profile e2e run --rm e2e

seed: ## Creates the access user
	$(PHP) php artisan db:seed --force

seed-volume: ## Generates 2,000,000 billings for measurement (slow)
	$(PHP) php artisan db:seed --class=BillingVolumeSeeder --force

fresh: ## Recreates the schema from scratch and seeds the user
	$(PHP) php artisan migrate:fresh --seed --force

# `next typegen` is here because Next's route types — LayoutProps, PageProps —
# are GENERATED, and tsconfig includes them. On a fresh clone nobody has created
# them yet and the typecheck fails; on the developer's machine they already exist,
# created by the development server, and the hole stays invisible. CI is what
# surfaced it. (The comment sits outside the recipe: inside it, make would echo
# every line.)
lint: ## Pint on the backend, typecheck and ESLint on the frontend
	$(PHP) ./vendor/bin/pint --test
	$(FRONTEND) npx next typegen
	$(FRONTEND) npx tsc --noEmit
	$(FRONTEND) npx eslint

# An internal target, with no `##` so it does not appear in the help.
#
# `up -d` hands control back as soon as the containers start, but php's entrypoint
# runs the migrations AFTER that. Seeding without waiting would fail with "table
# users doesn't exist" — and it would fail precisely on the first start, which is
# the only one where `make install` matters.
#
# The wait is long on purpose: on the first init MySQL takes ~10min to create the
# datadir on a slow disk, and the entrypoint keeps retrying the migration during
# that interval.
wait-migrations:
	@printf '  waiting for the entrypoint migrations'
	@attempt=1; \
	until status=$$($(PHP) php artisan migrate:status 2>/dev/null) \
		&& printf '%s' "$$status" | grep -q 'Ran' \
		&& ! printf '%s' "$$status" | grep -q 'Pending'; do \
		if [ $$attempt -ge 180 ]; then \
			printf '\n  the migrations did not finish; see `make logs`\n'; \
			exit 1; \
		fi; \
		printf '.'; \
		sleep 5; \
		attempt=$$((attempt + 1)); \
	done; \
	printf ' ok\n'

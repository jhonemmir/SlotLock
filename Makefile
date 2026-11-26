COMPOSE ?= docker compose
APP     ?= $(COMPOSE) exec -u www-data app
DB_USER ?= $(shell grep -E '^DB_USERNAME=' .env 2>/dev/null | cut -d= -f2)
DB_USER := $(if $(DB_USER),$(DB_USER),slotlock)
DB_NAME ?= $(shell grep -E '^DB_DATABASE=' .env 2>/dev/null | cut -d= -f2)
DB_NAME := $(if $(DB_NAME),$(DB_NAME),slotlock)

K6_NETWORK ?= slotlock_default
K6_BASE_URL ?= http://nginx
K6_RESOURCE ?= 00000000-0000-4000-8000-000000000001
N ?= 100
K6 ?= docker run --rm --network $(K6_NETWORK) -v "$(CURDIR)/k6:/scripts:ro" -e BASE_URL=$(K6_BASE_URL) -e RESOURCE_ID=$(K6_RESOURCE) -e N=$(N) grafana/k6

.PHONY: up down build shell setup fresh migrate test test-db lint logs psql fix-perms k6 k6-same k6-distinct k6-retry

up: ## Levanta DB + app + nginx
	$(COMPOSE) up -d --build

down: ## Baja los contenedores (conserva el volumen)
	$(COMPOSE) down

build: ## Reconstruye la imagen PHP
	$(COMPOSE) build

shell: ## Shell dentro del contenedor
	$(COMPOSE) exec app sh

fix-perms: ## Corrige archivos creados por root (storage, cache)
	$(COMPOSE) exec -u root app chown -R www-data:www-data storage bootstrap/cache

setup: up ## Instalación inicial: deps, .env, key, migraciones y seed
	$(APP) sh -c 'test -f .env || cp .env.example .env'
	$(APP) composer install --no-interaction --prefer-dist
	$(APP) php artisan key:generate --force
	$(APP) php artisan migrate --seed --force

fresh: ## Recrea el esquema desde cero + seed
	$(APP) php artisan migrate:fresh --seed --force

migrate: ## Aplica migraciones pendientes
	$(APP) php artisan migrate

test-db: ## Crea la base de datos de tests si no existe
	@$(COMPOSE) exec -T postgres sh -c 'createdb -U "$$POSTGRES_USER" slotlock_test 2>/dev/null || true'

test: test-db ## Corre la suite (PostgreSQL real)
	$(APP) php artisan test

lint: ## Pint en modo verificación
	$(APP) ./vendor/bin/pint --test

k6-same: ## k6: N requests al mismo slot → 1×201, (N-1)×409
	$(K6) run /scripts/same-slot.js

k6-distinct: ## k6: N slots distintos → N×201
	$(K6) run /scripts/distinct-slots.js

k6-retry: ## k6: misma Idempotency-Key concurrente → mismo id
	$(K6) run /scripts/idempotent-retry.js

k6: k6-same k6-distinct k6-retry ## Los tres escenarios de carga

logs:
	$(COMPOSE) logs -f app nginx

psql: ## Sesión psql sobre la DB de desarrollo
	$(COMPOSE) exec postgres psql -U $(DB_USER) -d $(DB_NAME)

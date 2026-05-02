.PHONY: help up down build restart logs ps shell tinker migrate seed fresh test lint analyse spa-shell deploy build-spa prod-up prod-down prod-logs prod-migrate prod-shell

help:
	@echo "Ogami ERP — Make targets"
	@echo "  make up          — start all services (detached)"
	@echo "  make down        — stop all services"
	@echo "  make build       — rebuild images"
	@echo "  make restart     — restart all services"
	@echo "  make logs        — tail logs (CTRL+C to exit)"
	@echo "  make ps          — list running services"
	@echo "  make shell       — bash inside the api container"
	@echo "  make spa-shell   — sh inside the spa container"
	@echo "  make tinker      — Laravel tinker REPL"
	@echo "  make migrate     — run pending migrations"
	@echo "  make seed        — run database seeders"
	@echo "  make fresh       — drop, migrate, seed (destructive)"
	@echo "  make test        — phpunit + vitest"
	@echo "  make lint        — eslint + php-cs-fixer (dry-run)"
	@echo "  make analyse     — larastan + tsc --noEmit"

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build --pull

restart:
	docker compose restart

logs:
	docker compose logs -f --tail=200

ps:
	docker compose ps

shell:
	docker compose exec api bash

spa-shell:
	docker compose exec spa sh

tinker:
	docker compose exec api php artisan tinker

migrate:
	docker compose exec api php artisan migrate

seed:
	docker compose exec api php artisan db:seed

fresh:
	docker compose exec api php artisan migrate:fresh --seed

test:
	docker compose exec api php artisan test
	docker compose exec spa npm run test -- --run

lint:
	docker compose exec api ./vendor/bin/php-cs-fixer fix --dry-run --diff || true
	docker compose exec spa npm run lint || true

analyse:
	docker compose exec api ./vendor/bin/phpstan analyse --memory-limit=1G || true
	docker compose exec spa npx tsc --noEmit || true

# ─── Production (Sprint 4 Task 38) ─────────────────────────────────────
# These targets target docker-compose.prod.yml and assume:
#   • You're SSH'd into the production VPS at /opt/ogami-erp
#   • .env (copied from .env.production.example) is filled in
#   • Let's Encrypt certs exist at /etc/letsencrypt/live/$$SERVER_NAME/
#   • $$SERVER_NAME env var is exported (e.g. erp.ogami.example)
# Full runbook: docs/DEPLOY.md
PROD_COMPOSE := docker compose -f docker-compose.prod.yml

build-spa:
	cd spa && docker run --rm -v "$$PWD:/app" -w /app node:20-alpine \
		sh -c "npm ci --no-audit --no-fund && npm run build"

prod-up:
	$(PROD_COMPOSE) up -d

prod-down:
	$(PROD_COMPOSE) down

prod-logs:
	$(PROD_COMPOSE) logs -f --tail=200

prod-shell:
	$(PROD_COMPOSE) exec api bash

prod-migrate:
	$(PROD_COMPOSE) exec api php artisan migrate --force

deploy: build-spa
	@if [ -z "$$SERVER_NAME" ]; then echo "ERROR: export SERVER_NAME=erp.your.domain"; exit 1; fi
	# Render nginx config with the live domain.
	envsubst '$${SERVER_NAME}' < docker/nginx/prod.conf > docker/nginx/prod.conf.rendered
	mv docker/nginx/prod.conf.rendered docker/nginx/prod.conf
	$(PROD_COMPOSE) build --pull
	$(PROD_COMPOSE) up -d
	# Allow the DB a moment to come up before running migrations.
	sleep 5
	$(PROD_COMPOSE) exec -T api php artisan migrate --force
	$(PROD_COMPOSE) exec -T api php artisan config:cache
	$(PROD_COMPOSE) exec -T api php artisan route:cache
	$(PROD_COMPOSE) exec -T api php artisan view:cache
	$(PROD_COMPOSE) exec -T nginx nginx -s reload || true
	@echo ""
	@echo "  Deploy complete. Smoke-test https://$$SERVER_NAME/sanctum/csrf-cookie"
	@echo ""

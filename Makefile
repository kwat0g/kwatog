.PHONY: help up down build restart logs ps shell tinker migrate seed fresh test lint analyse spa-shell

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

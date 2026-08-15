.PHONY: help up down build restart logs ps shell tinker migrate seed fresh test test-db lint analyse spa-shell chain-smoke worker-recovery-smoke deploy build-spa prod-up prod-down prod-logs prod-migrate prod-shell backup restore prod-backup prod-restore

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
	@echo "  make chain-smoke — isolated migrations + real Redis listener replay worker"
	@echo "  make worker-recovery-smoke — kill a real worker and verify Redis redelivery"
	@echo "  make lint        — eslint + Laravel Pint (dry-run)"
	@echo "  make analyse     — larastan + tsc --noEmit"
	@echo "  make backup      — dump dev DB to ./backups/ogami-<ts>.sql.gz"
	@echo "  make restore FILE=path/to/dump.sql.gz — restore dev DB (destructive)"

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

chain-smoke:
	bash scripts/chain-recovery-smoke.sh

worker-recovery-smoke:
	bash scripts/queue-worker-recovery-smoke.sh

test-db:
	@set -eu; \
	if docker compose exec -T db psql -U ogami -Atqc "SELECT 1 FROM pg_database WHERE datname = 'ogami_test'" | grep -q '^1$$'; then \
		echo "ogami_test already exists"; \
	else \
		docker compose exec -T db psql -U ogami -v ON_ERROR_STOP=1 -c "CREATE DATABASE ogami_test;"; \
	fi

lint:
	docker compose exec -T api ./vendor/bin/pint --test
	docker compose exec -T spa npm run lint

analyse:
	docker compose exec -T api ./vendor/bin/phpstan analyse app --memory-limit=1G
	docker compose exec -T spa npx tsc --noEmit

# ─── Backup / Restore ──────────────────────────────────────────────────
# Dev: dumps to ./backups on the HOST (the db container has /backups
# mounted via the script's BACKUP_DIR env). Production targets below use
# the same scripts but against docker-compose.prod.yml.

backup:
	@mkdir -p backups
	@docker cp scripts/db-backup.sh ogami-db:/tmp/db-backup.sh
	@docker compose exec -T \
		-e BACKUP_DIR=/backups \
		-e DB_HOST=localhost \
		-e DB_PORT=5432 \
		-e DB_USERNAME=$${DB_USERNAME:-ogami} \
		-e DB_PASSWORD=$${DB_PASSWORD:-ogami_dev_pw} \
		-e DB_DATABASE=$${DB_DATABASE:-ogami} \
		db sh -c 'mkdir -p /backups && bash /tmp/db-backup.sh'
	@latest=$$(docker exec ogami-db sh -c 'set -eu; latest="$$(ls -1t /backups/ogami-*.sql.gz 2>/dev/null | head -n 1)"; test -n "$$latest"; gzip -t "$$latest"; printf "%s" "$$latest"'); \
		docker cp "ogami-db:$$latest" ./backups/; \
		local="./backups/$$(basename "$$latest")"; test -s "$$local"; gzip -t "$$local"; \
		if [ -n "$${BACKUP_S3_BUCKET:-}" ]; then \
			command -v aws >/dev/null 2>&1 || { echo "ERROR: BACKUP_S3_BUCKET is set but aws CLI is not installed" >&2; exit 2; }; \
			prefix="$${BACKUP_S3_PREFIX:-}"; [ -z "$$prefix" ] || [ "$${prefix%/}" != "$$prefix" ] || prefix="$$prefix/"; \
			aws s3 cp "$$local" "$${BACKUP_S3_BUCKET%/}/$$prefix$$(basename "$$local")" --only-show-errors; \
		fi; \
		echo "→ backup available at $$local"

restore:
	@if [ -z "$(FILE)" ]; then echo "Usage: make restore FILE=backups/ogami-<ts>.sql.gz"; exit 2; fi
	@if [ ! -f "$(FILE)" ]; then echo "ERROR: $(FILE) not found"; exit 2; fi
	@docker cp "$(FILE)" ogami-db:/tmp/restore.sql.gz
	@docker cp scripts/db-restore.sh ogami-db:/tmp/db-restore.sh
	@docker compose exec -T \
		-e DB_HOST=localhost \
		-e DB_PORT=5432 \
		-e DB_USERNAME=$${DB_USERNAME:-ogami} \
		-e DB_PASSWORD=$${DB_PASSWORD:-ogami_dev_pw} \
		-e DB_DATABASE=$${DB_DATABASE:-ogami} \
		db bash /tmp/db-restore.sh --yes /tmp/restore.sql.gz

# OGAMI-018 — Production backup/restore. Same scripts, prod compose file.
# In prod the scheduler ALSO runs `php artisan db:backup` daily (03:17) inside
# the api container; these targets are the manual / drill entry points.
# Backups land in ./backups on the host (the prod db also persists
# /var/backups/ogami for host-cron drills).
prod-backup:
	@mkdir -p backups
	@docker cp scripts/db-backup.sh ogami-db:/tmp/db-backup.sh
	@$(PROD_COMPOSE) exec -T \
		-e BACKUP_DIR=/var/backups/ogami \
		-e DB_HOST=localhost \
		-e DB_PORT=5432 \
		-e DB_USERNAME=$${DB_USERNAME:-ogami} \
		-e DB_PASSWORD=$${DB_PASSWORD:?set DB_PASSWORD} \
		-e DB_DATABASE=$${DB_DATABASE:-ogami} \
		db sh -c 'mkdir -p /var/backups/ogami && bash /tmp/db-backup.sh'
	@latest=$$(docker exec ogami-db sh -c 'set -eu; latest="$$(ls -1t /var/backups/ogami/ogami-*.sql.gz 2>/dev/null | head -n 1)"; test -n "$$latest"; gzip -t "$$latest"; printf "%s" "$$latest"'); \
		docker cp "ogami-db:$$latest" ./backups/; \
		local="./backups/$$(basename "$$latest")"; test -s "$$local"; gzip -t "$$local"; \
		if [ -n "$${BACKUP_S3_BUCKET:-}" ]; then \
			command -v aws >/dev/null 2>&1 || { echo "ERROR: BACKUP_S3_BUCKET is set but aws CLI is not installed" >&2; exit 2; }; \
			prefix="$${BACKUP_S3_PREFIX:-}"; [ -z "$$prefix" ] || [ "$${prefix%/}" != "$$prefix" ] || prefix="$$prefix/"; \
			aws s3 cp "$$local" "$${BACKUP_S3_BUCKET%/}/$$prefix$$(basename "$$local")" --only-show-errors; \
		fi; \
		echo "→ prod backup available at $$local"

prod-restore:
	@if [ -z "$(FILE)" ]; then echo "Usage: make prod-restore FILE=backups/ogami-<ts>.sql.gz"; exit 2; fi
	@if [ ! -f "$(FILE)" ]; then echo "ERROR: $(FILE) not found"; exit 2; fi
	@docker cp "$(FILE)" ogami-db:/tmp/restore.sql.gz
	@docker cp scripts/db-restore.sh ogami-db:/tmp/db-restore.sh
	@$(PROD_COMPOSE) exec -T \
		-e DB_HOST=localhost \
		-e DB_PORT=5432 \
		-e DB_USERNAME=$${DB_USERNAME:-ogami} \
		-e DB_PASSWORD=$${DB_PASSWORD:?set DB_PASSWORD} \
		-e DB_DATABASE=$${DB_DATABASE:-ogami} \
		db bash /tmp/db-restore.sh --yes /tmp/restore.sql.gz

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
	$(PROD_COMPOSE) up -d db redis meilisearch
	@set -eu; \
		status=; \
		for i in 1 2 3 4 5 6 7 8 9 10; do \
			status=$$(docker inspect -f '{{.State.Health.Status}}' ogami-db 2>/dev/null || true); \
			[ "$$status" = healthy ] && break; \
			sleep 3; \
		done; \
		[ "$$status" = healthy ] || { echo 'ERROR: production database did not become healthy'; exit 1; }
	$(PROD_COMPOSE) up migrate
	$(PROD_COMPOSE) up -d api nginx reverb queue scheduler

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
	$(PROD_COMPOSE) build --pull
	# Apply schema changes before starting code that can consume queued work.
	$(PROD_COMPOSE) up -d db redis meilisearch
	@set -eu; \
		status=; \
		for i in 1 2 3 4 5 6 7 8 9 10; do \
			status=$$(docker inspect -f '{{.State.Health.Status}}' ogami-db 2>/dev/null || true); \
			[ "$$status" = healthy ] && break; \
			sleep 3; \
		done; \
		[ "$$status" = healthy ] || { echo 'ERROR: production database did not become healthy'; exit 1; }
	$(MAKE) prod-backup
	$(PROD_COMPOSE) up migrate
	$(PROD_COMPOSE) up -d api nginx reverb queue scheduler
	$(PROD_COMPOSE) exec -T api php artisan config:cache
	$(PROD_COMPOSE) exec -T api php artisan route:cache
	$(PROD_COMPOSE) exec -T api php artisan view:cache
	$(PROD_COMPOSE) exec -T nginx nginx -s reload
	@echo ""
	@echo "  Deploy complete. Smoke-test https://$$SERVER_NAME/sanctum/csrf-cookie"
	@echo ""

demo-verify: ## Track C — READ-ONLY demo readiness gate (never writes)
	docker compose exec api php artisan demo:verify

demo-seed: ## Track C — USER-ONLY: apply DefenseHeroSeeder to the demo DB (back up first)
	@echo "Back up first: scripts/db-backup.sh — never run this against an unbacked-up DB."
	docker compose exec api php artisan db:seed --class=DefenseHeroSeeder
	@echo "Then re-run: make demo-verify"

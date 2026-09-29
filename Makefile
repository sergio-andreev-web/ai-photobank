.PHONY: help setup up up-dev up-ai down migrate seed test lint ai-mac ai-mac-cpu _ai-mac-run ai-test ai-health logs reset \
	prod-config prod-up prod-down prod-logs prod-health backup restore test-e2e

help:
	@echo "AI Photobank — Development Commands"
	@echo ""
	@echo "Setup:"
	@echo "  make setup       Initialize development environment (one-time)"
	@echo ""
	@echo "Running:"
	@echo "  make up          Start all services"
	@echo "  make up-dev      Start all services with source bind-mounted (docker-compose.dev.yml)"
	@echo "  make down        Stop all services"
	@echo "  make logs        View service logs (follow mode)"
	@echo ""
	@echo "Database:"
	@echo "  make migrate     Run Laravel migrations"
	@echo "  make seed        Seed database with initial data"
	@echo ""
	@echo "Testing & Linting:"
	@echo "  make test        Run all tests (backend, frontend, AI)"
	@echo "  make lint        Run code quality checks"
	@echo "  make test-e2e    Browser §75 acceptance run against the running stack (changes its data)"
	@echo ""
	@echo "AI Development:"
	@echo "  make ai-mac      Run AI service natively on macOS with MPS (§8, §58)"
	@echo "  make ai-mac-cpu  Same, but CPU fallback (dev/smoke only, §58)"
	@echo "  make ai-test     Run AI service with CPU for testing"
	@echo "  make ai-health   Check AI service /health and /ready (AI_URL=..., default localhost:8001)"
	@echo ""
	@echo "Maintenance:"
	@echo "  make reset       Reset all containers and volumes (data loss!)"
	@echo ""
	@echo "Public cloud production profile (docker-compose.prod.yml):"
	@echo "  make prod-config Validate the merged production Compose config"
	@echo "  make prod-up     Start the production profile (needs .env + TLS cert)"
	@echo "  make prod-down   Stop the production profile"
	@echo "  make prod-logs   Follow production service logs"
	@echo "  make prod-health Check nginx/api/admin health over HTTPS"
	@echo "  make backup      Snapshot the database and photo storage"
	@echo "  make restore SNAPSHOT=<ts>  Restore a snapshot (destructive)"

# First-time setup (§66): compose up, migrations, seed, mock AI.
# Each step waits on the services' own healthchecks (`--wait`) instead of a
# fixed sleep, so a cold start on a clean machine or CI runner (images just
# built, first pgvector/RabbitMQ boot) cannot race the migrations, and setup
# only reports success once api, admin, nginx and ai-cpu are healthy.
SETUP_WAIT_TIMEOUT ?= 300

setup: .env
	@echo "Initializing development environment..."
	docker compose up -d --wait --wait-timeout $(SETUP_WAIT_TIMEOUT) postgres redis rabbitmq
	docker compose up -d --wait --wait-timeout $(SETUP_WAIT_TIMEOUT) api
	@echo "Running migrations..."
	docker compose exec -T api php artisan migrate --force
	@echo "Seeding database..."
	docker compose exec -T api php artisan db:seed
	# AI_BASE_URL defaults to the CPU/mock AI container, which lives behind the ai profile.
	docker compose --profile ai up -d --wait --wait-timeout $(SETUP_WAIT_TIMEOUT) admin nginx ai-cpu
	# Then the rest (api-worker) without --wait: the worker serves no HTTP, its
	# healthcheck is disabled, and `up --wait` rejects a container without one.
	docker compose --profile ai up -d
	@echo ""
	@echo "✓ Setup complete!"
	@echo "Admin UI:  http://localhost  (nginx serves the SPA, /api and /sanctum on one origin;"
	@echo "           the bare Nuxt server on :3000 has no /api, so sign-in does not work there)"
	@echo "Login:     admin@example.com / password123 (seeded for APP_ENV=local)"
	@echo "API:       http://localhost/api"
	@echo "Mock AI:   http://localhost:8001 (ai-cpu)"
	@echo "RabbitMQ:  http://localhost:15672"

# Copy env template if not exists
.env:
	@echo "Creating .env from .env.example..."
	cp .env.example .env
	@sed -i.bak "s|^APP_KEY=$$|APP_KEY=base64:$$(openssl rand -base64 32)|" .env && rm -f .env.bak
	@echo "✓ .env created with a fresh APP_KEY."

# Browser acceptance run of ТЗ §75 against the stack that is already running
# (make setup): uploads a batch, moderates candidates, edits tags — it changes
# the stack's data. E2E_AI_OUTAGE=1 also stops/starts ai-cpu (§46).
# See tests/e2e/stack/README.md for the variables.
test-e2e:
	cd tests/e2e/stack && npm ci --no-audit --no-fund && npx playwright install chromium && npx playwright test

# Start all services
up:
	docker compose --profile ai up -d

# Start all services with ./apps/api and ./apps/admin bind-mounted for local
# editing (docker-compose.dev.yml) — see infra/README.md "Editing source
# without a rebuild".
up-dev:
	docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Start with AI CPU profile for testing
up-ai:
	docker compose --profile ai up -d

# Stop all services
down:
	docker compose down

# Run migrations
migrate:
	docker compose exec api php artisan migrate

# Seed database
seed:
	docker compose exec api php artisan db:seed

# Run all tests (§66). Each suite runs in a throwaway toolchain container
# against the checked-out source, so it works right after `git clone` without
# PHP/Node/Python on the host and without the stack running: the runtime images
# ship no dev dependencies (composer --no-dev, Nuxt .output only, no pytest).
# The whole checkout is mounted because tests read contracts/ from the repo
# root; anonymous volumes keep the container's vendor/node_modules/.nuxt off
# the host tree, and named volumes cache downloads between runs.
test: test-backend test-frontend test-ai

# apps/api image = PHP 8.4 with the same extensions as production; built
# straight from its Dockerfile so no .env/compose project is needed.
API_TOOLCHAIN_IMAGE := photobank-api-toolchain

# PHPUnit on SQLite with FakeAiClient (phpunit.xml), invoked like CI does:
# `php artisan test` would also want an apps/api/.env. APP_ENV is reset because
# the image sets APP_ENV=production and phpunit.xml does not force its value.
test-backend:
	docker build -t $(API_TOOLCHAIN_IMAGE) apps/api
	docker run --rm --user root --entrypoint sh -e APP_ENV=testing \
		-v "$(CURDIR):/repo" -v /repo/apps/api/vendor \
		-v photobank_composer_cache:/root/.cache/composer \
		-w /repo/apps/api $(API_TOOLCHAIN_IMAGE) \
		-c 'composer install --no-interaction --no-progress --quiet && vendor/bin/phpunit'

# Vitest unit tests; the mock-API Playwright suite runs in CI (apps/admin: npx playwright test).
test-frontend:
	docker run --rm \
		-v "$(CURDIR):/repo" -v /repo/apps/admin/node_modules -v /repo/apps/admin/.nuxt \
		-v photobank_npm_cache:/root/.npm \
		-w /repo/apps/admin node:22-alpine \
		sh -c 'npm ci --no-audit --no-fund --loglevel=error && npx nuxi prepare && npx vitest run'

# pytest with mock models only; real-model tests are opt-in via `-m model` (§73).
test-ai:
	docker run --rm -e PYTHONDONTWRITEBYTECODE=1 \
		-v "$(CURDIR):/repo" -v photobank_pip_cache:/root/.cache/pip \
		-w /repo/services/ai python:3.12-slim \
		sh -c 'pip install --quiet --disable-pip-version-check --root-user-action=ignore -r requirements-dev.txt && python -m pytest -q -p no:cacheprovider'

# Code quality checks
lint: lint-backend lint-frontend lint-ai

# Pint (laravel/pint is the backend's only configured linter; phpstan is not installed).
lint-backend:
	docker build -t $(API_TOOLCHAIN_IMAGE) apps/api
	docker run --rm --user root --entrypoint sh \
		-v "$(CURDIR):/repo" -v /repo/apps/api/vendor \
		-v photobank_composer_cache:/root/.cache/composer \
		-w /repo/apps/api $(API_TOOLCHAIN_IMAGE) \
		-c 'composer install --no-interaction --no-progress --quiet && vendor/bin/pint --test'

lint-frontend:
	docker compose exec admin npm run lint

lint-ai:
	docker compose --profile ai exec ai-cpu flake8 .
	docker compose --profile ai exec ai-cpu black --check .

# Run AI service natively on macOS with MPS (§8, §58). Runs outside Docker
# (host GPU access) while the rest of the stack stays in containers — see
# docker-compose.mac.yml and `make up` (with that overlay).
# Model cache (HF_HOME) defaults to a host-persistent directory, not the
# container-only /models path from .env, since this process is native.
ai-mac: AI_MAC_DEVICE = mps
ai-mac: _ai-mac-run

# CPU fallback for the native process (dev/smoke only per §58 — do not use
# for anything that needs production-quality output).
ai-mac-cpu: AI_MAC_DEVICE = cpu
ai-mac-cpu: _ai-mac-run

_ai-mac-run:
	@test -f .env || (echo "Run 'cp .env.example .env' first" && exit 1)
	@echo "Starting AI service natively on macOS ($(AI_MAC_DEVICE))..."
	@echo "Prerequisites: Python 3.12+, and inside services/ai:"
	@echo "  pip install -r requirements.txt -r requirements-model.txt"
	@echo "  Uses services/ai/.venv/bin/python if that venv exists, else python3 on PATH"
	@echo "Model cache: \$${AI_MAC_HF_HOME:-\$$HOME/.cache/photobank-ai/huggingface} (persists across restarts)"
	@echo "Starting server on http://localhost:8001"
	set -a; . ./.env; set +a; \
	mkdir -p "$${AI_MAC_HF_HOME:-$$HOME/.cache/photobank-ai/huggingface}"; \
	cd services/ai && \
	if [ -x .venv/bin/python ]; then PYTHON=.venv/bin/python; else PYTHON=python3; fi; \
	DEVICE=$(AI_MAC_DEVICE) \
	AI_MODEL_PROVIDER=transformers \
	AI_CONCURRENCY="$${AI_CONCURRENCY:-1}" \
	HF_HOME="$${AI_MAC_HF_HOME:-$$HOME/.cache/photobank-ai/huggingface}" \
	"$$PYTHON" -m uvicorn app.main:app --host 0.0.0.0 --port 8001 --reload

# Run AI service with CPU (for testing without GPU)
ai-test:
	docker compose --profile ai up ai-cpu

# Deployment check: liveness + readiness of a running AI service (native Mac,
# ai-cpu container, or a RunPod deployment). Usage:
#   make ai-health                                   # localhost:8001 (Mac/ai-cpu default)
#   make ai-health AI_URL=https://<pod-id>-8001.proxy.runpod.net
ai-health:
	@url="$${AI_URL:-http://localhost:8001}"; \
	if [ -f .env ]; then set -a; . ./.env; set +a; fi; \
	echo "Checking AI service at $$url ..."; \
	curl -sf "$$url/health" > /dev/null \
		&& echo "✓ /health (process is up)" \
		|| { echo "❌ /health failed"; exit 1; }; \
	if [ -n "$$AI_SERVICE_API_KEY" ]; then \
		curl -sf -H "Authorization: Bearer $$AI_SERVICE_API_KEY" "$$url/ready" > /dev/null \
			&& echo "✓ /ready (models loaded)" \
			|| echo "❌ /ready failed (still loading, or startup failed — see service logs; /ready takes no API key per ADR-0010, so this is never an auth mismatch)"; \
	else \
		curl -sf "$$url/ready" > /dev/null \
			&& echo "✓ /ready (models loaded)" \
			|| echo "❌ /ready failed (still loading)"; \
	fi

# View logs in follow mode
logs:
	docker compose logs -f

# View specific service logs
logs-api:
	docker compose logs -f api

logs-ai:
	docker compose --profile ai logs -f ai-cpu

logs-admin:
	docker compose logs -f admin

# Reset all containers and volumes (data loss!)
reset:
	@echo "WARNING: This will delete all containers and volumes!"
	@echo "All data will be lost."
	@read -p "Type 'yes' to confirm: " confirm; \
	if [ "$$confirm" = "yes" ]; then \
		docker compose down -v; \
		echo "✓ Reset complete"; \
	else \
		echo "Cancelled"; \
	fi

# Shell into API container
shell-api:
	docker compose exec api sh

# Shell into admin container
shell-admin:
	docker compose exec admin sh

# Database shell
db-shell:
	docker compose exec postgres psql -U $${DB_USERNAME} -d $${DB_DATABASE}

# RabbitMQ management console
rabbitmq-console:
	@echo "RabbitMQ Management UI: http://localhost:15672"
	@echo "Default credentials: guest / guest"

# Health check
health:
	@echo "Checking service health..."
	@curl -s http://localhost/health || echo "❌ API health check failed"
	@curl -s http://localhost:3000 > /dev/null && echo "✓ Admin UI is up" || echo "❌ Admin UI is down"
	@curl -s http://localhost:8001/health || echo "❌ AI service health check failed"
	@docker compose exec -T postgres pg_isready -U $${DB_USERNAME} > /dev/null && echo "✓ PostgreSQL is up" || echo "❌ PostgreSQL is down"

# --- Public cloud production profile ---------------------------------------
# Requires apps/api/Dockerfile and apps/admin/Dockerfile (owned by the
# backend/frontend agents), a populated .env (secrets via your host's
# secret store) and a TLS cert/key under NGINX_CERTS_DIR. See infra/README.md.

PROD_COMPOSE = docker compose -f docker-compose.yml -f docker-compose.prod.yml

prod-config:
	$(PROD_COMPOSE) config

prod-up:
	$(PROD_COMPOSE) up -d

prod-down:
	$(PROD_COMPOSE) down

prod-logs:
	$(PROD_COMPOSE) logs -f

# Edge health check (nginx's own /health, see infra/README.md). This only
# confirms nginx itself answers over TLS; there is no public API health path
# in contracts/public-api.openapi.yaml yet, so end-to-end readiness (DB,
# queue, storage) must be checked container-side with `make prod-logs` or
# `docker compose ... exec api php artisan ...` until one exists.
prod-health:
	@echo "Checking production edge health..."
	@curl -sf https://$${NGINX_SERVER_NAME:-localhost}/health > /dev/null && echo "✓ nginx is up" || echo "❌ nginx health check failed"

backup:
	./infra/scripts/backup.sh

restore:
	./infra/scripts/restore.sh $(SNAPSHOT)

# Clean build cache
clean-build:
	docker compose build --no-cache

# View container status
ps:
	docker compose ps

# Full cleanup (including images)
fclean: down
	docker system prune -a -f

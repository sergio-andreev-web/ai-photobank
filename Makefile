.PHONY: help setup up down migrate seed test lint ai-mac ai-test logs reset

help:
	@echo "AI Photobank — Development Commands"
	@echo ""
	@echo "Setup:"
	@echo "  make setup       Initialize development environment (one-time)"
	@echo ""
	@echo "Running:"
	@echo "  make up          Start all services"
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
	@echo ""
	@echo "AI Development:"
	@echo "  make ai-mac      Run AI service natively on macOS with MPS"
	@echo "  make ai-test     Run AI service with CPU for testing"
	@echo ""
	@echo "Maintenance:"
	@echo "  make reset       Reset all containers and volumes (data loss!)"

# First-time setup: compose up, migrations, seed
setup: .env
	@echo "Initializing development environment..."
	docker-compose up -d postgres redis rabbitmq
	@echo "Waiting for database..."
	@sleep 5
	docker-compose up -d api
	@echo "Running migrations..."
	docker-compose exec -T api php artisan migrate --force
	@echo "Seeding database..."
	docker-compose exec -T api php artisan db:seed
	docker-compose up -d
	@echo ""
	@echo "✓ Setup complete!"
	@echo "Frontend:  http://localhost:3000"
	@echo "Backend:   http://localhost/api"
	@echo "RabbitMQ:  http://localhost:15672"

# Copy env template if not exists
.env:
	@echo "Creating .env from .env.example..."
	cp .env.example .env
	@echo "✓ .env created. Edit it if needed, then run: make setup"

# Start all services
up:
	docker-compose up -d

# Start with AI CPU profile for testing
up-ai:
	docker-compose --profile ai up -d

# Stop all services
down:
	docker-compose down

# Run migrations
migrate:
	docker-compose exec api php artisan migrate

# Seed database
seed:
	docker-compose exec api php artisan db:seed

# Run all tests
test: test-backend test-frontend test-ai

test-backend:
	docker-compose exec api php artisan test

test-frontend:
	docker-compose exec admin npm run test

test-ai:
	docker-compose --profile ai exec ai-cpu pytest -v

# Code quality checks
lint: lint-backend lint-frontend lint-ai

lint-backend:
	docker-compose exec api ./vendor/bin/pint --test
	docker-compose exec api ./vendor/bin/phpstan analyse

lint-frontend:
	docker-compose exec admin npm run lint

lint-ai:
	docker-compose --profile ai exec ai-cpu flake8 .
	docker-compose --profile ai exec ai-cpu black --check .

# Run AI service natively on macOS with MPS
ai-mac:
	@echo "Starting AI service natively on macOS with MPS..."
	@echo "Prerequisites:"
	@echo "  - Python 3.12+ installed"
	@echo "  - PyTorch with MPS support installed"
	@echo "  - Run 'cd services/ai && pip install -r requirements.txt'"
	@echo ""
	@echo "Starting server on http://localhost:8001"
	cd services/ai && python -m uvicorn app.main:app --host 0.0.0.0 --port 8001 --reload

# Run AI service with CPU (for testing without GPU)
ai-test:
	docker-compose --profile ai up ai-cpu

# View logs in follow mode
logs:
	docker-compose logs -f

# View specific service logs
logs-api:
	docker-compose logs -f api

logs-ai:
	docker-compose --profile ai logs -f ai-cpu

logs-admin:
	docker-compose logs -f admin

# Reset all containers and volumes (data loss!)
reset:
	@echo "WARNING: This will delete all containers and volumes!"
	@echo "All data will be lost."
	@read -p "Type 'yes' to confirm: " confirm; \
	if [ "$$confirm" = "yes" ]; then \
		docker-compose down -v; \
		echo "✓ Reset complete"; \
	else \
		echo "Cancelled"; \
	fi

# Shell into API container
shell-api:
	docker-compose exec api sh

# Shell into admin container
shell-admin:
	docker-compose exec admin sh

# Database shell
db-shell:
	docker-compose exec postgres psql -U $${DB_USERNAME} -d $${DB_DATABASE}

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
	@docker-compose exec -T postgres pg_isready -U $${DB_USERNAME} > /dev/null && echo "✓ PostgreSQL is up" || echo "❌ PostgreSQL is down"

# Clean build cache
clean-build:
	docker-compose build --no-cache

# View container status
ps:
	docker-compose ps

# Full cleanup (including images)
fclean: down
	docker system prune -a -f

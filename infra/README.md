# Infrastructure

This directory contains all infrastructure configuration for the AI Photobank project.

## Structure

```
infra/
├── docker/           # Docker-related configurations
├── nginx/            # Nginx reverse proxy configuration
│   ├── nginx.conf    # Main Nginx configuration
│   └── conf.d/       # Virtual host configurations
└── README.md         # This file
```

## Quick Start

### 1. Setup (First Time)

```bash
# Copy environment template and run setup
cp .env.example .env
make setup
```

This will:
1. Start PostgreSQL, Redis, RabbitMQ
2. Start API container
3. Run database migrations
4. Seed initial data
5. Start all other services

### 2. Access Services

- **Admin UI**: http://localhost:3000
- **API**: http://localhost/api
- **RabbitMQ Console**: http://localhost:15672 (guest/guest)
- **PostgreSQL**: localhost:5432

### 3. Common Commands

```bash
make up              # Start all services
make down            # Stop all services
make logs            # View logs
make migrate         # Run migrations
make seed            # Seed database
make test            # Run all tests
make ai-mac          # Run AI natively on Mac with MPS
make ai-test         # Run AI with CPU for testing
```

## Development Modes

### Docker (Default)

Core services run in containers:

```bash
make up
```

The CPU AI service is optional (`docker compose --profile ai up -d`).

### macOS with MPS

For local Mac development with GPU acceleration:

```bash
# Start services (without AI)
docker-compose -f docker-compose.yml -f docker-compose.mac.yml up -d

# In another terminal, run AI natively
make ai-mac
```

API will connect to AI at `http://host.docker.internal:8001`.

### Cloud deployment

This base Compose setup is for development and contract testing. Public HTTPS,
secrets, persistent storage, deployment automation, and the CUDA AI host are
handled by the dedicated cloud and RunPod tasks. Do not expose this development
configuration directly to the Internet.

## Health Checks

All services include health checks. Monitor with:

```bash
make health
```

Service endpoints:
- **API**: `GET /health`
- **AI**: `GET /health` and `GET /ready`
- **PostgreSQL**: `pg_isready`
- **Redis**: `PING`
- **RabbitMQ**: `diagnostics`
- **Nginx**: `GET /health`

## Docker Compose Services

### Core Services

| Service | Image | Purpose |
| --- | --- | --- |
| `nginx` | `nginx:alpine` | Reverse proxy, media serving |
| `api` | Build: `apps/api` | Laravel backend |
| `api-worker` | Build: `apps/api` | Queue worker (RabbitMQ) |
| `admin` | Build: `apps/admin` | Nuxt frontend |

### Infrastructure

| Service | Image | Purpose |
| --- | --- | --- |
| `postgres` | `pgvector/pgvector:pg17` | Database with vector support |
| `redis` | `redis:7-alpine` | Cache & sessions |
| `rabbitmq` | `rabbitmq:3.13-management` | Message queue |

### AI (Optional Profile)

| Service | Image | Purpose |
| --- | --- | --- |
| `ai-cpu` | Build: `services/ai` | CPU-based AI for testing |

To run with AI:

```bash
docker-compose --profile ai up -d
```

## Networking

All services communicate through the `photobank` bridge network.

DNS resolution:
- Service name = hostname (e.g., `api` → `api:8000`)
- From Docker: `host.docker.internal:PORT` for host services

## Storage

### Volumes

| Volume | Purpose |
| --- | --- |
| `postgres_data` | PostgreSQL database files |
| `redis_data` | Redis persistence |
| `rabbitmq_data` | RabbitMQ state |
| `photobank_storage` | Image files, cache |
| `ai_models_cache` | AI model weights (HuggingFace) |

### File Structure

```
storage/app/photobank/
├── originals/        # Original uploaded images
│   └── 2026/09/42/   # Organized by date
├── variants/         # Thumbnails, previews
│   └── <image-id>/   # One folder per image
└── cache/            # Temporary processing files
```

## Nginx Configuration

See `infra/nginx/` for configuration files:

- **`nginx.conf`**: Main Nginx configuration
- **`conf.d/default.conf`**: Virtual hosts and routing

Key features:
- Reverse proxy to API and Admin
- Media file serving with `X-Accel-Redirect`
- Health check endpoint
- Gzip compression
- Security headers

## Environment Variables

Main configuration file: `.env`

See `.env.example` for all available variables and descriptions.

Key variables:
- `APP_ENV`: Environment (local, staging, production)
- `DB_*`: Database connection
- `AI_BASE_URL`: AI service endpoint
- `TAG_*_THRESHOLD`: ML model thresholds

## Database

PostgreSQL 17 with pgvector extension for vector search.

### Initialization

Migrations run automatically during `make setup`.

Manual migration:
```bash
make migrate
```

Seed data:
```bash
make seed
```

### Access

```bash
# Via Makefile
docker-compose exec postgres psql -U photobank -d photobank

# Or with make shortcut
make db-shell
```

## Logs and Debugging

### View Logs

```bash
make logs              # All services
make logs-api          # Specific service
make logs-admin
make logs-ai
```

### Shell Access

```bash
make shell-api         # API container
make shell-admin       # Admin container
make db-shell          # PostgreSQL
```

## Troubleshooting

### Port Already in Use

```bash
# Find process using port 80
lsof -i :80

# Kill process or use different port in .env
```

### Migrations Failed

```bash
# Check logs
make logs-api

# Roll back migrations
docker-compose exec api php artisan migrate:rollback

# Re-run setup
make migrate
```

### Database Won't Start

```bash
# Remove database volume and restart
docker-compose down -v
make setup
```

### AI Service Not Responding

```bash
# Check if container is running
docker-compose ps ai-cpu

# View AI logs
make logs-ai

# Test AI health
curl http://localhost:8001/health
```

## Reset and Clean Up

```bash
# Stop services
make down

# Remove all volumes (data loss!)
make reset

# Full cleanup including images
make fclean
```

## CI/CD Integration

For CI pipelines, use:

```bash
# Health check before tests
make health

# Run tests
make test

# Lint code
make lint
```

The infrastructure is designed to:
- Support isolated testing (no external dependencies)
- Use CPU-based models in CI (no GPU required)
- Match production (RunPod) with same contract

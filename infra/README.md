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
make ai-mac-cpu      # Same, but CPU fallback (dev/smoke only)
make ai-test         # Run AI with CPU for testing
make ai-health       # Check AI /health and /ready (AI_URL=... for RunPod)
```

## Development Modes

### Docker (Default)

Core services run in containers:

```bash
make up
```

The CPU AI service is optional (`docker compose --profile ai up -d`).

### Editing source without a rebuild

`docker-compose.yml` builds `api`/`api-worker`/`admin` from their images and
does not bind-mount source — on a clean checkout there is no host `vendor/`
or `.output/` to shadow the image's own, so a plain `make up`/`up` works
right after `git clone`. To bind-mount `./apps/api` and `./apps/admin` for
local editing instead, add the opt-in overlay:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

See the comments in `docker-compose.dev.yml` for what it does and does not
give you (no Nuxt hot-reload — that still needs `npm run dev` on the host).

### macOS with MPS

For local Mac development with GPU acceleration (§8, §58). The rest of the
stack (Postgres, Redis, RabbitMQ, Laravel, Nuxt, Nginx) still runs in Docker;
only the AI service runs natively so it can use Apple's MPS backend, which
isn't available inside Docker Desktop's Linux VM:

```bash
# Start services (without AI)
docker compose -f docker-compose.yml -f docker-compose.mac.yml up -d

# In another terminal: install real-model deps once, then run AI natively
cd services/ai && pip install -r requirements.txt -r requirements-model.txt && cd ../..
make ai-mac
```

API will connect to AI at `http://host.docker.internal:8001`. `make ai-mac`
sets `DEVICE=mps`, `AI_MODEL_PROVIDER=transformers`, `AI_CONCURRENCY=1`
(§58) and sources the project-root `.env` for the rest (`VISION_MODEL`,
`EMBEDDING_MODEL`, `AI_SERVICE_API_KEY`, ...), so the key matches what
`api`/`api-worker` send. If MPS isn't available, `make ai-mac-cpu` runs the
same profile on CPU — development/smoke only (§58), not representative of
real latency or (for `float16`) numeric behavior.

**Model cache**: since this process runs on the host, not in a container, its
HuggingFace cache is a host directory — `AI_MAC_HF_HOME` in `.env` (default
`~/.cache/photobank-ai/huggingface` if unset), *not* the container-only
`HF_HOME=/models` used by the `ai-cpu` Docker profile. `make ai-mac` creates
the directory and passes it as `HF_HOME` to the native process, so weights
persist across restarts (§68) instead of re-downloading every time.

**Deployment check**: `make ai-health` (defaults to `http://localhost:8001`)
checks `GET /health` then `GET /ready` (with the `Authorization: Bearer`
header if `AI_SERVICE_API_KEY` is set) — see `make ai-health` under
"AI Development" in `make help`.

### Cloud deployment

This base Compose file (`docker-compose.yml`) is for development and contract
testing — do not expose it directly to the Internet. `docker-compose.prod.yml`
is the public cloud production overlay described below, for the Laravel/Nuxt
side. AI hosting for that profile is a separate RunPod GPU Pod, not part of
any Compose file here — see `infra/runpod/README.md` for the full build →
push → create Pod → mount model cache → configure env/API key →
`AI_BASE_URL` walkthrough (§67), and `infra/runpod/.env.example` for the
Pod's environment variables. Only `AI_BASE_URL` and `AI_SERVICE_API_KEY`
change between the Mac and RunPod profiles — no Laravel code changes (§7).

## Public cloud production profile

`docker-compose.prod.yml` overlays `docker-compose.yml` for a single public
host (VM, VPS, cloud compute instance):

```bash
cp .env.example .env
# edit .env: APP_KEY, NGINX_SERVER_NAME, SESSION_DOMAIN,
# SANCTUM_STATEFUL_DOMAINS, DB/RabbitMQ passwords, AI_BASE_URL, etc.
# (populate secret values from your host's secret store, not by hand)
mkdir -p certs && cp /path/to/fullchain.pem /path/to/privkey.pem certs/
make prod-config   # validate the merged config
make prod-up
make prod-health
```

### What the profile changes vs. the base Compose file

- **HTTPS/TLS**: `nginx` terminates TLS on 443 using a cert/key mounted from
  `NGINX_CERTS_DIR` (see `infra/nginx/templates/production.conf.template`,
  rendered by the official nginx image's envsubst-on-templates entrypoint).
  Port 80 only redirects to HTTPS, serves `/health`, and has a spare
  `/.well-known/acme-challenge/` location for certbot-style renewal if you
  wire one up later.
- **Single origin**: Nuxt (`/`), Laravel API (`/api/`) and Sanctum
  (`/sanctum/`) are all served from one HTTPS origin, matching ADR-0001 — no
  CORS. `admin` and the database/queue services no longer publish host
  ports; only nginx's 80/443 are public.
- **Protected media**: `/_protected/` stays `internal` in nginx — reachable
  only via Laravel's `X-Accel-Redirect` after the backend authorizes a
  request, never served directly (§57, §63).
- **50 MiB upload limit**: `client_max_body_size` in nginx is driven by
  `NGINX_CLIENT_MAX_BODY_SIZE` (default `50m`), matching
  `PHOTO_MAX_UPLOAD_BYTES` (ADR-0005). The PHP-side `upload_max_filesize` /
  `post_max_size` in `apps/api`'s (not yet written) Dockerfile must be set to
  the same value — nginx alone does not raise PHP's own limit.
- **Secrets via env/secret store**: containers still read configuration via
  `${VAR}` substitution from the environment `docker compose` is invoked
  with. Nothing here stores secret *values* — populate `.env` (or inject
  environment variables directly) from your platform's secret manager
  (systemd `EnvironmentFile`, Docker/Swarm secrets, Vault, your cloud
  provider's secret panel, ...) at deploy time. Never commit `.env` or
  `certs/`.
- **Persistent volumes**: `postgres_data` and `photobank_storage` are named
  Docker volumes, unchanged from the base file — they persist across
  `up`/`down` as long as the host's Docker data root is on persistent disk.
- **Immutable containers**: the dev bind-mounts of `./apps/api` and
  `./apps/admin` source into the containers are dropped; only the built
  image and the persistent volumes are used.
- **AI stays external**: no `ai-cpu` service is started by this overlay.
  Point `AI_BASE_URL` at a RunPod (or other) HTTPS endpoint.

### Backup / restore

```bash
make backup                       # writes ./backups/<timestamp>/{db.dump,photos.tar.gz}
make restore SNAPSHOT=<timestamp> # destructive: restores DB + photo storage
```

Both run a one-off `postgres:17-alpine` container (`backup-tools`, profile
`backup`) against the already-running `postgres` service and the
`photobank_storage` volume — start the stack with `make prod-up` first.
`restore.sh` asks for an explicit `yes` confirmation before overwriting
anything. Back up before every deploy that runs new migrations.

### Health and readiness

- `GET /health` at the nginx edge (both HTTP and HTTPS) always returns `200
  OK` if nginx itself is reachable — it does not check the backend.
- `nginx` only starts once `api` and `admin`'s own healthchecks
  (`condition: service_healthy`) pass, which in turn depend on
  Postgres/Redis/RabbitMQ being healthy (base file, unchanged).
- **Known gap**: `contracts/public-api.openapi.yaml` does not define a
  public `/health` (or `/ready`) path under `/api`, so there is no
  contract-backed way to check Laravel's own DB/queue/storage readiness
  through the public origin. The container-internal check
  (`http://localhost:8000/health` inside the `api`/`api-worker`
  containers, not proxied by nginx) is the only signal available today. If
  end-to-end readiness needs to be checkable from outside the host, the
  contract owner needs to add a `/api/health` (or similar) path first —
  this infra task does not invent one.

### Dependencies not yet satisfied (blockers for actually running this profile)

- `apps/api/Dockerfile` does not exist yet (owned by the backend agent). The
  `api` and `api-worker` services build from `./apps/api`; until that
  Dockerfile lands, `docker compose build`/`up` for either profile fails at
  the build step. The Dockerfile must:
  - set `upload_max_filesize` and `post_max_size` in `php.ini` to at least
    `PHOTO_MAX_UPLOAD_BYTES`;
  - serve on port 8000 inside the container (both Compose files' `api`
    healthcheck and nginx's `upstream api { server api:8000; }` assume this);
  - respond `200` on `GET /up` (Laravel's own default health route) — this
    is the exact path both Compose files' `api` healthcheck probes
    (`curl -f http://localhost:8000/up`), not `/health`: nginx's `/health` is
    a separate static stub it answers itself, never proxied to `api`.
  - honor `QUEUE_CONNECTION=rabbitmq` (already set by both Compose files) and
    the `EMBEDDING_ACTIVE_MODEL_KEY`/`EMBEDDING_ACTIVE_MODEL_VERSION` env
    vars this infra task added — see root `.env.example` and
    `apps/api/config/photobank.php`/ADR-0009.
- `apps/admin/` does not exist yet (owned by the frontend agent, Nuxt app +
  Dockerfile). Same build-context dependency for the `admin` service.
- Both gaps already existed in the base Compose file
  (`infrastructure-base`); this task does not change that ownership
  boundary, only documents the exact production-profile impact.

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
| `postgres` | `pgvector/pgvector:0.8.0-pg17` | Database with vector support |
| `redis` | `redis:7-alpine` | Cache & sessions |
| `rabbitmq` | `rabbitmq:3.13-management` | Message queue |

### AI (Optional Profile)

| Service | Image | Purpose |
| --- | --- | --- |
| `ai-cpu` | Build: `services/ai` | CPU-based AI for testing |

To run with AI:

```bash
docker compose --profile ai up -d
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
| `ai_models_cache` | AI model weights (HuggingFace), `ai-cpu` Docker profile only |

Not a Docker volume, but the same role: `make ai-mac`/`make ai-mac-cpu` cache
weights under the host path `AI_MAC_HF_HOME` (§68) — see "macOS with MPS"
above. On RunPod it's a Network Volume mounted at `/models` — see
`infra/runpod/README.md`.

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
- `QUEUE_WORKER_TIMEOUT`: `api-worker`'s `queue:work --timeout`; must stay
  greater than `AI_REQUEST_TIMEOUT`
- `EMBEDDING_ACTIVE_MODEL_KEY` / `EMBEDDING_ACTIVE_MODEL_VERSION`: which
  embedding version is active for semantic search (ADR-0009)

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
docker compose exec postgres psql -U photobank -d photobank

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
docker compose exec api php artisan migrate:rollback

# Re-run setup
make migrate
```

### Database Won't Start

```bash
# Remove database volume and restart
docker compose down -v
make setup
```

### AI Service Not Responding

```bash
# Check if container is running (ai-cpu profile only — not the Mac native process)
docker compose ps ai-cpu

# View AI logs
make logs-ai

# Test AI liveness + readiness (works for ai-cpu, native Mac, or RunPod via AI_URL=)
make ai-health
```

For `make ai-mac` / `make ai-mac-cpu` there is no container to check — look at
the terminal running the command for uvicorn/model-loading errors. A `503`
from `/ready` right after startup is expected while weights load (§61); see
`infra/runpod/README.md` "Deployment checks" for the RunPod equivalent.

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

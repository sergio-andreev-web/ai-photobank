# RunPod deployment (§67)

Deploys the AI service (`services/ai`) — unmodified, same HTTP contract as the
Mac/CPU profiles (§67: "RunPod — только deployment target", no
`if runpod:` branching allowed in application code) — onto a RunPod GPU Pod.
This directory only documents deployment; it does not change
`services/ai/**` (owned by the AI agent) or Laravel (`apps/api/**`).

See also: `docs/technical-specification.md` §58 (RunPod profile defaults),
§67-§69, `services/ai/README.md` (provider/env reference), ADR-0010 (health,
auth, error contract).

## 1. Build the image

From the repo root, build the CUDA/`transformers` variant (`INSTALL_MODEL_DEPS=true`
pulls in `requirements-model.txt` — PyTorch + Transformers; the default build
without that flag is the CPU/mock image used for CI, not for RunPod):

```bash
docker build \
  --build-arg INSTALL_MODEL_DEPS=true \
  -t <registry>/<namespace>/ai-photobank-ai:cuda \
  services/ai
```

Model weights are **not** baked into the image (§68) — they download on
first start into `HF_HOME`, which is why step 4 (model cache volume) matters.

## 2. Push the image

Push to a registry RunPod can pull from (Docker Hub, GHCR, RunPod's own
registry, ...):

```bash
docker push <registry>/<namespace>/ai-photobank-ai:cuda
```

## 3. Create the Pod

In the RunPod console (or `runpodctl create pod` / GraphQL API), create a
Pod with:

- **Container image**: the pushed `ai-photobank-ai:cuda` image.
- **GPU**: any CUDA GPU with enough VRAM for the configured `VISION_MODEL`
  (§58: 8B by default, 4B allowed on smaller GPUs).
- **Container start command**: the image's default `CMD` already starts
  `uvicorn app.main:app --host 0.0.0.0 --port 8001` — no override needed.
- **Exposed HTTP port**: `8001` (see step 6).

## 4. Mount model cache

Attach a RunPod **Network Volume** (persists independently of the Pod, and
can be reattached after a Pod restart/recreate — §68: "На RunPod cache
должен располагаться на persistent/network volume при наличии") mounted at
`/models` inside the container. Set `HF_HOME=/models/huggingface` (see
`.env.example`) so weights land under that mount. Without this, every Pod
restart re-downloads multi-GB weights from HuggingFace.

A plain Pod-local volume (not a Network Volume) also persists across
container restarts on the *same* Pod, but is lost if the Pod itself is
deleted/recreated — prefer a Network Volume for anything beyond throwaway
testing.

## 5. Configure environment

Set the Pod's environment variables from `infra/runpod/.env.example` in this
directory (copy it, fill in real values, and paste into the RunPod console's
"Environment Variables" — RunPod Pods do not read `docker-compose.yml` or the
project-root `.env`). At minimum: `DEVICE=cuda`, `AI_MODEL_PROVIDER=transformers`,
`VISION_MODEL`, `EMBEDDING_MODEL`, `HF_HOME=/models/huggingface`,
`AI_SERVICE_API_KEY`, `AI_ENVIRONMENT=production`.

`AI_ENVIRONMENT=production` also disables `/docs`, `/redoc` and
`/openapi.json` (§63).

## 6. Expose the AI API

RunPod publishes exposed HTTP ports through its proxy at
`https://<pod-id>-8001.proxy.runpod.net` (TCP port mapping is also available
for a raw `host:port`, but the HTTPS proxy is simpler and matches "AI service
in production behind a service API key" from §63 — TLS is handled by RunPod's
edge, not by `services/ai` itself). Use that URL as `AI_BASE_URL` in step 8.

## 7. Configure the API key

Generate one secret and set it in **both** places — they must match exactly,
compared in constant time (ADR-0010):

```bash
openssl rand -hex 32
```

- RunPod Pod env: `AI_SERVICE_API_KEY=<the generated value>` (step 5).
- Laravel host: `AI_SERVICE_API_KEY=<the same value>` in the project-root
  `.env` consumed by `docker-compose.yml` / `docker-compose.prod.yml` (already
  wired into the `api` and `api-worker` services' environment blocks).

Never commit either value.

With `AI_ENVIRONMENT=production` (the image default) the service refuses to
start when `AI_SERVICE_API_KEY` is empty (ADR-0010): settings validation in
`services/ai/app/config.py` fails before uvicorn serves any request, so the
container exits with a configuration error naming `AI_SERVICE_API_KEY` and
`/health` never answers. An empty key disables auth only with
`AI_ENVIRONMENT=development` (local use).

## 8. Configure Laravel `AI_BASE_URL`

On the Laravel host's `.env` (used by `docker-compose.prod.yml`, see
`infra/README.md`):

```bash
AI_BASE_URL=https://<pod-id>-8001.proxy.runpod.net
AI_SERVICE_API_KEY=<same value as step 7>
```

No Laravel code changes are needed to switch between the Mac/CPU and RunPod
profiles (§7, §67) — only this configuration.

## Deployment checks

From a machine that can reach the RunPod proxy URL:

```bash
# from the repo root
make ai-health AI_URL=https://<pod-id>-8001.proxy.runpod.net
```

This checks, in order:

1. `GET /health` (unauthenticated liveness — process is up).
2. `GET /ready` (readiness — weights loaded, dimensions verified, self-check
   passed per §61). Like `/health`, `/ready` takes no API key
   (`security: []` in `contracts/ai-api.openapi.yaml`, confirmed against
   `services/ai/app/routers/health.py` — neither route has a
   `require_api_key` dependency); `make ai-health` sends the `Authorization:
   Bearer` header anyway when `AI_SERVICE_API_KEY` is set, but `/ready`
   ignores it. `503 MODEL_NOT_READY` is expected for a short window right
   after the Pod starts while weights download/load; it should turn `200`
   once loading finishes — a `503` that persists means startup failed
   (check Pod logs), not an auth problem.

Then confirm the Laravel side can reach it end-to-end: on the Laravel host,
`docker compose exec api php artisan tinker` and call the configured
`AiClientInterface::health()` implementation, or watch `api`/`api-worker`
logs (`make prod-logs`) while triggering an upload — an `AI_BASE_URL`/
`AI_SERVICE_API_KEY` mismatch surfaces as a `502`/`503` from the AI client
wrapping a `401` from the AI service.

## Switching Mac ↔ RunPod

Only `AI_BASE_URL` and `AI_SERVICE_API_KEY` change on the Laravel side (§7,
§67: "Никакой application logic между Mac и RunPod отличаться не должна").
See `infra/README.md` for the Mac MPS profile.

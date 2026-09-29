# AI Photobank — AI service

FastAPI implementation of `contracts/ai-api.openapi.yaml`. Owned by the AI/ML
agent (`services/ai/**`); see `docs/agent-tasks/03-ai-service.md` and
`.agents` before changing behavior here.

## Model providers

`AI_MODEL_PROVIDER` selects the implementation behind the
`VisionModel`/`EmbeddingModel` interfaces (`app/models/base.py`). The HTTP
response shape is identical for all of them.

| Provider | Models | Needs |
| --- | --- | --- |
| `mock` (default) | deterministic `app/models/mock.py` | `requirements.txt` only |
| `transformers` | SigLIP2 (`EMBEDDING_MODEL`) + Qwen3-VL (`VISION_MODEL`) | `requirements-model.txt`, weights in `HF_HOME` |

Layout of the real provider:

- `app/models/vision.py` — prompt, retries, deadline, pixel budget (no torch);
- `app/models/embedding.py` — lowercasing, count/dimension/finite checks, L2 norm (no torch);
- `app/models/vlm_output.py` — untrusted VLM text → `VisionResult` (only a root object with `caption`/`ocr`/`concepts`; output cut off at `AI_VLM_MAX_NEW_TOKENS` keeps the caption and fully formed items; unknown `type` → `other`, invalid items dropped; neither caption nor concepts → invalid);
- `app/models/transformers_backend.py` — the only torch/transformers code;
- `app/models/weights.py` — HF cache resolution; `*_revision` = resolved commit sha, or `sha256:` digest for a local weights directory (ADR-0009);
- `app/inference.py` — model failures → contract error codes.

## Profiles

```bash
# Mac (native, MPS) — §8, §58
AI_MODEL_PROVIDER=transformers DEVICE=mps VISION_MODEL=Qwen/Qwen3-VL-4B-Instruct AI_CONCURRENCY=1

# CUDA / RunPod — §58, §67 (4B is also allowed)
AI_MODEL_PROVIDER=transformers DEVICE=cuda VISION_MODEL=Qwen/Qwen3-VL-8B-Instruct

# CPU — development/smoke only (§58); logs ai_service.cpu_inference warning
AI_MODEL_PROVIDER=transformers DEVICE=cpu
```

`DEVICE=cuda|mps` must actually be available, otherwise startup fails and
`/ready` stays 503 (no silent CPU fallback). `DEVICE=auto` picks cuda → mps → cpu.

On MPS the service sets `HF_DEACTIVATE_ASYNC_LOAD=1` before loading weights:
Transformers 5.x otherwise copies tensors from a thread pool, which
intermittently segfaults on MPS. An explicitly set non-empty value is kept.

## Startup and readiness

With real models, weights are resolved/loaded on a background thread:
`/health` answers 200 immediately, `/ready` returns 503 `MODEL_NOT_READY`
until models are loaded, dimensions are verified (1152) and a self-check on a
probe image passes (§61, ADR-0010). A failed startup is logged as
`ai_service.startup_failed` and keeps `/ready` at 503.

## Weights cache (§68)

Weights are never part of the image. They are downloaded once into
`HF_HOME` (Docker default `/models/huggingface`; mount a persistent volume on
`/models`). After the first download set `HF_HUB_OFFLINE=1` to start without
network access. Pin exact weights with `VISION_MODEL_REVISION` /
`EMBEDDING_MODEL_REVISION` (branch, tag or commit; default `main`).
`VISION_MODEL`/`EMBEDDING_MODEL` may also point to a local directory.

## Memory, timeouts, failures

| Variable | Default | Effect |
| --- | --- | --- |
| `DTYPE` | `auto` | `auto`: cuda → bfloat16 (float16 without bf16 support), mps → bfloat16, cpu → float32. Use `DTYPE=float16` on macOS < 14 |
| `AI_CONCURRENCY` | `1` | parallel inferences; extra requests get 429 `SERVICE_BUSY` |
| `AI_VLM_MAX_PIXELS` | `1048576` | image area given to the VLM (bounds visual tokens / memory) |
| `AI_VLM_MAX_NEW_TOKENS` | `1024` | generation length cap per attempt |
| `AI_VLM_OUTPUT_ATTEMPTS` | `2` | attempts (greedy, then sampled) before 502 `MODEL_OUTPUT_INVALID` |
| `AI_INFERENCE_TIMEOUT_SECONDS` | `120` | wall-clock budget per analysis incl. retries; generation stops cooperatively |

Failure mapping (all retryable per contract):

- VLM output not parseable, or empty (no caption and no concepts), after all
  attempts → 502 `MODEL_OUTPUT_INVALID`;
- timeout, out-of-memory (caches are released), NaN/zero embeddings or any
  other model exception → 500 `INFERENCE_FAILED`.

## Run locally

```bash
python3.12 -m venv .venv
.venv/bin/pip install -r requirements-dev.txt          # mock profile
.venv/bin/pip install -r requirements-model.txt        # + real models
.venv/bin/uvicorn app.main:app --port 8001
```

## Tests

```bash
.venv/bin/pytest            # no GPU, weights or transformers required (§73)
.venv/bin/pytest -m model   # real SigLIP2 + Qwen3-VL inference; profile from env
```

`tests/test_transformers_backend.py` runs when torch is installed (fake
modules, no weights). `-m model` downloads weights on first run;
`AI_MODEL_TEST_LOAD_TIMEOUT` (seconds, default 3600) bounds model loading.

## Docker

```bash
docker build -t ai-photobank-ai services/ai                                       # CPU / mock
docker build --build-arg INSTALL_MODEL_DEPS=true -t ai-photobank-ai:cuda services/ai  # CUDA
```

## Configuration

See docs/technical-specification.md §58, §60, §69 for the shared variables
(`DEVICE`, `DTYPE`, `VISION_MODEL`, `EMBEDDING_MODEL`, `HF_HOME`,
`PROMPT_VERSION`, `AI_SERVICE_API_KEY`, ...). Defaults live in `app/config.py`.

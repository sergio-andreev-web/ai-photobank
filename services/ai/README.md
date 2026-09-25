# AI Photobank — AI service

FastAPI implementation of `contracts/ai-api.openapi.yaml`. Owned by the AI/ML
agent (`services/ai/**`); see `docs/agent-tasks/03-ai-service.md` and
`.agents` before changing behavior here.

## Scope of this build

This task ("ai-mock") implements the full contract surface (`/health`,
`/ready`, `/v1/images/analyze`, `/v1/embeddings/text`), Pydantic schemas,
image preprocessing, security, and **deterministic mock models**
(`app/models/mock.py`). Real SigLIP2/Qwen3-VL integration behind the same
`VisionModel`/`EmbeddingModel` interfaces (`app/models/base.py`) is a
follow-up task and is intentionally not implemented — `AI_MODEL_PROVIDER`
only accepts `mock` today; any other value fails startup loudly instead of
silently pretending to be a real deployment.

## Run locally

```bash
python3.12 -m venv .venv
.venv/bin/pip install -r requirements-dev.txt
.venv/bin/uvicorn app.main:app --reload --port 8001
```

## Tests

```bash
.venv/bin/pytest            # no GPU/model weights required (§73)
.venv/bin/pytest -m model   # real-model tests — not implemented yet, none collected
```

## Configuration

See docs/technical-specification.md §58, §60, §69 for the full list of
environment variables (`DEVICE`, `VISION_MODEL`, `EMBEDDING_MODEL`, `HF_HOME`,
`PROMPT_VERSION`, `AI_SERVICE_API_KEY`, ...). Defaults live in
`app/config.py`.

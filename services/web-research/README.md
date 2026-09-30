# Universal object web research

Contract 1.11.0 / ADR-0016. Node 22+, Playwright Chromium. The browser uploads the job's preview
into Google Lens, then reads agent-selected public sources. No category-specific identification rules.
MCP stdio exposes `inspect_image`, `visual_search`, `read_source` through a per-job capability;
no arbitrary file upload, shell or database tools. API/Ollama adapters use the same tool handlers.
CAPTCHA or changed search UI returns `WEB_SEARCH_BLOCKED`; never bypasses it.

## Native Mac (CLI MVP)

Generate `WEB_RESEARCH_TOKEN` (at least 24 random characters) in root `.env`; Laravel and service
must use the same value. Set `WEB_RESEARCH_BASE_URL=http://host.docker.internal:8002`,
`WEB_RESEARCH_HOST=0.0.0.0`, `OLLAMA_BASE_URL=http://127.0.0.1:11434` for native Ollama.

```sh
make research-install
make research-native
# Separate terminal; CLI credentials must already be set up using each CLI's normal login.
ollama pull qwen3.5:4b
```

Use Settings to choose Codex CLI / Claude CLI / OpenAI API / Anthropic API / Ollama.
API providers need their API key AND explicit `WEB_RESEARCH_OPENAI_MODEL` /
`WEB_RESEARCH_ANTHROPIC_MODEL`. CLI model overrides are optional. `/ready` reports installed
CLI/configured API endpoints; it does not guarantee login, credits or downloaded model.
Native CLI MCP configs are temporary and never modify the user's CLI configuration.
Codex explicitly approves only these three job-scoped tools; shell remains disabled and sandbox read-only.

## Linux / website deployment

The UI calls only Laravel; research and models are internal services. Set the shared token and
`WEB_RESEARCH_BASE_URL=http://web-research:8002`; use an existing Ollama endpoint or start the
included model service. No Apple MPS dependencies, no model weights in Git.

```sh
# API providers or existing Ollama HTTP endpoint:
make research-up
# Included Ollama on CPU (downloads ~3.4 GB of model weights):
make research-local-up
# NVIDIA host with NVIDIA Container Toolkit:
docker compose -f docker-compose.yml -f docker-compose.research-gpu.yml --profile research --profile research-local up -d --build web-research ollama research-worker
docker compose exec ollama ollama pull qwen3.5:4b
```

Ollama model cache uses the `ollama_models` volume; neither model nor research port is published
by Compose. Website TLS/auth remain handled by the existing nginx/Laravel deployment. CLI adapters
are intended for the native MVP; container has API and Ollama adapters, no user CLI credentials.
Automatic research defaults off and only applies to new ready images; manual research always available.
Results are suggestions with evidence, never automatically assigned tags or captions. Research has
its own `images.research` worker; image processing remains independent. An unavailable model/search
fails research only. Manual retry explicitly creates a new upload; there are no hidden automatic retries.

## Checks

`npm test` mocks model/network responses and checks agent tool loops, request/result validation,
HTTP authentication and local-network restrictions. Live Lens and actual model accuracy must be
checked separately; search site markup and bot controls can change. A 4B model can confuse rare
objects; use uncertainty and review sources. The app does not identify people through face search.

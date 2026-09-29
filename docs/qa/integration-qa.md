# Integration QA handoff — §75 MVP

**Package:** `integration-qa` (QA owner). **Contract:** accepted public and AI OpenAPI `1.2.x`, JSON Schemas and `contracts/state-machines.md`. The QA files do not change an inter-module API. **Done when:** the focused contract suite and the Laravel HTTP-to-queue acceptance suite pass, and the clean `make setup` stack passes the §75 scenario in a browser through Nuxt, Laravel and the HTTP mock AI service (CI job `acceptance`).

## Checks and scope

| Check | File | Boundary and assertions |
| --- | --- | --- |
| Contract guard | `tests/contracts/test_mvp_boundaries.py` | Accepted operations/statuses for login, upload/duplicate, progress, retry/reprocess, moderation and search; strict AI analysis (1152 dimensions, no canonical `tag_id`); search mode request rules; public error examples; Nuxt API call paths and tag list page size. |
| §75 acceptance | `tests/e2e/MvpAcceptanceTest.php` | Public Laravel routes, real storage/pipeline/resolver/search, explicit queue drain, deterministic `FakeAiClient`: login, batch of 20, progress, duplicate, caption/AI tag, manual edit, candidate approval, tag/semantic/hybrid search, suppression and reprocess history. |
| Failure/recovery | Same PHP test | Upload completes with AI offline; failed processing and batch counters; tag search still works; semantic returns 503 `AI_SERVICE_UNAVAILABLE` with trace ID; retry recovers; retry of ready image returns 409. |
| §75 in the browser | `tests/e2e/stack/specs/section-75.spec.ts` | Clean `make setup` stack, nothing mocked (browser → nginx → Nuxt/Laravel → RabbitMQ worker → PostgreSQL/pgvector → `ai-cpu` mock AI): seeded admin login, batch of 20 generated PNGs, progress to `completed` `20 / 20`, candidate thumbnails via `/api/media`, **Create Tag** ×2 and **Merge Into...** with alias, image from the gallery with caption and `(ai)` tags, AI tag removed and another tag added as `(manual)` (kept after reload), tags-only search (hard filter, all tagged images of the run), the §75 query with Debug (`semantic_score` and `Score:` non-increasing, same order in UI and API). |
| AI outage in the browser | `tests/e2e/stack/specs/ai-outage.spec.ts` (`E2E_AI_OUTAGE=1`) | `docker compose stop ai-cpu`: semantic search → 503 `AI_SERVICE_UNAVAILABLE`, `X-Request-ID` = `trace_id`, UI `AI service unavailable` + **Retry search**; tag search in another tab still returns results; `docker compose start ai-cpu` + `/ready` → **Retry search** returns results. |
| Upload generator | `tests/e2e/stack/specs/png.spec.ts` | PNG structure and CRCs, 20 distinct files and pixel buffers per run nonce (SHA-256 dedup includes soft-deleted images; the mock AI hashes decoded pixels). |

The PHP suite uses the real backend contracts and in-process `FakeAiClient`. Neither suite asserts model quality: fake and mock embeddings are hash-based, so they verify ordering and filtering, not semantic relevance.

The browser suite runs steps 11–12 (moderation) before 6–10: `DatabaseSeeder` creates only the admin, so on a clean stack every mock concept is a pending candidate and images get AI tags only when a candidate is approved or merged (ADR-0008, `source=ai`). It reads the public API with the SPA's own fetch only to pick and cross-check data; every §75 action goes through the UI. It changes the stack's data (see `tests/e2e/stack/README.md`) and needs mock concepts that are not canonical tags yet, i.e. a fresh `make setup`.

## Run

From the repository root:

```sh
python3 -m unittest discover -s tests/contracts -v
python3 -m unittest discover -s tests/orchestrator -v
python3 contracts/tools/validate_contracts.py
php -l tests/e2e/MvpAcceptanceTest.php
cd apps/api && ./vendor/bin/phpunit -c phpunit.xml ../../tests/e2e/MvpAcceptanceTest.php
```

Browser acceptance on a fresh stack (Node 22; changes the stack's data):

```sh
make setup
make test-e2e                      # tests/e2e/stack: npm ci, Chromium, npx playwright test
E2E_AI_OUTAGE=1 make test-e2e      # also stops/starts ai-cpu
```

In CI (`.github/workflows/ci.yml`) the contract guard and orchestrator suites run in **Health Check**, `MvpAcceptanceTest.php` in **Backend Tests (SQLite)**, and the job **§75 Acceptance: make setup + browser e2e** builds the images, runs literally `make setup` on the clean checkout, checks that postgres/redis/rabbitmq/api/admin/nginx/ai-cpu are healthy and api-worker is running, then runs `tests/e2e/stack` with `E2E_AI_OUTAGE=1`. On failure it uploads the Playwright report (trace, screenshot, video, `stack-state.json`) and `docker compose logs` plus the Laravel log as the `section-75-acceptance` artifact. The job is informational (`continue-on-error: true`) until gating is decided.

The last command needs `apps/api/vendor/` from Composer, PHP GD/SQLite extensions, and the test environment in `apps/api/phpunit.xml`; it uses no GPU, broker, or running AI server. Keep the PHPUnit file outside `apps/api/tests` because QA owns `tests/e2e` only. Do not run it against a persistent database: `RefreshDatabase` resets its test database.

**Observed in this worktree:** the contract suite ran 6 tests, with 5 passing and the Nuxt `per_page` guard failing as described below. `contracts/tools/validate_contracts.py` passed (31 public operations, 4 AI operations, 6 schemas, 20 examples); `php -l` passed. Since the worktree has no `apps/api/vendor/`, the PHP suite was run from an isolated copy under `/private/tmp` with Composer packages installed from the local cache: **2 tests, 204 assertions, 1 failure** at the final `latest_analysis` assertion. The outage/retry test passed, and the §75 test passed its earlier flow and search assertions. The existing backend `HttpAiClientTest.php` also passed there (16 tests, 17 assertions), covering HTTP request/response handling at the AI boundary with fake HTTP responses. Docker socket access was denied, so the deployed Compose and browser checks remain unrun.

The deployed checks listed here as open (a clean `make setup`, the §75 path in the Nuxt UI with 20 distinct images and the HTTP mock AI, an AI outage with tag search and retry) are now automated by `tests/e2e/stack`; see the last section for observed results.

## Incompatibilities to route

| Owner | Evidence | Required resolution |
| --- | --- | --- |
| `infrastructure-base` | `Makefile` `setup` ends with plain `docker-compose up -d`; `docker-compose.yml` puts `ai-cpu` behind the `ai` profile. `apps/api/config/ai.php` defaults `AI_DRIVER=http`, while `.env.example` does not set `AI_DRIVER`. A clean setup therefore leaves Laravel pointing at an AI container that is not started. | Make clean setup provide a reachable mock/CPU AI service, or set the backend fake driver explicitly, consistent with §70 and the package-05 brief. |
| `infrastructure-base` | `.env.example` sets `EMBEDDING_ACTIVE_MODEL_KEY=siglip2-so400m-patch14-384` and `EMBEDDING_ACTIVE_MODEL_VERSION=mock-embedding-v1`. The mock AI provider declares `MockEmbeddingModel.name=mock-embedding` and `revision=mock-embedding-v1`. The backend checks both values in `EmbeddingVersion::isActive`; the default key does not match. | Align both active-model values with the selected mock AI model. Otherwise image embeddings stay inactive, semantic results are empty, and unmatched concept resolution can fail. |
| `admin-frontend-flows` | `apps/admin/pages/tags.vue` requests `GET /api/admin/tags?per_page=1000`; `contracts/public-api.openapi.yaml` caps this parameter at 500, and `TagController::index()` validates `max:500`. The new contract guard fails 1000 ≤ 500. | Page through the catalog or use a permitted page size; keep the tree complete when there are more than 500 tags. |
| `backend-search` / backend integration owner | In the frozen-time reprocess check, `image_analyses` contains two rows for the image (IDs 1 and 21), but `GET /api/admin/images/{image}` reports `latest_analysis.id=1`. `ImagePresenter::detail()` sorts analyses only by `created_at`; equal timestamps select the old row. The §54/§76 card must show the new analysis. | Add an ID tie-break when choosing the latest analysis (or query by descending ID), and rerun `MvpAcceptanceTest.php`. |
| `integration-qa` execution environment | Docker daemon socket access is denied; `apps/admin/node_modules/` is absent in the worktree. | Re-run the deployed/browser checks in an environment with Docker and frontend dependencies. The isolated PHP suite runs, with the backend failure above. |

No API contract inconsistency was found in the acceptance paths inspected. Contract-owned files remain unchanged.

## Resolution (2026-09-25)

| Finding | Fix |
| --- | --- |
| Nuxt tag page `per_page=1000` | #35 — the catalog is loaded page by page with `per_page=500`. |
| Stale `latest_analysis` after reprocess | #34 — latest analysis is chosen by `created_at desc, id desc`. |
| `make setup` without AI | #37 — `make setup` and `make up` start the `ai` profile (CPU/mock AI behind `AI_BASE_URL`). |
| Active embedding key vs. mock AI | #32 — `.env.example` defaults to `mock-embedding` / `mock-embedding-v1`; real-model values are documented next to them. |

Re-run on `main` with these fixes: the contract guard passes 6 of 6, and `MvpAcceptanceTest.php` passes 2 tests, 204 assertions on both SQLite and PostgreSQL 17 + pgvector 0.8.0. The browser-driven Nuxt ↔ Laravel ↔ HTTP mock AI run on a deployed Compose stack was still open then; see below.

## Browser acceptance follow-up (2026-09-29)

The Playwright suite in `tests/e2e/stack/` now exercises §75 and the §46 AI outage/recovery path through nginx, Nuxt, Laravel, RabbitMQ, PostgreSQL/pgvector and the HTTP mock AI. On an isolated Compose project with fresh volumes, `make setup` and `npx playwright test` with `E2E_AI_OUTAGE=1` passed **7/7 tests** (20 uploaded images). The admin build was mounted from the current combined worktree, and AI was rebuilt from it. Docker Hub stalled while resolving the PHP base image, so the API and worker used previously built images; this run does not certify a fresh API image build. The original local Compose project and its data were not used.

The run exposed and fixed two integration defects: a fresh `photobank_storage` volume was not writable by Laravel's UID 33 (`storage-init` now sets its root ownership before API startup), and mock AI reported the configured real embedding name instead of its actual `mock-embedding` model (the service now reports loaded provider names in analysis, embeddings and readiness responses). The contract guard was also updated after the tag catalog moved behind `useTags.listAllTags()`, and a batch-progress browser assertion was corrected for Playwright's concatenated text nodes.

The acceptance CI job is now required rather than informational. A CI run on a clean runner remains the check for fresh API image builds and the unmodified default Compose ports.

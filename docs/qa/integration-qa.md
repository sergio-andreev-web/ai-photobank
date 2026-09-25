# Integration QA handoff — §75 MVP

**Package:** `integration-qa` (QA owner). **Contract:** accepted public and AI OpenAPI `1.2.0`, JSON Schemas and `contracts/state-machines.md`. The QA files do not change an inter-module API. **Done when:** the focused contract suite and the Laravel HTTP-to-queue acceptance suite pass, then the clean Compose setup is exercised through Nuxt and the HTTP mock AI service.

## Checks and scope

| Check | File | Boundary and assertions |
| --- | --- | --- |
| Contract guard | `tests/contracts/test_mvp_boundaries.py` | Accepted operations/statuses for login, upload/duplicate, progress, retry/reprocess, moderation and search; strict AI analysis (1152 dimensions, no canonical `tag_id`); search mode request rules; public error examples; Nuxt API call paths and tag list page size. |
| §75 acceptance | `tests/e2e/MvpAcceptanceTest.php` | Public Laravel routes, real storage/pipeline/resolver/search, explicit queue drain, deterministic `FakeAiClient`: login, batch of 20, progress, duplicate, caption/AI tag, manual edit, candidate approval, tag/semantic/hybrid search, suppression and reprocess history. |
| Failure/recovery | Same PHP test | Upload completes with AI offline; failed processing and batch counters; tag search still works; semantic returns 503 `AI_SERVICE_UNAVAILABLE` with trace ID; retry recovers; retry of ready image returns 409. |

The PHP suite uses the real backend contracts and in-process `FakeAiClient`. It does not assert model quality: fake embeddings can verify ordering and filtering, but not semantic relevance. A browser-driven Nuxt ↔ Laravel acceptance run and a deployed Laravel ↔ HTTP mock AI run are still needed after the Compose setup issue below is fixed.

## Run

From the repository root:

```sh
python3 -m unittest discover -s tests/contracts -v
python3 contracts/tools/validate_contracts.py
php -l tests/e2e/MvpAcceptanceTest.php
cd apps/api && ./vendor/bin/phpunit -c phpunit.xml ../../tests/e2e/MvpAcceptanceTest.php
```

The last command needs `apps/api/vendor/` from Composer, PHP GD/SQLite extensions, and the test environment in `apps/api/phpunit.xml`; it uses no GPU, broker, or running AI server. Keep the PHPUnit file outside `apps/api/tests` because QA owns `tests/e2e` only. Do not run it against a persistent database: `RefreshDatabase` resets its test database.

**Observed in this worktree:** the contract suite ran 6 tests, with 5 passing and the Nuxt `per_page` guard failing as described below. `contracts/tools/validate_contracts.py` passed (31 public operations, 4 AI operations, 6 schemas, 20 examples); `php -l` passed. Since the worktree has no `apps/api/vendor/`, the PHP suite was run from an isolated copy under `/private/tmp` with Composer packages installed from the local cache: **2 tests, 204 assertions, 1 failure** at the final `latest_analysis` assertion. The outage/retry test passed, and the §75 test passed its earlier flow and search assertions. The existing backend `HttpAiClientTest.php` also passed there (16 tests, 17 assertions), covering HTTP request/response handling at the AI boundary with fake HTTP responses. Docker socket access was denied, so the deployed Compose and browser checks remain unrun.

After the infrastructure owner fixes the defaults, run `make setup` from a clean checkout, then verify the same §75 path in the Nuxt UI with a seeded admin, 20 distinct images and the HTTP mock AI service. Poll batch and image status to completion. Check both `GET /api/admin/batches/{batch}` progress and the UI display. Confirm that a network outage of the AI service leaves upload and tag search available, then restore it and retry a failed image. Record observed results and timings separately; those deployed checks could not be run in this worktree.

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

Re-run on `main` with these fixes: the contract guard passes 6 of 6, and `MvpAcceptanceTest.php` passes 2 tests, 204 assertions on both SQLite and PostgreSQL 17 + pgvector 0.8.0. The browser-driven Nuxt ↔ Laravel ↔ HTTP mock AI run on a deployed Compose stack is still open.

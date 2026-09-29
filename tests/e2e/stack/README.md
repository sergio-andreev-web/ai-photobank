# Stack e2e — ТЗ §75 in a browser on the running stack

Playwright run of the §75 scenario against a stack started by `make setup`: browser → nginx → Nuxt SPA and
Laravel → RabbitMQ worker → PostgreSQL + pgvector → mock AI (`ai-cpu`, `services/ai/app/models/mock.py`).
Nothing is mocked; unlike `apps/admin/tests/e2e` there is no `page.route` and no dev server.

**It changes the stack's data.** Each run uploads a new batch of 20 generated PNGs, creates tags from two
candidates, merges a third candidate into one of them (with an alias), removes an AI tag from one image and adds
a tag to it manually. With `E2E_AI_OUTAGE=1` it also stops and restarts the `ai-cpu` container. Run it on a
fresh stack, never on one whose data you need.

## Run

```sh
make setup      # clean stack: .env, containers, migrations, seeded admin, mock AI
make test-e2e   # npm ci + Chromium + the run below

cd tests/e2e/stack && npm ci && npx playwright install chromium   # CI on Linux: --with-deps chromium
npx playwright test                    # unit + acceptance (+ ai-outage when E2E_AI_OUTAGE=1)
E2E_AI_OUTAGE=1 npx playwright test
npx playwright test --project=unit     # PNG generator only, no stack needed
npx playwright show-report             # HTML report of the last run
```

Projects: `unit` (`specs/png.spec.ts`), `acceptance` (`specs/section-75.spec.ts`, one serial test whose
steps are numbered as in §75) and `ai-outage` (`specs/ai-outage.spec.ts`, depends on `acceptance`; alone:
`--project=ai-outage --no-deps`).

## What it checks

- **§75:** login; new batch; 20 unique PNGs (generated per run: uploads are deduplicated by SHA-256, soft-deleted
  images included); batch progress; wait for `completed` and `20 / 20`; tag candidates with this run's images
  (thumbnails must load through `/api/media`): **Create Tag** twice, **Merge Into...** a third one with alias;
  open an image from the gallery; caption; `(ai)` tags; remove one; add the other new tag, `(manual)`, kept
  after reload; tags-only search (every hit carries the tag, all of this run's tagged images found); the query
  `мужчина в каске на промышленном объекте` with Debug: `semantic_score` in the response and `Score:` on the
  page are non-increasing and in the same order.
- Steps 11–12 run before 6–10: the seeder creates only the admin, so the taxonomy is empty, every mock concept
  becomes a candidate and images get AI tags only when a candidate is approved or merged (ADR-0008, `source=ai`).
- The spec reads the public API with the SPA's own fetch to pick and cross-check data; every §75 action goes
  through the UI.
- **§46 (`E2E_AI_OUTAGE=1`):** `docker compose stop ai-cpu` → semantic search shows `AI service unavailable` and
  `Retry search` (503 `AI_SERVICE_UNAVAILABLE`, `X-Request-ID` equals `trace_id`), tag search in a second tab
  still works → `docker compose start ai-cpu`, wait for `/ready` → **Retry search** returns results. The
  container is restarted even if the test fails.

On failure the report keeps the trace, screenshot, video and `stack-state.json` (status, tasks and tags of this
run's images). On a stack where earlier runs already turned the mock concepts into tags there may be fewer than
three pending candidates with new images; the test says so — use a fresh `make setup`.

## Environment

| Variable | Default | Purpose |
| --- | --- | --- |
| `E2E_BASE_URL` | `http://localhost` | nginx origin (Nuxt alone on `:3000` has no `/api`) |
| `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD` | `admin@example.com` / `password123` | account from `DatabaseSeeder` |
| `E2E_IMAGE_COUNT` | `20` | uploads per run (fewer only for a quick local smoke) |
| `E2E_PROCESSING_TIMEOUT_MS` | `480000` | wait for the batch's final status |
| `E2E_TEST_TIMEOUT_MS` | `1200000` | §75 test timeout |
| `E2E_AI_OUTAGE` | unset | `1` enables `ai-outage` |
| `E2E_AI_SERVICE` | `ai-cpu` | Compose service to stop |
| `E2E_AI_READY_URL` / `E2E_AI_READY_TIMEOUT_MS` | `http://localhost:8001/ready` / `180000` | AI readiness after restart |
| `E2E_COMPOSE_DIR` | repository root | Compose project directory for `docker compose` |

Requires Node 22; `ai-outage` also needs `docker compose` on the machine that runs the stack.

# ADR-0010: Контракт AI service — health/ready, ошибки, лимиты, API key, concepts

- Статус: Accepted
- Дата: 2026-09-25
- Затрагивает: AI, backend (AiClient, FakeAiClient), infra (health checks, секреты); `ai-api.openapi.yaml`,
  `schemas/ai-error.json`, `schemas/concepts.json`

## Контекст

§30 задает форму `/health`; §61 — последовательность startup и отдельный `/ready`, но также фразу «только затем
`/health` возвращает ready». §63 — service API key в production, AI output валидируется JSON Schema, `/docs`
запрещен/защищен. Формат ошибок AI, лимиты входа, enum `concept.type` и язык labels не заданы.

## Рекомендация

Health/readiness:

- `/health` — liveness: 200 `{status: "ok", device, models}`, пока процесс жив, даже во время загрузки моделей.
  Используется для перезапуска контейнера.
- `/ready` — readiness: 200 `{status: "ready", device, models, embedding_dimensions: 1152, prompt_version}` после
  шагов §61; иначе 503 `MODEL_NOT_READY` с `Retry-After`. Используется compose healthcheck зависимостей,
  `AiClientInterface::health()` и решением backend о 503 для semantic search.
- Обе без API key.

Аутентификация: `Authorization: Bearer <AI_SERVICE_API_KEY>` (альтернатива — `X-API-Key`). Если ключ в AI не задан
(локальная разработка) — проверка выключена; в production пустой ключ — ошибка конфигурации на startup. Сравнение
в постоянное время. `/docs`, `/redoc`, `/openapi.json` выключены при `APP_ENV=production`.

Ошибки: все не-2xx — `schemas/ai-error.json`, включая ошибки валидации (обработчик FastAPI переопределен).
Повторяемость — по HTTP-статусу: 5xx и 429 — повторяемые, прочие 4xx — нет. Коды — `x-known-codes`.
Timeout клиента — `AI_REQUEST_TIMEOUT` (§69); timeout считается повторяемой ошибкой.

Лимиты входа (конфигурация AI, значения по умолчанию):

- analyze: JPEG/PNG/WebP по содержимому; до 20 MiB (`AI_MAX_IMAGE_BYTES`); декодирование до 50 MP
  (защита от decompression bomb) → иначе 413/422; ресайз под модель внутри AI (§62).
- text embeddings: 1..64 текстов, каждый 1..1000 символов, не пробельный; иначе 422 `INVALID_REQUEST`.
- Параллелизм: при заполненной очереди inference (`AI_CONCURRENCY`, §58) — 429 `SERVICE_BUSY` с `Retry-After`
  либо ожидание в очереди в пределах timeout (выбор реализации AI, контракт допускает оба).

Concepts:

- `type` ∈ типы тегов §16 (12 значений); значение вне enum — ответ модели невалиден: AI либо отображает его в `other`,
  либо отбрасывает concept до формирования ответа. В ответе API невалидный type невозможен.
- `label`: 1..255 символов, как вернула модель, без нормализации (нормализует Laravel, §35); до 50 concepts.
- `confidence` 0..1, неоткалиброванный (§38).
- Язык label — **вопрос**. Предложение: английский (prompt и примеры §31 на английском), русские имена тегов
  сопоставляются через aliases и stage 3.
- Ответ модели, не прошедший схему после внутренних попыток, — 502 `MODEL_OUTPUT_INVALID` (повторяемая: вывод VLM
  недетерминирован). Если AI вернул 200, а Laravel не смог провалидировать ответ, — это нарушение контракта AI:
  задача `failed` с `AI_RESPONSE_INVALID` без повторов.

Трассировка: `X-Request-ID` в запросе → тот же в ответе; для analyze значение совпадает с полем `request_id`.

## Вопросы

1. Разделение `/health` (liveness) и `/ready` (readiness)?
2. `Authorization: Bearer` или `X-API-Key`?
3. Enum `concept.type` = типы тегов? Отображение неизвестного типа в `other` или отбрасывание?
4. Язык `label`?
5. Лимиты по умолчанию (20 MiB, 50 MP, 64 текста, 1000 символов, 50 concepts)?
6. `MODEL_OUTPUT_INVALID` повторяемый?

## Принятое решение

Владелец продукта утвердил этот пункт 2026-09-25. Итоговая формулировка: AI service.** `/health` проверяет живой процесс, `/ready` — загруженные модели. Service key передаётся через `Authorization: Bearer`. Concept type совпадает с типами тегов; неизвестный тип отображается в `other`. Labels — на английском. Лимиты: 20 MiB и 50 MP для analyze, до 64 текстов по 1000 символов, до 50 concepts. `MODEL_OUTPUT_INVALID` возвращает 502 и может повторяться.

### Дополнение 2026-09-25 (contracts 1.2.0)

Владелец продукта утвердил отдельный код ошибки обработки: если AI на все попытки этапа отвечал 502
`MODEL_OUTPUT_INVALID`, задача и изображение получают `failed` с `error_code` `AI_OUTPUT_INVALID`, а не
`AI_SERVICE_UNAVAILABLE`. Повторяемость 502 не меняется; `AI_SERVICE_UNAVAILABLE` остаётся для недоступности AI,
прочих 5xx, 429 и timeout. Изменение совместимое (minor): новый код в каталоге кодов обработки.

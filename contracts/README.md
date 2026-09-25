# AI Photobank — контракты

| | |
| --- | --- |
| Версия | `0.1.0-draft` |
| Статус | **Черновик, не утвержден.** Реализации пакетов 01–04 не стартуют по элементам со статусом `proposed`, пока координатор не примет решения из [docs/adr](../docs/adr/README.md). |
| Владелец | пакет 00 «Контракты» (`contracts/**`, `docs/adr/**`) |
| Источник требований | [docs/technical-specification.md](../docs/technical-specification.md) (далее «ТЗ», ссылки `§N`) |

Контракты — единственный источник истины между модулями (§10): Nuxt ориентируется только на
`public-api.openapi.yaml`, Laravel и FastAPI — на `ai-api.openapi.yaml` и `schemas/**`.

## Состав

| Файл | Назначение | Потребители |
| --- | --- | --- |
| `public-api.openapi.yaml` | Публичный API Laravel: auth, dashboard, batches/upload, images, image tags, tags, aliases, tag candidates, search, media | frontend, backend, QA |
| `ai-api.openapi.yaml` | API AI service: `/health`, `/ready`, `/v1/images/analyze`, `/v1/embeddings/text` | backend (AiClient), AI, infra (health checks), QA |
| `schemas/image-analysis.json` | Ответ analyze; общий schema test backend и AI (§71) | backend, AI |
| `schemas/concepts.json` | Массив визуальных понятий | backend, AI |
| `schemas/ai-error.json` | Тело любой ошибки AI service | backend, AI |
| `schemas/text-embeddings.json` | Запрос/ответ text embeddings | backend, AI |
| `schemas/enums.json` | Все enum статусов и типов (§13–§25, §45, §57, §60) | все |
| `state-machines.md` | Автоматы состояний batch, image, processing task, candidate, tag, image tag | backend, frontend, QA |
| `examples/ai/*.json` | Полноразмерные (1152) детерминированные примеры AI API | backend (FakeAiClient), AI (mock model), QA |
| `tools/validate_contracts.py` | Проверка схем, OpenAPI, примеров и негативных случаев | все, CI |
| `requirements-validation.txt` | Зависимости для полной проверки в CI | все, CI |
| `tools/generate_examples.py` | Детерминированная генерация `examples/ai/*.json` | владелец контрактов |

Inline-примеры в OpenAPI покрывают критические операции (login, создание/polling batch, upload и дубликат,
карточка изображения, ручной тег, тег/alias и конфликт, кандидаты, поиск с debug, media, AI health/ready/analyze
и ошибки). Полный набор положительных/ошибочных фикстур и mock responses — следующая задача `contract-fixtures`.

## Маркировка требований

- `x-tz: "§N"` — операция, поле или значение взяты из ТЗ.
- `x-decision-status: proposed` + `x-adr: ADR-NNNN` — предложение контрактного этапа для пробела в ТЗ.
  Это **не утвержденный контракт**: координатор принимает или меняет решение в ADR, после чего маркер снимается.
- `x-proposed-constraints` — перечень ограничений узла, которые предложены, а не взяты из ТЗ.
- Если маркера нет, но поведение описано в `description` словом «proposed», действует то же правило.

## Общие правила

1. **Ошибки.** Public API: `{"error": {"code", "message", "details", "trace_id"}}` (§51). AI API: та же форма с
   `request_id` вместо `trace_id` (`schemas/ai-error.json`). Клиент строит логику только на `error.code` и HTTP-статусе,
   `message` не парсит. **Неизвестный код обрабатывается как общая ошибка по HTTP-статусу.**
2. **Совместимость ответов public API.** Клиент игнорирует неизвестные поля. Ответы AI валидируются строго
   (`additionalProperties: false`), так как вывод модели недоверенный (§63): новое поле в AI-ответе — minor-версия
   контракта и одновременное обновление схемы у backend и AI.
3. **Трассировка.** Каждый ответ public API содержит `X-Request-ID`, равный `error.trace_id`. Laravel передает
   `X-Request-ID` в AI; AI возвращает его в заголовке и в `error.request_id` (§64). Для analyze значение совпадает
   с полем формы `request_id` (§31). Детали — ADR-0002.
4. **Embeddings.** Ровно 1152 числа (§5); сокращенный массив в §31 — только иллюстрация. Векторы разных
   моделей/версий не смешиваются (§77, ADR-0009).
5. **Хранилище.** Наружу не отдаются storage keys и абсолютные пути (§11); файлы — только через `/api/media/...` (§57).

## Каталог кодов ошибок public API

| Код | HTTP | Источник |
| --- | --- | --- |
| `IMAGE_NOT_FOUND` | 404 | ТЗ §51 |
| `IMAGE_ALREADY_EXISTS` (`details.existing_image_id`) | 409 | код и details — ТЗ §27; HTTP-статус — proposed |
| `AI_SERVICE_UNAVAILABLE` | 503 | ТЗ §46 |
| `UNAUTHENTICATED` | 401 | proposed (ADR-0001) |
| `FORBIDDEN` | 403 | proposed (ADR-0001) |
| `CSRF_TOKEN_MISMATCH` | 419 | proposed (ADR-0001) |
| `INVALID_CREDENTIALS` | 422 | proposed (ADR-0001) |
| `TOO_MANY_REQUESTS` | 429 | proposed (ADR-0001) |
| `VALIDATION_ERROR` (`details.fields`) | 422 | proposed (ADR-0002) |
| `INTERNAL_ERROR` | 500 | proposed (ADR-0002) |
| `BATCH_NOT_FOUND` | 404 | proposed |
| `BATCH_NOT_ACCEPTING_UPLOADS` | 409 | proposed (ADR-0005) |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | proposed (ADR-0005) |
| `FILE_TOO_LARGE` | 413 | proposed (ADR-0005) |
| `IMAGE_NOT_RETRYABLE` | 409 | proposed (ADR-0003) |
| `IMAGE_TAG_NOT_FOUND` | 404 | proposed (ADR-0006) |
| `MEDIA_NOT_AVAILABLE` | 404 | proposed (ADR-0011) |
| `TAG_NOT_FOUND` | 404 | proposed |
| `TAG_ALREADY_EXISTS` (`details.existing_tag_id`) | 409 | proposed (ADR-0007) |
| `TAG_ARCHIVED` | 409 | proposed (ADR-0007) |
| `TAG_PARENT_INVALID` | 422 | proposed (ADR-0007) |
| `ALIAS_NOT_FOUND` | 404 | proposed |
| `ALIAS_ALREADY_EXISTS` (`details.existing_tag_id`) | 409 | proposed (ADR-0007) |
| `TAG_CANDIDATE_NOT_FOUND` | 404 | proposed |
| `TAG_CANDIDATE_ALREADY_RESOLVED` | 409 | proposed (ADR-0008) |
| `EMBEDDING_VERSION_MISMATCH` | 503 | proposed (ADR-0009) |

Коды ошибок обработки изображения (`images.error_code`, `processing_tasks.error_code`, отдаются в `ImageDetail`),
все proposed: `METADATA_EXTRACTION_FAILED`, `VARIANT_GENERATION_FAILED`, `AI_SERVICE_UNAVAILABLE`,
`AI_REQUEST_REJECTED` (AI вернул не повторяемую 4xx), `AI_RESPONSE_INVALID` (ответ не прошел `image-analysis.json`),
`TAG_RESOLUTION_FAILED`, `INTERNAL_ERROR`.

Коды AI service и их повторяемость — `schemas/ai-error.json` (`x-known-codes`).

## Версионирование и изменения

Semantic versioning для каталога `contracts/` целиком (одна версия в `info.version` обоих OpenAPI и в этом README):

- **major** — breaking: удаление/переименование endpoint или поля, новое обязательное поле запроса,
  сужение enum или типа, смена кода ошибки, смена размерности embedding, изменение семантики;
- **minor** — совместимое добавление: новый endpoint, необязательное поле запроса, поле ответа public API,
  новый код ошибки, новое значение enum, которое клиенты обязаны переносить;
- **patch** — описания, примеры, опечатки без изменения поведения.

Порядок изменения:

1. Владелец контрактов меняет схему, пример и, при необходимости, ADR; прогоняет `tools/validate_contracts.py`.
2. В описании изменения указываются версия, затронутые потребители (backend / frontend / AI / infra / QA) и
   совместимость. Breaking change требует явного уведомления **всех** потребителей до слияния.
3. Затем владельцы модулей синхронизируют реализации. Агент модуля не меняет `contracts/**` сам и не маскирует
   недостающий контракт собственным временным API (см. `.agents`).
4. Предсерийные версии: `0.x.y-draft` — пока есть открытые ADR; `1.0.0` — после утверждения всех ADR 0001–0011
   и снятия маркеров `proposed` с принятых элементов.

## Проверка

```sh
python3 -m pip install -r contracts/requirements-validation.txt
python3 contracts/tools/validate_contracts.py
```

Скрипт работает на стандартной библиотеке (YAML — через PyYAML или системный Ruby). Если установлены
`jsonschema` и `openapi-spec-validator`, дополнительно выполняются проверка метасхемы, валидация OpenAPI 3.1
и примеров библиотекой. Встроенный валидатор поддерживает подмножество JSON Schema 2020-12, используемое
контрактами, и отвергает незнакомые ключевые слова. Негативные проверки подтверждают, что неверные payload
(2-элементный embedding, неизвестный concept type, tag_id от AI, стандартная ошибка FastAPI, невалидные режимы поиска)
отклоняются.

Перегенерация примеров: `python3 contracts/tools/generate_examples.py`.

## Открытые вопросы

Сводный список вопросов координатору — в [docs/adr/README.md](../docs/adr/README.md#вопросы-координатору).

# Architecture Decision Records

Журнал решений контрактного этапа (пакет 00). Каждый ADR закрывает строку таблицы
«Решения, которые надо закрыть в контрактном этапе» из [development-plan.md](../development-plan.md).

**Все ADR приняты владельцем продукта 2026-09-25.** Точные решения записаны в [decision-checklist.md](decision-checklist.md); при расхождении с первоначальной рекомендацией действует утвержденный список.

Краткий пакет конкретных решений для review: [decision-checklist.md](decision-checklist.md).

Формат: контекст → варианты → рекомендация → последствия → вопросы.

| ADR | Тема | Строка плана | Статус |
| --- | --- | --- | --- |
| [0001](0001-authentication-sanctum-spa.md) | Sanctum: cookie-сессия или bearer, CSRF, 401/403/419 | Sanctum | Accepted |
| [0002](0002-api-conventions.md) | Формы ответов, пагинация, фильтры, ошибки, trace_id | Формы ответов | Accepted |
| [0003](0003-dashboard-and-retry.md) | Dashboard и retry failed image | Dashboard и retry | Accepted |
| [0004](0004-search-request.md) | `POST /api/search`: mode, валидация, tag_ids, debug | Search | Accepted |
| [0005](0005-batch-lifecycle-and-upload.md) | Автомат batch, счетчики, дубликаты, параметры upload | Batch | Accepted |
| [0006](0006-image-editing-and-suppression.md) | PATCH image, ручные теги, suppression | (связано с §41, §76) | Accepted |
| [0007](0007-tag-normalization-and-aliases.md) | Нормализация, коллизии alias, уникальность, archive/delete | Alias collisions | Accepted |
| [0008](0008-candidate-moderation.md) | approve/merge/reject, повторное обнаружение | Кандидат | Accepted |
| [0009](0009-embedding-versioning.md) | model_key/model_version, активная версия, метрика и индекс | Embedding | Accepted |
| [0010](0010-ai-service-contract.md) | /health vs /ready, ошибки AI, лимиты, API key, concept type | /health, 1152 | Accepted |
| [0011](0011-media-serving.md) | Авторизованная отдача media, X-Accel-Redirect | Media | Accepted |
| [0012](0012-similar-images.md) | Похожие изображения по image embedding | Search, Images | Accepted |

Строка плана «ТЗ показывает массив embedding из 2 чисел» закрыта без ADR — это однозначное требование §5:
`schemas/image-analysis.json` требует ровно 1152 числа, полный пример — `contracts/examples/ai/analyze-response.json`.

## История вопросов координатору (закрыты)

Блокирующие для старта соответствующих реализаций (в скобках — кого блокирует):

1. **Auth (ADR-0001; backend, frontend, infra).** Утвердить Sanctum SPA cookie-сессию и same-origin через nginx
   (`/api`, `/sanctum` проксируются на Laravel) вместо bearer token? Код неверного логина — 422 `INVALID_CREDENTIALS`?
2. **Коллекции (ADR-0002; backend, frontend).** Формат `{data, meta:{page, per_page, total, last_page}}`,
   `per_page` по умолчанию 50 / максимум 100 (теги — 500), сортировка параметром `sort` с префиксом `-`?
3. **Dashboard и retry (ADR-0003; backend, frontend).** Добавить `GET /api/admin/dashboard` и
   `POST /api/admin/images/{id}/retry`? Нужен ли batch-level retry всех failed изображений в MVP?
4. **Search (ADR-0004; backend, frontend).** Несколько `tag_ids` — AND или OR? Раскрываются ли дочерние теги иерархии?
   Нужен ли порог минимальной similarity в semantic? Кто может включать `debug` — любой admin или только при
   серверном флаге?
5. **Batch (ADR-0005; backend, frontend).** `processed` включает failed? Дубликаты исключены из `total`?
   Статус `failed` batch — когда все изображения failed? Нужно ли автозавершение незавершенного batch?
   Возвращает ли retry финальный batch в `processing`?
6. **Upload (ADR-0005; backend, frontend, infra).** Допустимые MIME (предложено JPEG/PNG/WebP; нужен ли HEIC/TIFF?),
   лимит размера по умолчанию (предложено 50 MiB; влияет на `client_max_body_size` nginx и PHP),
   имя поля `image`, код 201 или 202?
7. **Retry policy (ADR-0005; backend).** §47: 3 попытки всего или 1 + 3 повтора (10/30/120 с)?
8. **PATCH image (ADR-0006; backend, frontend).** Какие поля редактируются? Предложено только `original_name`.
   Создавать ли suppression при удалении manual-тега?
9. **Taxonomy (ADR-0007; backend, frontend).** Один alias на два тега допустим? Alias может совпадать с
   normalized_name другого тега? Как разрешать совпадение нормализованной строки у тегов разных типов
   (например, «каска» object и concept)? Что делать при совпадении concept с архивным тегом?
10. **Moderation (ADR-0008; backend, frontend).** Повторно обнаруженный rejected-кандидат: оставить rejected,
    вернуть в pending или игнорировать? Merge по умолчанию добавляет alias? Какой `source` у тегов, привязанных
    при approve/merge (`ai` или `manual`)?
11. **Embedding versions (ADR-0009; backend, AI).** Откуда `model_version`: добавить в ответ AI
    `models.embedding_revision` (и `revision` в text embeddings) или брать из конфигурации Laravel?
    Как вычислять `model_key` из `models.embedding`? Требовать L2-нормализацию векторов от AI?
12. **AI contract (ADR-0010; AI, backend, infra).** `/health` — liveness без проверки моделей, `/ready` — готовность?
    Service API key через `Authorization: Bearer` или `X-API-Key`? Enum `concept.type` = типы тегов §16?
    Язык `label` (английский, русский или любой)? Лимиты: 20 MiB на изображение, 64 текста на запрос?
    `MODEL_OUTPUT_INVALID` повторяемый?
13. **Media (ADR-0011; backend, infra, frontend).** Отдача через cookie-сессию и `X-Accel-Redirect` на internal
    location nginx (`/_protected/`)? Кеширование `private, max-age=3600`?

Неблокирующие уточнения ТЗ, выявленные при работе:

- §61 говорит одновременно «только затем `/health` возвращает ready» и «добавить readiness отдельно» — предложено
  разделить liveness/readiness (ADR-0010).
- §81 упоминает «202 / persisted» для upload, а ресурс изображения создается синхронно — предложено 201 (ADR-0005).
- Таблица `tag_candidates` (§23) не хранит, какой тег создан/выбран при approve/merge; ответ approve/merge
  возвращает его, но история в списке кандидатов недоступна. Нужно ли поле `resolved_tag_id` (ADR-0008)?
- §22 требует `vision_model_version`/`embedding_model_version`, а ответ §31 их не содержит (ADR-0009).

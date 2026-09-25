# ADR-0004: Запрос поиска `POST /api/search`

- Статус: Accepted
- Дата: 2026-09-25
- Затрагивает: backend, frontend, QA; `SearchRequest`, `SearchResponse`

## Контекст

§42 задает тело `{query, tag_ids, page, per_page}`, §45 — режимы `tags | semantic | hybrid`, §43 — теги как жесткий
фильтр, §44 — `semantic_score` только в debug, §46 — 503 `AI_SERVICE_UNAVAILABLE` для semantic. Как выбирается режим,
что делать с пустым query и несколькими тегами, не сказано.

## Варианты выбора режима

- A. Явное обязательное поле `mode` (рекомендуется): валидация однозначна, frontend точно знает, будет ли вызван AI.
- B. Вывод режима из полей (query есть → semantic/hybrid; нет → tags). Пустая строка query и `tag_ids: []`
  дают неоднозначности, ошибка frontend молча меняет режим.

## Рекомендация

Вариант A, тело — `oneOf` по `mode` с `additionalProperties: false`:

| mode | query | tag_ids | AI | Сортировка |
| --- | --- | --- | --- | --- |
| `tags` | запрещен | 1..20, уникальные | не вызывается | `created_at desc, id desc` |
| `semantic` | обязателен, 1..1000 символов, не пробельный | запрещен | text embedding | similarity desc, id asc |
| `hybrid` | обязателен | 1..20 | text embedding | similarity desc, id asc среди прошедших фильтр |

- Несколько `tag_ids` — **AND** (изображение имеет все теги). Дочерние теги не раскрываются.
  Архивные теги допустимы как фильтр. Несуществующий ID — 422 `VALIDATION_ERROR` с `details.fields.tag_ids`.
- Фильтр применяется до/внутри векторного запроса, а не после усечения top-K, чтобы hard filter не терял результаты
  (§43). Вектор ищется только среди активной версии embeddings (ADR-0009).
- Порога similarity нет: `total` = число подходящих изображений с активным embedding.
- `semantic_score = 1 - cosine_distance` (§44) возвращается только при `debug=true` и только в semantic/hybrid.
- Возвращаются только не удаленные изображения; для `tags` — в любом статусе, для semantic/hybrid — с embedding.
- AI недоступен/не готов → 503 `AI_SERVICE_UNAVAILABLE` (с `Retry-After`, если известно); `tags` работает без AI.
- Ответ: `{data: [ImageSummary + semantic_score?], meta: {page, per_page, total, last_page, mode}}`.

## Альтернативы для вопросов

- OR вместо AND; раскрытие потомков тегов (`include_descendants`); порог `SEARCH_MIN_SIMILARITY`; debug только
  при серверном флаге `SEARCH_DEBUG_ENABLED`.

## Вопросы

1. AND или OR для нескольких тегов? Раскрывать ли потомков?
2. Нужен ли порог минимальной similarity?
3. Разрешать `debug` любому admin или только при серверном флаге?

## Принятое решение

Владелец продукта утвердил этот пункт 2026-09-25. Итоговая формулировка: поиск.** Поле `mode` обязательно. Несколько tag IDs соединяются через AND; дочерние теги автоматически не включаются. Порог similarity не вводить. `debug` доступен любому вошедшему администратору; теги остаются жёстким фильтром для hybrid.

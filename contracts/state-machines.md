# Автоматы состояний

Версия контрактов `1.0.0`. Значения статусов взяты из ТЗ (`schemas/enums.json`). **Переходы, условия и счетчики утверждены** решениями ADR-0001–0011. Переходы, которых нет в таблицах, запрещены.

## Batch (`upload_batches.status`, §14) — ADR-0005

```text
             первый сохраненный файл              complete, есть незавершенные
  created ─────────────────────────▶ uploading ─────────────────────────────▶ processing
     │                                   │                                        │
     │ complete, все изображения          │ complete, все изображения              │ последнее изображение
     │ в финальном статусе               │ в финальном статусе                    │ стало ready/failed
     ▼                                   ▼                                        ▼
  ┌───────────────────────── completed | completed_with_errors | failed ◀────────┘
  │                                          │
  └──────────── retry/reprocess изображения ─┘──▶ processing
```

| Из | В | Условие |
| --- | --- | --- |
| `created` | `uploading` | успешно сохранен первый файл (дубликат не считается) |
| `created`, `uploading` | `processing` | `POST .../complete` и есть изображения не в финальном статусе |
| `created`, `uploading`, `processing` | финальный статус | после complete все изображения в `ready`/`failed` |
| финальный | `processing` | retry/reprocess изображения batch; `completed_at` обновится при новом завершении |

Финальный статус после complete:

- `completed` — `failed = 0` (включая пустой batch, `total = 0`);
- `completed_with_errors` — `0 < failed < total`;
- `failed` — `failed = total > 0`.

Счетчики `progress` (§49) вычисляются по не удаленным изображениям batch:

- `total` — сохраненные изображения; **дубликаты не сохраняются и не считаются** (§27);
- `processed` — изображения в финальном статусе (`ready` или `failed`);
- `failed` — изображения в статусе `failed`.
- Инвариант: `0 ≤ failed ≤ processed ≤ total`. Прогресс UI = `processed / total`.

Прочее:

- `complete` идемпотентен (§26): повтор возвращает текущее состояние.
- Upload принимается только в `created`/`uploading`; иначе 409 `BATCH_NOT_ACCEPTING_UPLOADS`.
- Batch без `complete` остается `uploading` бессрочно (автозавершение — вопрос координатору, ADR-0005).

## Image (`images.status`, §15)

```text
 uploaded ──▶ queued ──▶ processing ──▶ ready
    │            │            │           │
    └────────────┴────────────┴──▶ failed │
                                   │      │
          retry (только failed) ◀──┘      │ reprocess
                 ▼                        ▼
               queued ◀───────────────────┘
```

| Статус | Смысл (утверждено) |
| --- | --- |
| `uploaded` | оригинал сохранен, запись создана; metadata/variants еще не выполнены |
| `queued` | metadata и variants готовы, AI-анализ ожидает worker (в т.ч. при недоступном AI, §46) |
| `processing` | выполняются `ai_analysis` → `tag_resolution` → `finalize` |
| `ready` | анализ сохранен, теги разрешены; изображение доступно в semantic search |
| `failed` | у одной из задач исчерпаны попытки (§47); `error_code`/`error_message` заполнены |

- Upload отвечает после сохранения оригинала (`uploaded` или `queued`), не дожидаясь AI (§81).
- Поиск по тегам работает для любого статуса; semantic search учитывает только изображения с активным embedding (ADR-0009).
- `reprocess` (§76) допустим из `ready` и `failed`; из `queued`/`processing` — no-op, 202 с текущим состоянием.
- `retry` (ADR-0003) допустим только из `failed`.
- Soft delete (§78) допустим из любого статуса; обработка удаленного изображения прекращается на следующем этапе.

## Processing task (`processing_tasks`, §25)

Типы выполняются по порядку: `metadata` → `variants` → `ai_analysis` → `tag_resolution` → `finalize` (§28).

```text
 queued ──▶ processing ──▶ succeeded
   ▲            │
   │  ошибка,   │ ошибка, attempts = max_attempts
   │  attempts  ▼
   └──< max ── failed ──(retry изображения: attempts = 0)──▶ queued
```

- Повторяемая ошибка (AI 5xx/429/timeout, недоступность): `attempts += 1`, задача снова `queued` с задержкой
  10 с / 30 с / 120 с (§47).
- Не повторяемая ошибка (AI 4xx кроме 429, ответ не прошел `image-analysis.json` после проверки) — сразу `failed`
  (ADR-0010: `MODEL_OUTPUT_INVALID` возвращает 502 и допускает повтор).
- `failed` у задачи → `images.status = failed` (§47).
- `max_attempts` для AI: **вопрос** — 3 попытки всего или 1 + 3 повтора с задержками §47 (ADR-0005).
- Идемпотентность (§28): повтор этапа не создает дубликатов — variants перезаписываются по тем же ключам,
  image_analyses создается один раз на запуск анализа, связи тегов upsert по `image_id + tag_id`.

## Tag candidate (`tag_candidates.status`, §23) — ADR-0008

```text
           approve              merge                reject
 pending ───────────▶ created   pending ───────▶ merged   pending ───────▶ rejected
```

- Все переходы только из `pending`; повторное действие → 409 `TAG_CANDIDATE_ALREADY_RESOLVED`.
- Upsert по `normalized_name + type` (§39): повторное обнаружение добавляет связь в `tag_candidate_images`
  (одна на изображение). `occurrences_count` = число различных изображений.
- Повторное обнаружение `rejected`: связь с изображением сохраняется, счетчик растет, статус остается
  `rejected`, в очередь `pending` не возвращается (ADR-0008).
- `created`/`merged`: тег уже существует (или добавлен alias при merge), поэтому resolver находит его на stage 1/2.

## Tag (`tags.status`, §16) — ADR-0007

```text
 active ◀──── PATCH status ────▶ archived
    │
    └── DELETE: используется → archived (200); не используется → удален (204)
```

- `archived`: существующие связи сохраняются и участвуют в фильтрах; новые назначения (ручные, resolver, approve/merge)
  запрещены; тег не считается в `tags_count` dashboard.
- Совпадение concept с архивным тегом на stage 1/2 — concept игнорируется, кандидат не создается (утверждено).

## Image tag link (`image_tags`, `image_tag_suppressions`, §18, §19, §41) — ADR-0006

| Событие | Результат |
| --- | --- |
| Resolver назначает тег | `source=ai`, `locked=false`, если нет suppression и нет manual-связи |
| Admin добавляет тег | `source=manual`, `locked=true`; существующая AI-связь переводится в manual; suppression удаляется |
| Admin удаляет тег | связь удаляется, создается suppression (для AI-тега — §41; для manual — утверждено) |
| Approve/merge кандидата | `source=ai`, `locked=false`, confidence из `tag_candidate_images`, suppressed пары пропускаются |
| Reprocess (§76) | набор `source=ai, locked=false` заменяется новым результатом resolver; manual/locked и suppressions не меняются |

`source=import` в MVP не создается ни одной операцией public API и обрабатывается как `locked`.

## Состояния файла на экране Upload (§52)

Клиентские состояния, выводимые из ответов API:

| UI | Источник |
| --- | --- |
| `uploading` | запрос `POST /batches/{id}/images` в полете |
| `uploaded` | ответ 201 (изображение `uploaded` или `queued`), пока polling не покажет следующий статус |
| `duplicate` | 409 `IMAGE_ALREADY_EXISTS` |
| `processing` | `status` = `queued` или `processing` (polling `GET /api/admin/images?batch_id=…`) |
| `ready` | `status` = `ready` |
| `failed` | ошибка upload (413/415/422/5xx) или `status` = `failed` |

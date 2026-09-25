# ADR-0003: Dashboard и retry failed image

- Статус: Proposed
- Дата: 2026-09-25
- Затрагивает: backend, frontend; `GET /api/admin/dashboard`, `POST /api/admin/images/{image}/retry`, `reprocess`

## Контекст

Экран Dashboard (§52) показывает images count, tags count, pending candidates, processing images, failed images.
MVP включает «повторный запуск failed AI jobs» (§3), после исчерпания попыток admin получает Retry (§47).
Endpoints для этого в §50 не названы. `reprocess` (§76) есть, но описан как повторный AI-анализ готового изображения.

## Варианты

Dashboard:

- A. `GET /api/admin/dashboard` с пятью счетчиками (рекомендуется): один запрос, одна точка расчета.
- B. Композиция: `meta.total` из `GET /images?status=...` (×3), `/tags?status=active`, `/tag-candidates?status=pending`
  — пять запросов, «processing» требует трех статусов или фильтра по нескольким статусам.

Retry:

- A. Отдельный `POST /api/admin/images/{id}/retry` только для `failed` (рекомендуется): продолжает с упавшей задачи
  (например, variants), сбрасывает `attempts`. Семантика отличается от reprocess.
- B. Использовать `reprocess` и для failed: проще API, но reprocess после сбоя variants должен уметь начинать не с AI.
- C. Дополнительно `POST /api/admin/batches/{id}/retry-failed` — массовый retry (не включен; frontend может вызвать
  retry по списку `GET /images?batch_id=&status=failed`).

## Рекомендация

- Dashboard A. Определения: `images_count` — не удаленные изображения; `tags_count` — active теги;
  `pending_candidates_count`; `processing_images_count` — статусы uploaded+queued+processing; `failed_images_count`.
- Retry A: 202 + `ImageDetail` (`status=queued`), иначе 409 `IMAGE_NOT_RETRYABLE`.
- Reprocess: из `ready`/`failed` — новый полный цикл AI (`ai_analysis` → `tag_resolution` → `finalize`) с новой
  записью image_analyses (§76); из `queued`/`processing` — no-op 202 без дубля задачи.

## Вопросы

1. Утвердить два новых endpoint?
2. Нужен ли batch-level retry (вариант C) в MVP?

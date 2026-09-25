# ADR-0006: Редактирование изображения, ручные теги и suppression

- Статус: Proposed
- Дата: 2026-09-25
- Затрагивает: backend, frontend, QA; `PATCH /api/admin/images/{image}`, `/images/{image}/tags*`, reprocess

## Контекст

`PATCH /api/admin/images/{id}` есть в §50 без описания полей. Ручное добавление — `source=manual, locked=true` (§41),
ручное удаление AI-тега создает suppression (§19, §41), reprocess не трогает manual tags и suppressions,
но может обновить caption/OCR/AI tags (§76).

## Варианты PATCH

- A. Только `original_name` (рекомендуется): нет конфликта с reprocess.
- B. Также `caption`/`ocr_text`: нужен флаг «ручная подпись» и правило, что reprocess ее не перезаписывает —
  это новое поведение, которого нет в ТЗ.
- C. Удалить PATCH из MVP — противоречит §50.

## Варианты suppression при удалении manual-тега

- A. Всегда создавать suppression (рекомендуется): удаление = «этого тега здесь быть не должно»; AI не вернет тег.
- B. Только для `source=ai` (буквально §41): manual-тег, добавленный и удаленный, может вернуться после reprocess.

## Рекомендация

- PATCH A, `minProperties: 1`.
- `POST /images/{id}/tags {tag_id}`: создает или переводит связь в manual/locked; удаляет suppression этого тега;
  уже manual → 200 без изменений; архивный тег → 409 `TAG_ARCHIVED`.
- `DELETE /images/{id}/tags/{tag}`: удаляет связь, создает suppression (A); нет связи → 404 `IMAGE_TAG_NOT_FOUND`.
- Reprocess заменяет только связи `source=ai, locked=false`; suppressed теги не назначаются.
- Отдельного API просмотра/снятия suppressions нет: снятие — ручным добавлением тега.

## Вопросы

1. Какие поля редактирует PATCH?
2. Suppression при удалении manual-тега — A или B?
3. Нужно ли показывать suppressions на карточке изображения?

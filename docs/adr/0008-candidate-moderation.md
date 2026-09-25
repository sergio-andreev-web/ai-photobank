# ADR-0008: Модерация tag candidates

- Статус: Accepted
- Дата: 2026-09-25
- Затрагивает: backend, frontend, QA; `/api/admin/tag-candidates*`, `state-machines.md`

## Контекст

Кандидаты создаются/обновляются upsert по `normalized_name + type` (§39), хранят ближайший тег и similarity (§23),
связаны с изображениями (§24). Действия: создать тег, объединить, отклонить (§40); при merge изображения кандидата
получают canonical tag. Не определены атомарность, судьба изображений при approve, повторное обнаружение
отклоненного кандидата и предотвращение циклов.

## Рекомендация

- Все действия — в одной транзакции с блокировкой строки кандидата; только из `pending`, иначе 409
  `TAG_CANDIDATE_ALREADY_RESOLVED`. Фиксируются `resolved_at`, `resolved_by`.
- **Approve**: создает тег (`name`/`type`/`parent_id` можно переопределить; по умолчанию display_name/type);
  если такой тег уже есть — 409 `TAG_ALREADY_EXISTS` (admin выбирает merge). Статус `created`.
- **Merge** `{tag_id, create_alias=true}`: целевой тег должен быть active; по умолчанию display_name кандидата
  добавляется alias целевого тега (если он не совпадает с именем тега и не занят — иначе 409 `ALIAS_ALREADY_EXISTS`).
  Статус `merged`. Alias обеспечивает stage 2 match и предотвращает повторное появление кандидата.
- Approve и merge привязывают тег ко всем изображениям `tag_candidate_images` (не удаленным) с `source=ai`,
  `locked=false`, confidence из связи, пропуская suppressed пары и существующие связи.
  Ответ — `{candidate, tag, attached_images_count, alias_created}`.
- **Reject**: статус `rejected`, изображения не меняются.
- Повторное обнаружение:
  - `pending` — добавить связь с изображением (одна на изображение), обновить `occurrences_count` = число изображений,
    при необходимости `closest_tag_id`/`similarity` (лучшее значение);
  - `rejected` — вариант A (рекомендуется): связь и счетчик обновляются, статус остается `rejected`, в очередь не
    возвращается; варианты B — вернуть в `pending` при росте счетчика (риск циклов); C — полностью игнорировать
    (теряется статистика);
  - `created`/`merged` — не должно происходить (stage 1/2); если произошло (например, merge без alias и stage 3 ниже
    порога) — связь добавляется, статус не меняется.
- Список кандидатов по умолчанию `status=pending`, сортировка `-occurrences_count`; `sample_images` — до 8 по confidence.

## Вопросы

1. Поведение повторного обнаружения rejected — A, B или C?
2. `create_alias=true` по умолчанию при merge?
3. `source` привязанных при approve/merge тегов — `ai` (предложено; reprocess может их убрать) или `manual`
   (решение admin, защищено от reprocess)?
4. Нужно ли хранить `resolved_tag_id` в tag_candidates для истории (сейчас §23 его нет)?

## Принятое решение

Владелец продукта утвердил этот пункт 2026-09-25. Итоговая формулировка: модерация.** Повторно обнаруженный rejected candidate остаётся rejected, но счётчик обновляется. Merge по умолчанию создаёт alias. Назначения изображений после approve/merge имеют `source=ai`, `locked=false`, так как понятия обнаружены AI; suppression учитывается. Добавить `resolved_tag_id` в `tag_candidates` для аудита результата решения администратора.

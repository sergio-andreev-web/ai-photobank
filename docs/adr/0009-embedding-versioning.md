# ADR-0009: Версии embeddings, активная версия, метрика и индекс

- Статус: Proposed
- Дата: 2026-09-25
- Затрагивает: backend (схема БД, search, TagResolver), AI (поля ответа), QA; `schemas/image-analysis.json`,
  `schemas/text-embeddings.json`

## Контекст

`image_embeddings` и `tag_embeddings` хранят `model_key`, `model_version`, `VECTOR(1152)`, HNSW (§20, §21, §79);
`image_analyses` — `vision_model_version`, `embedding_model_version` (§22). Similarity = `1 - cosine_distance` (§44).
Нельзя смешивать векторы разных моделей (§77). Ответ AI (§31, §32) содержит только имена моделей
(`models.embedding`, `model`), версии нет. В §20 `model_key = siglip2-so400m-patch14-384`, а AI возвращает
`google/siglip2-so400m-patch14-384`.

## Варианты источника версии

- A. AI возвращает ревизию весов: `models.vision_revision`, `models.embedding_revision` в analyze и `revision`
  в text embeddings (рекомендуется; поля добавлены в схемы как необязательные proposed). Laravel не может
  «угадать» веса, загруженные AI.
- B. Laravel берет версию из своей конфигурации (`EMBEDDING_MODEL_VERSION`). Рассинхронизация с фактическим AI не
  обнаруживается — главный риск смешивания векторов.
- C. Версия = имя модели. Смена весов при том же имени (новая ревизия HF) не отличается.

## Рекомендация

- Вариант A; после утверждения поля `*_revision` становятся обязательными (minor → совместимо только если AI
  обновлен одновременно; иначе объявить как major).
- `model_key` = `models.embedding` без префикса организации (`google/siglip2-so400m-patch14-384` →
  `siglip2-so400m-patch14-384`), `model_version` = `embedding_revision`.
- Активная версия — конфигурация Laravel (`EMBEDDING_ACTIVE_MODEL_KEY`, `EMBEDDING_ACTIVE_MODEL_VERSION`);
  `image_embeddings.active = true` только у строк активной версии; одна активная строка на изображение.
- Semantic search и stage 3 используют только активную версию. Если text embedding вернулся с другой моделью/ревизией
  — 503 `EMBEDDING_VERSION_MISMATCH` (поиск), задача resolver — повторяемая ошибка.
- Метрика — cosine (`vector_cosine_ops`, оператор `<=>`), `semantic_score = 1 - distance`. HNSW partial index
  `WHERE active` для image_embeddings (§20) и индекс по tag_embeddings с фильтром по model_key/version.
- AI возвращает L2-нормализованные векторы (норма 1 ± 1e-3), что делает cosine и inner product эквивалентными и
  упрощает проверку в тестах.
- Смена embedding-модели — новая версия, переиндексация через reprocess; старые строки деактивируются, не удаляются.

## Вопросы

1. Вариант A (ревизия от AI) или B (конфигурация Laravel)?
2. Правило вычисления `model_key` из имени модели?
3. Требовать ли L2-нормализацию в контракте?
4. Что использовать как `vision_model_version`, если ревизии нет (например, локальные веса)?
5. Unique `tag_id + model_key` (§21) не включает `model_version`: при смене ревизии старый и новый tag embedding не
   могут сосуществовать во время переиндексации. Расширить unique до `tag_id + model_key + model_version`
   (предложено) или перезаписывать на месте?

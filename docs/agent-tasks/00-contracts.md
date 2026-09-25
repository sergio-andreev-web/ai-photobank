# Пакет 00 — контракты и решения API

**Владеет:** `contracts/**`, журнал архитектурных решений в `docs/adr/**`.

**Задача:** превратить [исходное ТЗ](../technical-specification.md) в проверяемые контракты до запуска независимых реализаций. Публичный API — единственный источник для Nuxt; AI API — единственный источник для Laravel и FastAPI.

**Сделать:** `public-api.openapi.yaml`, `ai-api.openapi.yaml`, `schemas/image-analysis.json`, `schemas/concepts.json`, `schemas/ai-error.json`, README с версионированием и правилами изменений; enum и автоматы состояний; примеры всех критических операций; машиночитаемые фикстуры и mock responses. Закрыть таблицу решений из [плана](../development-plan.md).

**Проверка:** OpenAPI и JSON Schema проходят валидацию; примеры соответствуют схемам; присутствуют операции auth, batch/upload, image/media, taxonomy/aliases, candidates, search, retry, health/readiness; backend, frontend и AI могут начать работу только по этим файлам.

**Граница:** не писать production реализацию Laravel, Nuxt или моделей. Изменения контракта после фиксации сообщать всем потребителям с указанием совместимости.

# Пакет 01 — Laravel backend и домен

**Владеет:** `apps/api/**`, включая миграции, API, очереди, тесты backend и Dockerfile API.

**Источник:** [исходное ТЗ](../technical-specification.md), `contracts/public-api.openapi.yaml`, `contracts/ai-api.openapi.yaml`, `contracts/schemas/**`.

**Модули внутри пакета:** auth; batch/upload/storage и варианты; обработка и RabbitMQ; AI HTTP client + FakeAiClient; taxonomy/aliases/candidates и suppression; pgvector search; media serving. AI никогда не создает canonical tags и не пишет в БД.

**Результат:** загрузка файла быстро возвращает ответ и запускает идемпотентный фоновой pipeline; оригинал сохраняется без изменения; дубли определяются по SHA-256; обработка переживает временную недоступность AI; вручную назначенные теги и suppression сохраняются при reprocess; теги ищутся без AI; смысловой поиск применяет hard filters и только совместимую версию embedding.

**Проверка:** PHPUnit/Pest и интеграционные тесты с FakeAiClient и детерминированными 1152-мерными векторами. Проверить duplicate, retry, неуспешный AI, candidate moderation, ручные теги, reprocess и поиск. Реальная модель и GPU для тестов не требуются.

**Граница:** не менять `contracts/**` и каталоги других владельцев; обнаруженное расхождение передать владельцу контрактов. Общие Laravel-файлы и миграции контролирует один backend-интегратор.

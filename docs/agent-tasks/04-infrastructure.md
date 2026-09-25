# Пакет 04 — локальный запуск и deployment

**Владеет:** `infra/**`, `docker-compose*.yml`, корневые `Makefile`, `.env.example`, конфигурация CI и инфраструктурная документация.

**Источник:** [исходное ТЗ](../technical-specification.md), утвержденные порты, env и health контракты. Dockerfile внутри `apps/api/**`, `apps/admin/**` и `services/ai/**` создают владельцы этих сервисов; инфраструктурный агент интегрирует их как потребитель.

**Сделать:** Compose для nginx, api, worker, admin, PostgreSQL+pgvector, Redis, RabbitMQ и опционального AI CPU; volumes, health checks, Makefile команды из §66; инструкции Mac MPS и RunPod CUDA, кеш моделей и API key; запуск mock AI без внешней модели.

**Результат:** после `cp .env.example .env && make setup` можно открыть приложение и прогнать smoke test. Переключение AI Mac ↔ RunPod требует изменения конфигурации, не кода Laravel.

**Проверка:** чистый запуск, миграции, seed, очередь, доступность UI/API и CPU/mock AI, корректная работа health checks.

**Граница:** не изменять код сервисов и их Dockerfile без согласования с владельцем. Секреты и веса моделей не коммитить.

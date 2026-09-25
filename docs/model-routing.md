# Модели для задач AI Photobank

**Пересмотрено:** 2026-09-25. Исполняемая матрица находится в [policy.json](../orchestrator/policy.json), а задания и зависимости — в [tasks.json](../orchestrator/tasks.json). Модель выбирает оркестратор при запуске задачи; файлы `.agents` и `CLAUDE.md` сами ее не переключают.

## Принцип

Оцениваем сложность **конкретного среза**, а не название модуля. Узкий каркас или миграция по утвержденной схеме подходит Luna/Haiku. Реализация с несколькими зависимостями и проверяемым поведением — Sol/Sonnet. Доменные инварианты, неоднозначные архитектурные решения и сквозная интеграция по умолчанию идут Claude Opus; для Codex максимально разрешен GPT-6 Sol. При нехватке контракта исполнитель останавливает зависимую работу и формулирует вопрос; более сильная модель не получает права изобретать договоренности.

| Задача | По умолчанию | Альтернативный провайдер | Основание |
| --- | --- | --- | --- |
| `contracts-v1` | Claude **Opus 5.5**, high | Codex **GPT-6 Sol**, high | Общие API, enum, состояния и ADR влияют на все модули |
| `contract-fixtures` | Claude **Haiku 4.5** | Codex **Luna**, medium | Примеры по уже утвержденным схемам |
| `backend-bootstrap` | Claude **Sonnet 5**, medium | Codex **Sol**, medium | Каркас и интерфейсы без доменных правил |
| `backend-schema-base` | Codex **Luna**, medium | Claude **Haiku 4.5** | Простые таблицы по точной схеме |
| `backend-schema-domain` | Claude **Sonnet 5**, high | Codex **Sol**, high | Связи, soft delete, pgvector и индексы |
| `backend-media-api` | Codex **Sol**, high | Claude **Sonnet 5**, high | Авторизация, хранение, валидация и дубликаты |
| `backend-processing` | Claude **Opus 5.5**, high | Codex **Sol**, high | Идемпотентность, конкурентные задачи, retry и reprocess |
| `backend-taxonomy` | Claude **Opus 5.5**, high | Codex **Sol**, high | Canonical tags, кандидаты, ручные решения и suppression |
| `backend-search` | Claude **Opus 5.5**, high | Codex **Sol**, high | Жесткие фильтры, hybrid ranking и версии embeddings |
| `admin-frontend-base` | Codex **Luna**, medium | Claude **Haiku 4.5** | Каркас и простые компоненты по mock API |
| `admin-frontend-flows` | Claude **Sonnet 5**, medium | Codex **Sol**, medium | Загрузка, модерация и поиск со связанным состоянием |
| `admin-dockerfile` | Claude **Sonnet 5**, medium | Codex **Sol**, medium | Образ Nuxt для prod и согласование с Compose |
| `ai-mock` | Claude **Sonnet 5**, medium | Codex **Sol**, medium | Межсервисный контракт и preprocessing |
| `ai-models` | Claude **Opus 5.5**, high | Codex **Sol**, high | Реальные модели, память, MPS/CUDA и отказы |
| `infrastructure-base` | Codex **Luna**, medium | Claude **Haiku 4.5** | Compose и команды по готовым интерфейсам |
| `infrastructure-web` | Claude **Sonnet 5**, high | Codex **Sol**, high | Публичный HTTPS, хранение и восстановление в облаке |
| `infrastructure-runpod` | Codex **Sol**, high | Claude **Sonnet 5**, high | GPU deployment, секреты и health checks |
| `ai-production-key` | Claude **Sonnet 5**, medium | Codex **Sol**, medium | Точечная проверка конфигурации и тесты запуска |
| `ci-tests` | Codex **Sol**, high | Claude **Sonnet 5**, high | Сервисы CI, PostgreSQL с pgvector, кеш зависимостей и честные падения |
| `compose-runtime` | Codex **Sol**, high | Claude **Sonnet 5**, high | Очереди воркера, окружение и запуск стека с чистого checkout |
| `integration-qa` | Claude **Opus 5.5**, high | Codex **Sol**, high | Сквозная приемка нескольких сервисов |

Для `Haiku 4.5` оркестратор не передает `--effort`: эта модель не поддерживает соответствующий параметр. `Fable 5.1` доступен только по явному `--model` для длительного исследования или задачи, где Opus на высокой сложности не справился. Для Codex разрешены только `gpt-6-luna` и `gpt-6-sol`; это проверяется даже при ручном выборе. Ollama пока выключен и разрешен только для узких видов задач после явной настройки и проверки локальной модели.

Без параметров `plan` и `run` проверяют текущие остатки подписок обоих провайдеров. **Провайдеры сравниваются по пятчасовому остатку**: Codex `primary`, Claude `Current session`. Недельный остаток проверяется отдельно и исключает провайдера, если он ниже 5%; пятчасовой порог тоже 5%. При разнице пятчасовых остатков меньше 10 процентных пунктов сохраняется предпочтительный провайдер задачи; при большей разнице выбирается провайдер с большим пятчасовым остатком. Например, при 20% у Codex и 80% у Claude задача уйдет Claude, если недельные лимиты позволяют запуск. Это сравнение процентов, а не оценка одинакового количества доступных токенов у разных подписок.

`--provider codex|claude` и `--model MODEL` задают выбор явно; `--model` без провайдера определяет его по списку разрешенных моделей. Оркестратор всё равно проверяет текущий остаток выбранной подписки и не запускает задачу при нуле; для Fable дополнительно проверяет его отдельный недельный лимит. Если проверка недоступна, оператор может сознательно указать `--skip-usage-check` вместе с явной моделью или провайдером. Команда `usage` показывает проценты и ошибки источников, `doctor` проверяет наличие CLI. Доступность конкретного ID в аккаунте выяснится при запуске; оркестратор не подменяет модель молча.

Внутри одного пакета задачи одного владельца выполняются последовательно и принимаются отдельно. Разные владельцы могут работать параллельно после приемки общих контрактов. Результат подтверждают контрактные проверки, тесты и review; название модели само по себе не является приемкой.

## Источники

- [OpenAI Docs: Model selection](https://developers.openai.com/api/docs/guides/model-selection) — Sol для повседневного кодинга, Luna для узкой работы.
- [Anthropic: Choosing the right model](https://platform.claude.com/docs/en/about-claude/models/choosing-a-model) — Opus для сложной агентной разработки, Sonnet для повседневного кодинга, Haiku для прямолинейных задач; Fable как эскалация.
- [Anthropic: Models overview](https://platform.claude.com/docs/en/models/overview) — ID моделей и поддержка effort.

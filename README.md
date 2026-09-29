# AI Photobank

MVP корпоративного фотобанка с управляемыми тегами и смысловым поиском. В репозитории есть Laravel API, Nuxt-панель, FastAPI-сервис анализа изображений, Compose-стек, контракты и приемочные тесты. Локальный стек запускается с mock AI без GPU; реальные модели подключаются отдельным профилем.

- [Исходное ТЗ](docs/technical-specification.md)
- [Этапы и зоны ответственности](docs/development-plan.md)
- [Общие правила агентов](.agents)
- [Назначение моделей](docs/model-routing.md)
- [Оркестратор: запуск и приемка задач](orchestrator/README.md)
- [Запуск Compose и окружения](infra/README.md)
- [Сквозная приемка §75](tests/e2e/stack/README.md)

Быстрый старт (нужны Docker Compose v2 и свободные стандартные порты):

```bash
make setup
make test
```

Панель открывается на `http://localhost`. `make setup` создаёт `.env`, поднимает сервисы, применяет миграции и создаёт локального администратора. `make test-e2e` запускает браузерную приемку и **изменяет данные** текущего стека; выполняйте её на отдельном тестовом окружении.

Проверить план без запуска агентов:

```bash
python3 scripts/orchestrate.py doctor
python3 scripts/orchestrate.py list
python3 scripts/orchestrate.py plan contracts-v1
```

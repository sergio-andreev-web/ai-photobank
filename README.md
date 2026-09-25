# AI Photobank

MVP корпоративного фотобанка с управляемыми тегами и смысловым поиском. Сейчас репозиторий содержит исходное ТЗ, план разработки и локальный оркестратор для параллельной работы Codex, Claude и опционально Ollama. Реализация приложения начнется после фиксации контрактов.

- [Исходное ТЗ](docs/technical-specification.md)
- [Этапы и зоны ответственности](docs/development-plan.md)
- [Общие правила агентов](.agents)
- [Назначение моделей](docs/model-routing.md)
- [Оркестратор: запуск и приемка задач](orchestrator/README.md)

Проверить план без запуска агентов:

```bash
python3 scripts/orchestrate.py doctor
python3 scripts/orchestrate.py list
python3 scripts/orchestrate.py plan contracts-v1
```

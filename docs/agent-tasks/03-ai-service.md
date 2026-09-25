# Пакет 03 — FastAPI и локальные модели

**Владеет:** `services/ai/**`, включая Dockerfile, prompts, unit tests и mock models.

**Источник:** [исходное ТЗ](../technical-specification.md), `contracts/ai-api.openapi.yaml`, `contracts/schemas/**`.

**Сделать:** API анализа изображения, batch text embeddings, health/readiness; интерфейсы VisionModel и EmbeddingModel; валидацию MIME/размера, EXIF и RGB preprocessing; Pydantic parsing; versioned prompt; детерминированные mock models. Затем подключить SigLIP2 и Qwen3-VL с выбором CPU/MPS/CUDA по конфигурации. Защитить production API key и документацию.

**Результат:** для 4B на Mac и 8B/4B на CUDA формат ответа одинаков; embedding имеет 1152 измерений; модельные веса хранятся в `HF_HOME`, а не в образе. AI не знает пользователей, записи изображений, ID тегов и PostgreSQL.

**Проверка:** pytest без модели в обычном CI; отдельные `pytest -m model` для реального inference; общий schema test для анализа; проверки invalid image, invalid model output, device selection и готовности.

**Граница:** не реализовывать taxonomy, не писать в БД, не менять Laravel или контракт самостоятельно.

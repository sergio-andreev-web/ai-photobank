# Contract Examples and Fixtures

Детерминированные примеры и фикстуры для тестирования и mock-API.

## Структура

```
examples/
├── README.md (этот файл)
├── ai/
│   ├── analyze-response.json         # Успешный анализ изображения (1152-мерный embedding)
│   ├── text-embeddings-request.json  # Запрос text embeddings
│   ├── text-embeddings-response.json # Ответ text embeddings
│   └── errors/
│       ├── invalid-request.json       # 400: Нарушение валидации
│       ├── unauthorized.json          # 401: Неверный API-ключ
│       ├── image-too-large.json       # 413: Файл превышает лимит
│       ├── unsupported-media-type.json # 415: Неподдерживаемый формат
│       ├── image-decode-failed.json   # 422: Не удалось декодировать
│       ├── model-not-ready.json       # 503: Модели еще загружаются
│       ├── model-output-invalid.json  # 502: VLM вернул невалидный output
│       ├── service-busy.json          # 429: Очередь переполнена
│       └── inference-failed.json      # 500: Внутренняя ошибка inference
└── public-api/
    └── errors/
        ├── unauthenticated.json           # 401: Нет сессии
        ├── validation-error.json          # 422: Ошибка валидации
        ├── image-not-found.json           # 404: Изображение не найдено
        ├── image-already-exists.json      # 409: Дубликат по хешу
        ├── ai-service-unavailable.json    # 503: AI service недоступен
        └── internal-error.json            # 500: Внутренняя ошибка сервера
```

## Валидация примеров

Все примеры проходят валидацию:
- **Встроенный валидатор**: поддерживает JSON Schema 2020-12 (подмножество)
- **jsonschema**: дополнительная проверка с `Draft202012Validator`
- **openapi-spec-validator**: проверка соответствия OpenAPI 3.1
- **Схемы**: все примеры проверяются против соответствующих схем в `contracts/schemas/`

Запуск валидации: `python3 contracts/tools/validate_contracts.py`

## AI API примеры

### Успешные ответы

#### `ai/analyze-response.json`
Полный анализ изображения со следующими элементами:
- **request_id**: UUID запроса
- **caption**: Описание изображения на английском (естественный язык)
- **ocr**: Массив найденных текстов с confidence
- **concepts**: Визуальные понятия (person, object, scene, activity) с confidence
- **embedding**: L2-нормализованный вектор размерностью ровно 1152
- **embedding_dimensions**: const 1152
- **models**: Версии используемых моделей (vision и embedding)
- **prompt_version**: Версия prompt-шаблона для VLM
- **processing_ms**: Время обработки в миллисекундах

Используется:
- Backend (FakeAiClient в тестах)
- AI service (mock-модели)
- QA (эталонная проверка)

#### `ai/text-embeddings-request.json`
Запрос для получения embeddings текстов. Содержит:
- **texts**: Массив строк для embedding (может быть 1..N элементов)

#### `ai/text-embeddings-response.json`
Ответ с embeddings текстов. Содержит:
- **model**: Полный идентификатор embedding-модели
- **revision**: Версия модели (дата сборки)
- **dimensions**: const 1152
- **embeddings**: Массив векторов (один на каждый текст)

**Инвариант:** `len(response.embeddings) == len(request.texts)`

### Ошибочные ответы

Все ошибки имеют формат `schemas/ai-error.json`:

```json
{
  "error": {
    "code": "CODE",
    "message": "Человеческое сообщение",
    "details": { /* зависит от code */ },
    "request_id": "uuid или null"
  }
}
```

| Файл | Код | HTTP | Описание |
| --- | --- | --- | --- |
| `invalid-request.json` | INVALID_REQUEST | 400 | Нарушена валидация (обязательные поля, формат) |
| `unauthorized.json` | UNAUTHORIZED | 401 | Service API key отсутствует или неверен |
| `image-too-large.json` | IMAGE_TOO_LARGE | 413 | Размер файла > AI_MAX_IMAGE_BYTES (20 MiB) |
| `unsupported-media-type.json` | UNSUPPORTED_MEDIA_TYPE | 415 | Формат не image/jpeg, image/png, image/webp |
| `image-decode-failed.json` | IMAGE_DECODE_FAILED | 422 | Файл битый или не изображение |
| `model-not-ready.json` | MODEL_NOT_READY | 503 | Модели загружаются или self-check не пройден |
| `model-output-invalid.json` | MODEL_OUTPUT_INVALID | 502 | VLM вернул невалидный structured output |
| `service-busy.json` | SERVICE_BUSY | 429 | Очередь inference переполнена (повторяемая) |
| `inference-failed.json` | INFERENCE_FAILED | 500 | Внутренняя ошибка (повторяемая) |

### Public API ошибочные ответы

Примеры ошибочных ответов public API Laravel (используют общий формат `schemas/error-response.json`):

| Файл | Код | HTTP | Описание |
| --- | --- | --- | --- |
| `unauthenticated.json` | UNAUTHENTICATED | 401 | Нет активной сессии или истекла |
| `validation-error.json` | VALIDATION_ERROR | 422 | Нарушена валидация входящих данных |
| `image-not-found.json` | IMAGE_NOT_FOUND | 404 | Изображение не найдено (§51) |
| `image-already-exists.json` | IMAGE_ALREADY_EXISTS | 409 | Дубликат по sha256 (§27) |
| `ai-service-unavailable.json` | AI_SERVICE_UNAVAILABLE | 503 | AI service недоступен (§46) |
| `internal-error.json` | INTERNAL_ERROR | 500 | Внутренняя ошибка сервера |

Используется:
- Frontend (mock API для тестирования error handling)
- QA (сценарии ошибок и граничных случаев)
- Backend тесты (проверка правильной обработки ошибок)

## Особенности примеров

1. **Детерминизм**: Все примеры генерируются скриптом `tools/generate_examples.py`
   и дают побайтно одинаковый результат при повторном запуске.

2. **Полноразмерные embedding**: Все вектора содержат ровно 1152 компонент (не сокращенные примеры из ТЗ).

3. **Валидация**: Все примеры проходят схемы из `schemas/`; запущен `tools/validate_contracts.py`.

4. **Реалистичные данные**:
   - Concept labels выбраны из типичных сценариев (промышленность, инженеры, оборудование)
   - OCR покрывает кириллицу (ЛУКОЙЛ)
   - Confidence в диапазоне [0.81, 0.97] — типично для VLM

## Использование в коде

### Backend (Laravel / FakeAiClient)

```php
$response = json_decode(file_get_contents('contracts/examples/ai/analyze-response.json'));
$this->mockAiService->shouldReceive('analyze')->andReturn($response);
```

### Frontend (Nuxt / mock API)

Примеры используются для мок-сервера, который отвечает на запросы к AI API:

```javascript
// Внутри mock-сервера (не коммитится)
const mockAnalyzeResponse = require('../../contracts/examples/ai/analyze-response.json');
```

### QA / интеграционные тесты

```python
import json
with open('contracts/examples/ai/analyze-response.json') as f:
    expected = json.load(f)
    # Сравнить с реальным ответом
```

## Регенерация примеров

После изменения `tools/generate_examples.py` пересоздать примеры:

```sh
python3 contracts/tools/generate_examples.py
```

Затем запустить валидацию:

```sh
python3 contracts/tools/validate_contracts.py
```

## Добавление новых примеров

1. Добавить пример в `examples/ai/` или подпапку
2. Указать корректный `$ref` на схему в `tools/validate_contracts.py` (в EXAMPLE_FILE_SCHEMAS)
3. Запустить `validate_contracts.py` — должен пройти без ошибок
4. Обновить этот README

Примеры не должны содержать:
- Production секреты или ключи
- Абсолютные пути (только relative storage keys)
- Данные, которые могут отличаться между запусками (используй фиксированные значения)

# Журнал работ агентов

Живой журнал для передачи работы между агентами (Claude Code, Codex, локальные). Каждый агент **дописывает** сюда,
что сделал, какие решения принял и что делать дальше. Новые записи — сверху в разделе «Текущая работа», завершенное
переносится в «Сделано». Правила проекта — `.agents`; контракты — `contracts/`; решения — `docs/adr/`.

## Как поднять и проверить стенд (Mac, реальные модели)

```bash
make start     # AI-сервис (Qwen3-VL + SigLIP2 на MPS) в фоне + Docker-стек; ждет готовности
make status    # контейнеры и AI
make stop      # остановить все (данные сохраняются)
```

- Админка: http://localhost, вход `admin@example.com` / `password123` (сидер, только `APP_ENV=local`).
- Лог AI: `.run/ai-mac.log`; pid: `.run/ai-mac.pid` (`infra/scripts/ai-mac.sh start|stop|status`).
- Локальный `.env` (не в git): `DB_PORT=55433` (5432 занят другим проектом), `PROMPT_VERSION=image-analysis-v3`,
  `TAG_AUTO_ATTACH_THRESHOLD=0.92`, `HF_HUB_OFFLINE=1`, `AI_MAC_HF_HOME=~/.cache/huggingface`.
- Веса: Qwen3-VL-4B, SigLIP2 — `~/.cache/huggingface`; InsightFace `buffalo_l` — `~/.cache/photobank-ai/insightface/buffalo_l`.
- Тестовые фото: `~/Downloads/test_imgs` (21 шт.).
- Тесты: backend — `make test-backend` (Docker; PHPUnit на SQLite); AI — `cd services/ai && .venv/bin/python -m pytest -q`;
  admin — `cd apps/admin && npx vitest run` и `E2E_PORT=3137 npx playwright test` (mock API).
- В сессиях Claude Docker credential helper зависает: `export DOCKER_CONFIG=<пустой config.json> DOCKER_HOST=unix://$HOME/.docker/run/docker.sock`.

## Известные ограничения и блокеры

- **CI GitHub Actions не запускается** с 2026-09-29: «recent account payments have failed or your spending limit
  needs to be increased». PR #62–#70 влиты по локальным проверкам; после восстановления оплаты перезапустить CI на
  `main` (в т. ч. §75 `tests/e2e/stack` и PHPUnit на PostgreSQL).
- PyTorch MPS не потокобезопасен: `AI_CONCURRENCY` на MPS только 1 (сервис не стартует иначе), текстовая башня
  SigLIP2 на MPS считается на CPU (PR #55).
- 4B-модель иногда ошибается в русском согласовании; подпись правится вручную (PR #69), название тега — при
  создании из кандидата (PR #62).

## Текущая работа

### Распознавание людей по лицам (ADR-0015) — в работе, Claude, 2026-09-30

Решение владельца продукта (2026-09-30): делаем; использование некоммерческое; 152-ФЗ соблюдается (согласия —
ответственность владельца, в системе — удаление биометрии человека по требованию). Выход за §4 ТЗ оформляется ADR-0015.

Модель: InsightFace `buffalo_l` (релиз v0.7, https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_l.zip,
sha256 архива `80ffe37d8a5940d59a7384c201a2a38d4741f2f3c51eef46ebb28218a7b0ca2f`). Нужны 2 файла:
`det_10g.onnx` (SCRFD, sha256 `5838f7fe…5b91`) и `w600k_r50.onnx` (ArcFace R50 WebFace600K, sha256 `4c06341c…e43`);
остальные файлы архива удалены. Лицензия моделей InsightFace — только некоммерческое использование.

Замер на 21 фото (CPU, onnxruntime 1.30): 30–125 мс на фото; ложных лиц на пейзажах нет; одна фигуристка на трех
фото — cosine 0.65–0.82, разные люди — ≤ 0.15 → порог «тот же человек» ~0.4.

План (каждый пункт — отдельный PR):
1. [готово, PR «AI: лица»] AI-сервис: `app/models/faces.py` (без пакета insightface/OpenCV), `POST /v1/faces/detect`
   (изображение → лица: bbox и 5 точек в долях, score, 512-мерный вектор; AI не знает людей), контракт AI API 1.9.0
   (`schemas/face-detection.json`), `MockFaceModel` для CI, тесты `tests/test_faces.py`, `onnxruntime` в
   `requirements-model.txt`. Веса качает `infra/scripts/fetch-face-model.sh` (sha256 закреплены); `ai-mac.sh start`
   (= `make start`) вызывает его сам — модель поднимается вместе с проектом. `FACES_ENABLED`, `FACE_MODEL_DIR` в `.env.example`.
   Проверка на стенде: 8 фото пользователя с события — 2 человека по 4 фото каждый (0.64–0.87, в т. ч. профиль),
   фигуристка на 3 фото (0.64–0.65); 25 лиц → 17 людей, ошибочных объединений нет.
2. [готово, PR «Лица, этап 2»] Backend, контракты 1.10.0. Поиск лиц — задача `App\Jobs\ScanImageFaces` в очереди
   `images.ai`, ставится из `FinalizeStage` (не этап processing_tasks: сбой лиц не делает фото failed); один раз на
   revision (`images.faces_revision`), при повторе — только пересопоставление; пересканирование удаляет старые лица.
   Таблицы `people` (name, name_normalized для поиска без учета регистра, note) и `faces` (bbox в долях, size_px,
   score, embedding vector(512), model_revision, status, similarity, rejected_person_ids). Сопоставление —
   `App\Services\Faces\FaceMatcher` (в PHP по эталонам `confirmed`; при тысячах людей перевести на pgvector):
   ≥ 0.55 → `auto`, ≥ 0.40 → `suggested`, иначе `unassigned`; отказ запоминается. Группы незнакомых —
   `FaceClusters` (≥ 64 px, ≥ 0.45, ≥ 2 фото). API: `/api/admin/people[...]`, `/api/admin/images/{id}/faces`,
   `/api/admin/faces/clusters|assign|{id}/reject`, фильтр `person_id`, имена людей в поиске по словам. Удаление
   человека удаляет его лица (152-ФЗ). Команда бэкфилла: `php artisan photobank:scan-faces [--all]`.
   Проверено на стенде (PostgreSQL): группы незнакомых = 3 реальных человека; назначение 2 фото → остальные `auto`
   (0.78); фильтр и поиск по имени; удаление человека стирает его лица; стенд возвращен в исходное состояние.
3. [следующий шаг] Админка: раздел «Люди» (новые люди из групп → ввести имя; справочник; лица на подтверждение),
   лица на странице фото (рамки, имя, «это не он», назначить), фильтр галереи по человеку, подписи статусов.

## Сделано (PR в `main`)

| PR | Что | Контракт/ADR |
| --- | --- | --- |
| #55 | Раздельные лимиты AI: поиск доступен во время анализа; SigLIP2 text на CPU при MPS | — |
| #56 | Интерфейс админки только на русском (`utils/labels.ts`) | — |
| #57 | Русская локаль валидации Laravel (`lang/ru/validation.php`) | — |
| #59 | `make start/stop/status` — стек + обе модели одной командой | — |
| #61 | Галерея: выделение, массовые действия, вкладки статусов, сортировка | — |
| #60 | Просмотр на весь экран, история партий | — |
| #62 | Модерация: русское имя при создании тега, массовые действия, горячие клавиши | — |
| #63 | Поиск по смыслу по умолчанию, понятия ИИ → теги, компактные фильтры | — |
| #64 | Подписи и понятия ИИ на русском: prompt `image-analysis-v3` | 1.3.0, ADR-0010 |
| #65 | Автопривязка тегов только для словоформ: порог 0.92 | — |
| #66 | Поиск: совпадения по словам (caption/OCR/теги) первыми + порог релевантности | 1.4.0, ADR-0004 |
| #67 | Похожие фото, поиск по имени файла | 1.5.0, ADR-0012 |
| #68 | Корзина и восстановление; реализован `GET /api/admin/dashboard` (был только в контракте) | 1.6.0, ADR-0013 |
| #69 | Редактирование подписи, ручная подпись переживает reprocess | 1.7.0, ADR-0006 |
| #70 | Скачивание оригиналов ZIP-архивом | 1.8.0, ADR-0014 |

Данные стенда переведены на русский 2026-09-30: теги `white`→«белый», `building`→«здание»; английские кандидаты
отклонены (бренды латиницей восстановлены в pending); все фото переобработаны с v3.

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

### Исследование объектов — Codex, 2026-09-30

Совместный пакет 05, контракт 1.11.0 / ADR-0016. Ветка `codex/object-web-research` от `main`;
Artists integration/ART workflow здесь не применяется. `.idea/` сохранена без изменений.

Реализованы настройки (автоматика off по умолчанию), отдельная очередь `images.research`,
идемпотентный ручной запуск, история/ошибки/polling в карточке изображения. Включение применяется только
к новым ready изображениям, reprocess не повторяет автоматическое исследование. Выключение отменяет
не начатые automatic jobs; manual работает при любом положении галочки. Предложения не меняют подписи,
canonical tags или людей. Поиск объектов универсальный, без предметных категорий/правил в коде.

Новый `services/web-research`: Playwright Google Lens, job-scoped stdio MCP (inspect_image,
visual_search, read_source), адаптеры Codex CLI / Claude CLI / OpenAI Responses / Anthropic Messages /
Ollama Qwen3.5 4B. Только bytes превью, никаких storage keys/DB/ID пользователей. Проверяются схема,
фактически прочитанные ссылки, размеры, таймауты, лимит инструментов; приватные адреса блокируются.
Ключи только в окружении; CLI получают временный MCP config, глобальные настройки не меняются.
Для Codex три конкретных инструмента разрешены в рамках задания, shell выключен, sandbox read-only.

Подготовлены Docker-сервис и Ollama для Linux CPU/NVIDIA (`research`, `research-local`,
`docker-compose.research-gpu.yml`), отдельный worker и production overlay. Нет зависимости от Apple MPS.
Native daemon включен в make start/stop (detached session, survives terminal exit); инструкции в services/web-research/README.md.
Локальный стенд пересобран и мигрирован, http://localhost/settings доступен, выбран ollama, автоматика off.
Qwen3.5 4B скачана (3.4 GB, кеш Ollama). Корневая .env дополнена локальными параметрами/случайным токеном,
не коммитится. Сервис .run/web-research.log/.pid, порт 8002.

Проверки: PHPUnit 344 теста / 1407 assertions, 9 skipped; Vitest 125 passed; сервис 10 passed,
включая настоящий stdio MCP transport, fake API/Ollama loops и HTTP/URL/schema ошибки.
OpenAPI/JSON Schema валидны, Mac/production Compose валидны, Docker build сервиса и запуск /health
в Linux-контейнере успешны. CLI Codex/Claude реально вызвали инструменты и вернули valid uncertain
на синтетической картинке и фиктивном источнике; реальная Qwen3.5 4B вызвала visual_search/read_source
и вернула valid structured result на рисунке и детерминированном тексте (не тест точности веб-поиска).
Vue-tsc exit 0, существующее предупреждение vue-router/volar/sfc-route-blocks сохраняется.

Живой Lens: после исправления загрузки длинных JS URL превью отправлено, далее Google возвращает
/sorry CAPTCHA. Обхода нет: WEB_SEARCH_BLOCKED. Успешное определение по реальным веб-источникам
в этом окружении пока не подтверждено. Live OpenAI/Anthropic не запускались: нет API keys/models;
их tool loops проверены mock ответами. NVIDIA inference не запускался, подготовлена конфигурация.
Полный финальный Playwright: 103 passed. Коммит реализации 4b7de73, ветка отправлена; создан
[PR #74](https://github.com/sergio-andreev-web/ai-photobank/pull/74), еще не в main. Следующий шаг — пробы на реальных
объектах с доступным поисковиком, оценка точности/скорости Qwen, затем deployment сайта.


## Сделано (PR в `main`)

### Лица, этап 3 — админка завершена, Codex, 2026-09-30

Продолжена незакоммиченная работа Claude в `feat/faces-admin` (пакет 02, только `apps/admin/**` и журнал;
публичный контракт 1.10.0, ADR-0015). Добавлен `apps/admin/tests/e2e/people.spec.ts`: 14 сценариев на запоминание
выбранных лиц группы, пустое имя/пустой выбор, ошибку API с сохранением формы, поиск по имени, подтверждение
и отказ, переименование, подтверждение удаления биометрии, PERSON_NOT_FOUND, назначение нового/существующего
человека на фото, обновление рамок, фильтр галереи и некорректный `person_id`.

Проверка геометрии нашла дефект вертикальных превью: изображение выходило за контейнер, рамки смещались.
`pages/gallery/[id].vue` теперь вписывает изображение по его пропорциям в область 4:3; тест проверяет положение
и размеры рамок для обеих ориентаций на ширинах 1440 и 390 px. В галерее исправлен обработчик открытия фильтров,
который возвращал boolean вместо void и не проходил TypeScript-проверку.

Проверки: Vitest **125 passed**, полный Playwright **100 passed** (86 старых + 14 новых), `git diff --check` без
ошибок, Docker production build успешен. `npx vue-tsc --noEmit` — exit 0, без TS-ошибок, но предупреждает об
отсутствующем вспомогательном плагине `vue-router/volar/sfc-route-blocks` в установленной связке Nuxt/vue-router.
На реальном стенде просмотрены раздел «Люди» (3 группы), кропы, рамки и форма назначения лица; данные не менялись.
Админка пересобрана и перезапущена. Коммит реализации: `83b9934`; ветка отправлена в origin.
Создан и по запросу владельца слит в `main` [PR #73](https://github.com/sergio-andreev-web/ai-photobank/pull/73). Проверена аннотация Admin Tests:
job не стартовал из-за неоплаченного аккаунта/лимита расходов (тот же блокер, что у предыдущих PR).
Слияние: `c632ab3`; локальный checkout переключен на `main` и обновлен fast-forward.
Следующий шаг: после восстановления оплаты — перезапуск CI на `main`.

### Распознавание людей по лицам (ADR-0015) — завершено, Claude / Codex, 2026-09-30

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
3. [готово, ветка `feat/faces-admin` в основном checkout, завершено Codex, 2026-09-30] Админка:
   - сделано: `types/api.ts` (Face, Person, FaceCluster…), `composables/usePeople.ts`, `utils/faceCrop.ts` (+ unit-тест),
     `components/FaceThumb.vue` (кроп лица из превью через CSS), `components/ImageFaces.vue` (лица на фото: «Кто
     это?» — выбрать из справочника или ввести имя, «Да/Нет» для suggested, «Это не он(а)»), рамки лиц поверх превью
     в `pages/gallery/[id].vue`, `pages/people/index.vue` («Новые люди» — группы → «Как зовут?» → «Запомнить»;
     справочник с поиском), `pages/people/[id].vue` (переименование, «На подтверждение» / «Узнан автоматически» /
     «Эталоны», «Подтвердить все», ✕ «не он», «Все фото», удаление с биометрией), пункт меню «Люди», фильтр
     «Человек» в галерее + `?person_id=` (`utils/galleryRoute.ts`), подписи статусов и кодов ошибок в `utils/labels.ts`;
   - проверено: vitest 125 passed, Playwright 100 passed (включая 14 новых сценариев), production build;
     экраны просмотрены на реальном стенде; исправлено смещение рамок вертикальных фото;
   - PR #73 слит в `main` по запросу владельца 2026-09-30;
     задача «ставить поиск лиц после загрузки» уже работает (этап 2), бэкфилл: `docker exec photobank-api php artisan photobank:scan-faces`.

| PR | Что | Контракт/ADR |
| --- | --- | --- |
| #73 | Админка: люди, подтверждение лиц, рамки и фильтр галереи; 14 новых e2e | 1.10.0, ADR-0015 |
| #72 | Backend: справочник людей, сопоставление, группы лиц, удаление биометрии | 1.10.0, ADR-0015 |
| #71 | AI: поиск лиц и векторы InsightFace buffalo_l | 1.9.0, ADR-0015 |
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

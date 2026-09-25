# ADR-0011: Авторизованная отдача media-файлов

- Статус: Accepted
- Дата: 2026-09-25
- Затрагивает: backend, infra (nginx), frontend; `GET /api/media/images/{image}/{variant}`, `ImageUrls`

## Контекст

Frontend не читает файловую систему; файлы — через `/api/media/images/{id}/thumbnail|preview|original`,
авторизация на backend, рекомендуется `X-Accel-Redirect` через nginx (§57). В БД — относительные storage keys (§11).
Способ авторизации для `<img>` и контракт между Laravel и nginx не описаны.

## Варианты

- A. Cookie-сессия + `X-Accel-Redirect` (рекомендуется, требует ADR-0001 A): `<img src="/api/media/...">` работает
  напрямую; Laravel проверяет сессию и существование файла, отвечает пустым телом с
  `X-Accel-Redirect: /_protected/photobank/<storage_key>` и `Content-Type`; nginx `location /_protected/ { internal; alias <PHOTO_STORAGE_ROOT>/; }`
  отдает файл (Range, sendfile).
- B. Signed URLs с коротким TTL (`?expires=&signature=`) — нужны при bearer-аутентификации; URL в ответах API
  устаревают, кеш браузера хуже.
- C. Стриминг через PHP — просто, но нагружает PHP-FPM большими оригиналами (§57 не рекомендует). Допустим как
  fallback вне Docker (`MEDIA_DRIVER=php`), контракт для клиента тот же.

## Рекомендация

- Вариант A; клиентский контракт одинаков для A и C.
- thumbnail/preview — `image/webp` (§12); original — исходный MIME, `Content-Disposition: inline; filename="<санитизированное имя>"`.
- `Cache-Control: private, max-age=3600`, `ETag` (sha256 для original; ключ варианта + updated_at для вариантов);
  поддержка `If-None-Match` → 304.
- Вариант еще не создан → 404 `MEDIA_NOT_AVAILABLE`; изображение отсутствует или soft-deleted → 404 `IMAGE_NOT_FOUND`;
  без сессии → 401 (браузер покажет битую картинку — frontend перенаправляет на login по 401 из API-запросов).
- В ответах API — относительные URL (`urls.thumbnail` и т.д.), `null` для еще не созданных вариантов.
- Internal location недоступен снаружи; storage keys и абсолютные пути не попадают в ответы клиенту.

## Вопросы

1. Утвердить вариант A и префикс internal location `/_protected/`?
2. Параметры кеширования?
3. Нужен ли `Content-Disposition: attachment` (скачивание) для original через query-параметр?

## Принятое решение

Владелец продукта утвердил этот пункт 2026-09-25. Итоговая формулировка: media.** Cookie-сессия + nginx `X-Accel-Redirect` через внутренний путь `/_protected/`; вне Docker допустим PHP fallback с тем же клиентским URL. `Cache-Control: private, max-age=3600` и ETag. Original отдаётся inline; отдельный параметр `attachment` в MVP не нужен.

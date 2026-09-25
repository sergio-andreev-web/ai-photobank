# ADR-0001: Аутентификация — Sanctum SPA cookie-сессия

- Статус: Accepted
- Дата: 2026-09-25
- Затрагивает: backend, frontend, infra; `public-api.openapi.yaml` (securitySchemes, `/sanctum/csrf-cookie`, `/api/auth/*`)

## Контекст

ТЗ задает Laravel Sanctum (§5), endpoints login/logout/me (§50) и отдачу файлов через `/api/media/...` с
авторизацией на backend (§57). Режим Sanctum (cookie-сессия SPA или bearer token), CSRF, CORS и коды ошибок не заданы.
Media используется в `<img src>`, где браузер не добавляет заголовок `Authorization`.

## Варианты

| | A. SPA cookie-сессия (рекомендуется) | B. Bearer token (personal access token) |
| --- | --- | --- |
| Login | `GET /sanctum/csrf-cookie` → `POST /api/auth/login` ставит HttpOnly cookie сессии | `POST /api/auth/login` возвращает token, клиент хранит его |
| `<img>` media | работает: cookie отправляется автоматически | не работает без signed URL или blob-загрузки через fetch |
| XSS-риск | token недоступен JS | token в JS-хранилище |
| CSRF | нужен `X-XSRF-TOKEN` | не нужен |
| Требования к развертыванию | один origin или общий родительский домен + `SANCTUM_STATEFUL_DOMAINS` | любой origin + CORS |

## Рекомендация

Вариант A:

- Nuxt и Laravel обслуживаются одним origin через nginx (`/` → Nuxt, `/api` и `/sanctum` → Laravel), CORS не нужен.
- Изменяющие запросы (POST/PATCH/DELETE) отправляют `X-XSRF-TOKEN` = значение cookie `XSRF-TOKEN`.
- Ответы: без сессии — 401 `UNAUTHENTICATED`; роль не `admin` — 403 `FORBIDDEN`; неверный CSRF — 419
  `CSRF_TOKEN_MISMATCH` (клиент заново вызывает `/sanctum/csrf-cookie` и повторяет запрос один раз);
  неверные учетные данные — 422 `INVALID_CREDENTIALS` (не 401, чтобы не путать с истекшей сессией);
  throttling login — 429 `TOO_MANY_REQUESTS`.
- `POST /api/auth/logout` идемпотентен (204 и без сессии), инвалидирует сессию и регенерирует CSRF token.
- `POST /api/search` и `/api/media/*` тоже требуют сессию admin (закрытый фотобанк, §1).
- Cookie: HttpOnly, `SameSite=Lax`, `Secure` в production.

## Последствия

- Infra обязана проксировать `/api` и `/sanctum` через тот же nginx, что отдает Nuxt (затрагивает пакет 04).
- Frontend-mock должен эмулировать cookie и 419.
- Отказ от A в пользу B потребует решения для media (ADR-0011) — signed URLs или загрузку через fetch.

## Вопросы

1. Утвердить вариант A и same-origin топологию?
2. Нужен ли `remember` (долгоживущая сессия) в login? В контракте поле исключено из версии 1.0.0.

## Принятое решение

Владелец продукта утвердил этот пункт 2026-09-25. Итоговая формулировка: вход.** Sanctum SPA cookie-сессия через один origin (Nuxt, `/api`, `/sanctum` за nginx); CSRF для изменяющих запросов. Ошибки 401/403/419, неверный логин — 422. Долгая сессия `remember` в MVP не нужна: убрать предложенное поле из login. Поиск и media требуют сессию администратора.

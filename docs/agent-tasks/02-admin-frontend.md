# Пакет 02 — административный Nuxt frontend

**Владеет:** `apps/admin/**`.

**Источник:** [исходное ТЗ](../technical-specification.md) и только `contracts/public-api.openapi.yaml` для серверных данных.

**Сделать:** login, dashboard, uploader с 3–5 параллельными запросами, состояниями каждого файла и polling batch; галерею и детали изображения; дерево тегов и aliases; очередь candidates с созданием/слиянием/отклонением; поиск в режимах tags/semantic/hybrid; обработку кодов ошибок и retry; адаптивный интерфейс.

**Результат:** все экраны работают против mock server по OpenAPI, включая duplicate upload, AI outage и failed image. Frontend не обращается напрямую к AI, БД или файловой системе и не повторяет алгоритм TagResolver.

**Проверка:** Vitest для ключевой клиентской логики; Playwright для login, batch upload, image detail, tag editing, moderation и search на mock API.

**Граница:** не менять backend/AI и контракт напрямую; отсутствующее поле или endpoint оформить как запрос владельцу контрактов.

import type {
  BatchStatus,
  FaceStatus,
  ImageStatus,
  ImageTagSource,
  ProcessingTaskStatus,
  ProcessingTaskType,
  TagCandidateStatus,
  TagStatus,
  TagType,
} from '~/types/api'
import type { FileState } from '~/utils/uploadTracking'

/**
 * Russian UI texts for contract enums and error codes. The interface is Russian-only:
 * API values (codes, statuses, types) stay as they are and are only mapped here for display.
 */

export const IMAGE_STATUS_LABELS: Record<ImageStatus, string> = {
  uploaded: 'Загружено',
  queued: 'В очереди',
  processing: 'Обрабатывается',
  ready: 'Готово',
  failed: 'Ошибка',
}

export const BATCH_STATUS_LABELS: Record<BatchStatus, string> = {
  created: 'Создана',
  uploading: 'Идёт загрузка',
  processing: 'Обрабатывается',
  completed: 'Завершена',
  completed_with_errors: 'Завершена с ошибками',
  failed: 'Ошибка',
}

export const TAG_TYPE_LABELS: Record<TagType, string> = {
  object: 'Объект',
  person: 'Человек',
  activity: 'Действие',
  scene: 'Сцена',
  location: 'Место',
  season: 'Сезон',
  weather: 'Погода',
  brand: 'Бренд',
  event: 'Событие',
  color: 'Цвет',
  concept: 'Понятие',
  other: 'Другое',
}

export const TAG_STATUS_LABELS: Record<TagStatus, string> = {
  active: 'Активен',
  archived: 'В архиве',
}

export const TAG_SOURCE_LABELS: Record<ImageTagSource, string> = {
  ai: 'ИИ',
  manual: 'вручную',
  import: 'импорт',
}

export const TASK_TYPE_LABELS: Record<ProcessingTaskType, string> = {
  metadata: 'Метаданные',
  variants: 'Превью',
  ai_analysis: 'ИИ-анализ',
  tag_resolution: 'Подбор тегов',
  finalize: 'Завершение',
}

export const TASK_STATUS_LABELS: Record<ProcessingTaskStatus, string> = {
  queued: 'в очереди',
  processing: 'выполняется',
  succeeded: 'выполнено',
  failed: 'ошибка',
}

export const CANDIDATE_STATUS_LABELS: Record<TagCandidateStatus, string> = {
  pending: 'Ожидают решения',
  created: 'Созданы теги',
  merged: 'Объединены',
  rejected: 'Отклонены',
}

/** How a resolved candidate ended up (singular, for the candidate card). */
export const CANDIDATE_RESOLUTION_LABELS: Record<TagCandidateStatus, string> = {
  pending: 'ожидает решения',
  created: 'создан тег',
  merged: 'объединён с тегом',
  rejected: 'отклонён',
}

export const FACE_STATUS_LABELS: Record<FaceStatus, string> = {
  unassigned: 'Не знаем, кто это',
  suggested: 'Похоже — подтвердите',
  auto: 'Узнан автоматически',
  confirmed: 'Подтверждён',
}

export const FILE_STATE_LABELS: Record<FileState, string> = {
  uploading: 'Загрузка',
  uploaded: 'Загружен',
  duplicate: 'Дубликат',
  processing: 'Обработка',
  ready: 'Готов',
  failed: 'Ошибка',
}

/** Unknown values (a newer contract) are shown as they came rather than hidden. */
export function labelOf<T extends string>(labels: Record<T, string>, value: T | string): string {
  return (labels as Record<string, string>)[value] ?? value
}

/** Russian plural form: pluralRu(5, ['изображение', 'изображения', 'изображений']) → 'изображений'. */
export function pluralRu(count: number, forms: readonly [string, string, string]): string {
  const n = Math.abs(count) % 100
  const n1 = n % 10
  if (n > 10 && n < 20) return forms[2]
  if (n1 > 1 && n1 < 5) return forms[1]
  if (n1 === 1) return forms[0]
  return forms[2]
}

export const IMAGE_FORMS = ['изображение', 'изображения', 'изображений'] as const
export const FILE_FORMS = ['файл', 'файла', 'файлов'] as const

/** Public API error codes (contracts/README.md) and processing error codes. */
const ERROR_MESSAGES: Record<string, string> = {
  IMAGE_NOT_FOUND: 'Изображение не найдено.',
  IMAGE_ALREADY_EXISTS: 'Такое изображение уже загружено.',
  AI_SERVICE_UNAVAILABLE: 'ИИ-сервис сейчас недоступен. Попробуйте ещё раз чуть позже.',
  UNAUTHENTICATED: 'Сессия истекла. Войдите снова.',
  FORBIDDEN: 'Недостаточно прав для этого действия.',
  CSRF_TOKEN_MISMATCH: 'Сессия устарела. Обновите страницу и повторите действие.',
  INVALID_CREDENTIALS: 'Неверный email или пароль.',
  TOO_MANY_REQUESTS: 'Слишком много запросов. Подождите немного и повторите.',
  VALIDATION_ERROR: 'Проверьте введённые данные.',
  INTERNAL_ERROR: 'Внутренняя ошибка сервера. Попробуйте ещё раз.',
  NOT_FOUND: 'Не найдено.',
  METHOD_NOT_ALLOWED: 'Действие не поддерживается.',
  BATCH_NOT_FOUND: 'Партия не найдена.',
  BATCH_NOT_ACCEPTING_UPLOADS: 'Партия больше не принимает файлы.',
  UNSUPPORTED_MEDIA_TYPE: 'Формат не поддерживается. Загрузите JPEG, PNG или WebP.',
  FILE_TOO_LARGE: 'Файл слишком большой.',
  IMAGE_NOT_RETRYABLE: 'Это изображение сейчас нельзя отправить на повторную обработку.',
  IMAGE_TAG_NOT_FOUND: 'У изображения нет такого тега.',
  MEDIA_NOT_AVAILABLE: 'Файл изображения недоступен.',
  TAG_NOT_FOUND: 'Тег не найден — возможно, его уже удалили.',
  TAG_ALREADY_EXISTS: 'Тег с таким названием и типом уже существует.',
  TAG_ARCHIVED: 'Тег в архиве. Сначала восстановите его.',
  TAG_PARENT_INVALID: 'Этот тег нельзя выбрать родителем.',
  ALIAS_NOT_FOUND: 'Синоним не найден.',
  ALIAS_ALREADY_EXISTS: 'Такой синоним уже есть у другого тега.',
  TAG_CANDIDATE_NOT_FOUND: 'Кандидат не найден.',
  TAG_CANDIDATE_ALREADY_RESOLVED: 'По этому кандидату уже принято решение.',
  PERSON_NOT_FOUND: 'Человек не найден — возможно, его уже удалили.',
  FACE_NOT_FOUND: 'Лицо не найдено — возможно, фото переобработали.',
  EMBEDDING_VERSION_MISMATCH: 'Поисковый индекс строится на другой версии модели. Семантический поиск временно недоступен.',
  // Processing error codes (image.error_code, processing_tasks[].error_code).
  METADATA_EXTRACTION_FAILED: 'Не удалось прочитать метаданные файла.',
  VARIANT_GENERATION_FAILED: 'Не удалось создать превью.',
  AI_REQUEST_REJECTED: 'ИИ-сервис отклонил изображение.',
  AI_RESPONSE_INVALID: 'ИИ-сервис вернул некорректный ответ.',
  AI_OUTPUT_INVALID: 'Модель не смогла описать изображение в нужном формате.',
  TAG_RESOLUTION_FAILED: 'Не удалось подобрать теги.',
}

const STATUS_MESSAGES: Record<number, string> = {
  401: ERROR_MESSAGES.UNAUTHENTICATED!,
  403: ERROR_MESSAGES.FORBIDDEN!,
  404: 'Не найдено.',
  409: 'Действие конфликтует с текущим состоянием данных. Обновите страницу.',
  413: ERROR_MESSAGES.FILE_TOO_LARGE!,
  415: ERROR_MESSAGES.UNSUPPORTED_MEDIA_TYPE!,
  419: ERROR_MESSAGES.CSRF_TOKEN_MISMATCH!,
  422: ERROR_MESSAGES.VALIDATION_ERROR!,
  429: ERROR_MESSAGES.TOO_MANY_REQUESTS!,
}

export const NETWORK_ERROR_MESSAGE = 'Нет связи с сервером. Проверьте подключение и повторите.'
export const GENERIC_ERROR_MESSAGE = 'Что-то пошло не так. Попробуйте ещё раз.'

/**
 * Russian text for an API error; the API's own `message` is never shown (§51: it is not parsed either). Unknown codes fall back to the HTTP status (contracts/README.md:
 * "неизвестный код обрабатывается как общая ошибка по HTTP-статусу"); no status means no response.
 */
export function errorText(code: string | null | undefined, status: number | null): string {
  if (code && ERROR_MESSAGES[code]) return ERROR_MESSAGES[code]
  if (status === null) return NETWORK_ERROR_MESSAGE
  if (STATUS_MESSAGES[status]) return STATUS_MESSAGES[status]
  if (status >= 500) return 'Ошибка сервера. Попробуйте ещё раз позже.'
  return GENERIC_ERROR_MESSAGE
}

/** Text for a processing error code (image.error_code, processing_tasks[].error_code). */
export function processingErrorText(code: string | null | undefined): string {
  return code && ERROR_MESSAGES[code] ? ERROR_MESSAGES[code] : 'Ошибка обработки.'
}

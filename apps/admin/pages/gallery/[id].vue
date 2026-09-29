<template>
  <div class="space-y-6">
    <NuxtLink to="/gallery" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700">
      <UIcon name="i-heroicons-arrow-left" /> Назад в галерею
    </NuxtLink>

    <ErrorAlert v-if="loadError" title="Не удалось загрузить изображение" :message="loadError" @dismiss="loadError = null" />
    <ErrorAlert v-if="actionError" :message="actionError" @dismiss="actionError = null" />
    <div v-if="notice" role="status" aria-live="polite" data-testid="image-notice">
      <UAlert color="success" variant="soft" :title="notice" close @update:open="notice = null" />
    </div>

    <div v-if="loading && !image" class="text-center text-gray-600">Загрузка…</div>

    <div v-else-if="image" class="surface-card p-6 md:p-8">
      <div class="grid gap-8 lg:grid-cols-2">
        <!-- Image Preview -->
        <div class="space-y-4">
          <div class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl bg-slate-100">
            <img
              v-if="image.urls.preview"
              :src="image.urls.preview"
              :alt="image.original_name"
              class="h-full w-full object-contain"
            />
            <div v-else class="text-6xl text-slate-300"><UIcon name="i-heroicons-photo" /></div>
          </div>
          <div>
            <p class="subtle-label">ИМЯ ФАЙЛА</p>
            <div v-if="!renaming" class="mt-1 flex items-start gap-2">
              <p class="flex-1 break-all text-xl font-semibold text-gray-900" data-testid="image-name">{{ image.original_name }}</p>
              <UButton
                size="sm"
                color="neutral"
                variant="ghost"
                icon="i-heroicons-pencil-square"
                aria-label="Переименовать"
                title="Переименовать"
                @click="startRename"
              />
            </div>
            <form v-else class="mt-2 space-y-3" novalidate @submit.prevent="handleRename">
              <UFormField label="Имя файла" name="original_name" :error="renameError ?? draftError ?? undefined">
                <UInput v-model="nameDraft" class="w-full" maxlength="1024" autofocus />
              </UFormField>
              <div class="flex gap-2">
                <UButton type="submit" :loading="actionLoading === 'rename'" :disabled="!canSaveName">Сохранить</UButton>
                <UButton variant="ghost" color="neutral" @click="cancelRename">Отмена</UButton>
              </div>
            </form>
          </div>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <p class="text-gray-600">Статус</p>
              <UBadge :color="statusColor(image.status)" class="mt-1">{{ labelOf(IMAGE_STATUS_LABELS, image.status) }}</UBadge>
            </div>
            <div>
              <p class="text-gray-600">Размеры</p>
              <p class="text-gray-900">{{ image.width && image.height ? `${image.width} × ${image.height}` : '—' }}</p>
            </div>
            <div>
              <p class="text-gray-600">Объём</p>
              <p class="text-gray-900">{{ formatSize(image.file_size) }}</p>
            </div>
            <div>
              <p class="text-gray-600">Создано</p>
              <p class="text-gray-900">{{ formatDate(image.created_at) }}</p>
            </div>
          </div>

          <div v-if="image.status === 'failed'" class="rounded-md bg-red-50 p-3 text-sm text-red-700">
            <p class="font-medium">{{ imageErrorText }}</p>
          </div>

          <div class="flex flex-wrap gap-2">
            <UButton
              v-if="image.status === 'failed'"
              color="warning"
              :loading="actionLoading === 'retry'"
              @click="handleRetry"
            >
              Повторить обработку
            </UButton>
            <UButton
              v-if="image.status === 'ready' || image.status === 'failed'"
              variant="soft"
              :loading="actionLoading === 'reprocess'"
              @click="handleReprocess"
            >
              Обработать заново
            </UButton>
            <UButton
              v-if="!confirmingDelete"
              color="error"
              variant="soft"
              icon="i-heroicons-trash"
              @click="() => { confirmingDelete = true }"
            >
              Удалить изображение
            </UButton>
          </div>

          <section
            v-if="confirmingDelete"
            class="rounded-xl border border-red-200 bg-red-50 p-4"
            aria-label="Подтверждение удаления"
          >
            <h3 class="font-semibold text-red-900">Удалить изображение?</h3>
            <p class="mt-1 text-sm text-red-700">
              Оно исчезнет из галереи, поиска и счётчиков. Сам файл и история анализа сохранятся.
            </p>
            <div class="mt-3 flex gap-2">
              <UButton color="error" :loading="actionLoading === 'delete'" @click="handleDelete">Удалить</UButton>
              <UButton variant="ghost" color="neutral" :disabled="actionLoading === 'delete'" @click="() => { confirmingDelete = false }">
                Отмена
              </UButton>
            </div>
          </section>
          <!-- Similar images (ADR-0012) -->
          <section class="pt-2" aria-label="Похожие фото" data-testid="similar-images">
            <h3 class="section-title">Похожие фото<template v-if="similarTotal"> ({{ similarTotal }})</template></h3>
            <p v-if="similarLoading" class="mt-2 text-sm text-gray-500">Ищем похожие…</p>
            <p v-else-if="image.status !== 'ready' && !similar.length" class="mt-2 text-sm text-gray-500">
              Появятся после обработки фото.
            </p>
            <p v-else-if="!similar.length" class="mt-2 text-sm text-gray-500">Похожих фото не найдено.</p>
            <div v-else class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
              <NuxtLink
                v-for="item in similar"
                :key="item.id"
                :to="`/gallery/${item.id}`"
                class="block aspect-square overflow-hidden rounded-lg bg-slate-100 hover:ring-2 hover:ring-blue-500"
                :title="item.original_name"
              >
                <img v-if="item.urls.thumbnail" :src="item.urls.thumbnail" :alt="item.original_name" class="h-full w-full object-cover" />
                <span v-else class="flex h-full items-center justify-center text-2xl text-slate-300"><UIcon name="i-heroicons-photo" /></span>
              </NuxtLink>
            </div>
          </section>
        </div>

        <!-- Image Details -->
        <div class="space-y-6">
          <div v-if="image.caption || image.ocr_text" class="rounded-xl bg-slate-50 p-5">
            <h3 class="section-title">Описание</h3>
            <div class="mt-2 space-y-2 text-sm text-gray-600">
              <p v-if="image.caption">Подпись: {{ image.caption }}</p>
              <p v-if="image.ocr_text">Текст на изображении: {{ image.ocr_text }}</p>
            </div>
          </div>

          <!-- Image Tags -->
          <div class="rounded-xl border border-slate-200 p-5">
            <div class="flex items-center justify-between">
              <h3 class="section-title">Теги</h3>
              <UButton size="sm" variant="soft" @click="() => { showAddTag = !showAddTag }"> Добавить тег </UButton>
            </div>

            <div v-if="showAddTag" class="mt-2 flex gap-2">
              <USelectMenu
                v-model="tagToAdd"
                :items="tagOptions"
                value-key="value"
                placeholder="Выберите тег"
                class="flex-1"
                @update:search-term="onTagSearch"
              />
              <UButton :disabled="tagToAdd == null" :loading="actionLoading === 'add-tag'" @click="handleAddTag">
                Добавить
              </UButton>
            </div>

            <div v-if="image.image_tags.length" class="mt-2 flex flex-wrap gap-2" data-testid="image-tags">
              <UBadge
                v-for="imageTag in image.image_tags"
                :key="imageTag.tag.id"
                variant="soft"
                :color="imageTag.source === 'manual' ? 'success' : 'info'"
                class="flex items-center gap-1"
              >
                {{ imageTag.tag.name }}
                <span class="text-xs">({{ labelOf(TAG_SOURCE_LABELS, imageTag.source) }})</span>
                <button
                  type="button"
                  class="ml-1 text-xs opacity-70 hover:opacity-100"
                  :aria-label="`Убрать тег ${imageTag.tag.name}`"
                  @click="handleRemoveTag(imageTag.tag.id)"
                >
                  ✕
                </button>
              </UBadge>
            </div>
            <p v-else class="mt-2 text-sm text-gray-600">Тегов пока нет.</p>
          </div>

          <!-- AI concepts -->
          <div v-if="image.latest_analysis?.concepts?.length">
            <h3 class="section-title">Понятия, найденные ИИ</h3>
            <p class="mt-1 text-xs text-gray-500">Нажмите на понятие, чтобы добавить фото тег.</p>
            <div class="mt-2 flex flex-wrap gap-2" data-testid="concepts">
              <button
                v-for="concept in image.latest_analysis.concepts"
                :key="`${concept.type}:${concept.label}`"
                type="button"
                class="concept-chip"
                :class="{ 'is-open': openConcept === concept }"
                :aria-expanded="openConcept === concept"
                @click="openConcept = openConcept === concept ? null : concept"
              >
                {{ concept.label }} ({{ Math.round(concept.confidence * 100) }}%)
              </button>
            </div>
            <ConceptTagger
              v-if="openConcept"
              :key="`${openConcept.type}:${openConcept.label}`"
              :image-id="image.id"
              :concept="openConcept"
              :assigned-tag-ids="image.image_tags.map((it) => it.tag.id)"
              @done="onConceptTagged"
              @close="openConcept = null"
            />
          </div>

          <!-- Processing Tasks -->
          <div v-if="image.processing_tasks.length">
            <h3 class="section-title">Этапы обработки</h3>
            <div class="mt-2 space-y-2">
              <div v-for="task in image.processing_tasks" :key="task.type" class="text-sm">
                <p class="font-medium text-gray-900">{{ labelOf(TASK_TYPE_LABELS, task.type) }}</p>
                <p class="text-xs text-gray-600">
                  Статус: {{ labelOf(TASK_STATUS_LABELS, task.status) }} (попыток: {{ task.attempts }} из {{ task.max_attempts }})
                  <span v-if="task.error_code" class="text-red-600">— {{ processingErrorText(task.error_code) }}</span>
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { Concept, ImageDetail, ImageStatus, ImageSummary, Tag } from '~/types/api'
import { toApiProblem } from '~/utils/errorHandler'
import {
  IMAGE_STATUS_LABELS,
  labelOf,
  processingErrorText,
  TAG_SOURCE_LABELS,
  TAG_TYPE_LABELS,
  TASK_STATUS_LABELS,
  TASK_TYPE_LABELS,
} from '~/utils/labels'

// ImagePatchRequest.original_name in contracts/public-api.openapi.yaml.
const NAME_MAX_LENGTH = 1024

const route = useRoute()
const imagesApi = useImages()
const tagsApi = useTags()

const image = ref<ImageDetail | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const actionLoading = ref<'retry' | 'reprocess' | 'add-tag' | 'remove-tag' | 'rename' | 'delete' | null>(null)

const renaming = ref(false)
const nameDraft = ref('')
const renameError = ref<string | null>(null)
const confirmingDelete = ref(false)

const showAddTag = ref(false)
const openConcept = ref<Concept | null>(null)
const similar = ref<ImageSummary[]>([])
const similarTotal = ref(0)
const similarLoading = ref(false)

async function loadSimilar(id: number) {
  similarLoading.value = true
  try {
    const data = await imagesApi.listSimilar(id, { per_page: 12 })
    similar.value = data.data
    similarTotal.value = data.meta.total
  } catch {
    // Secondary block: the page works without it.
    similar.value = []
    similarTotal.value = 0
  } finally {
    similarLoading.value = false
  }
}
const notice = ref<string | null>(null)

async function onConceptTagged(message: string) {
  openConcept.value = null
  notice.value = message
  await loadImage()
}
const tagToAdd = ref<number | undefined>()
const tagOptions = ref<{ label: string; value: number }[]>([])

function statusColor(status: ImageStatus) {
  const colors = {
    uploaded: 'neutral',
    queued: 'warning',
    processing: 'info',
    ready: 'success',
    failed: 'error',
  } as const
  return colors[status]
}

// A failed image carries a processing error code; the stored message is not shown (Russian-only UI).
const imageErrorText = computed(() => processingErrorText(image.value?.error_code))

const canSaveName = computed(() => {
  const name = nameDraft.value.trim()
  return name.length > 0 && name !== image.value?.original_name
})
// The Save button stays disabled for a blank draft (and disabled buttons don't submit on Enter),
// so say why right in the field.
const draftError = computed(() => (nameDraft.value.trim() ? null : 'Введите имя файла.'))

function formatDate(date: string): string {
  return new Date(date).toLocaleString('ru-RU')
}

function formatSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} КБ`
  return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} МБ`
}

async function loadImage() {
  loading.value = true
  loadError.value = null
  try {
    image.value = await imagesApi.getImage(Number(route.params.id))
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loading.value = false
  }
}

async function onTagSearch(query: string) {
  try {
    const data = await tagsApi.listTags({ q: query || undefined, per_page: 20, status: 'active' })
    tagOptions.value = data.data
      .filter((tag: Tag) => !image.value?.image_tags.some((it) => it.tag.id === tag.id))
      .map((tag: Tag) => ({ value: tag.id, label: `${tag.name} (${labelOf(TAG_TYPE_LABELS, tag.type)})` }))
  } catch (e) {
    // Search box degrades gracefully to an empty list on failure.
  }
}

async function handleAddTag() {
  if (!image.value || !tagToAdd.value) return
  actionLoading.value = 'add-tag'
  actionError.value = null
  try {
    await imagesApi.addTag(image.value.id, tagToAdd.value)
    tagToAdd.value = undefined
    showAddTag.value = false
    await loadImage()
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

async function handleRemoveTag(tagId: number) {
  if (!image.value) return
  actionLoading.value = 'remove-tag'
  actionError.value = null
  try {
    await imagesApi.removeTag(image.value.id, tagId)
    await loadImage()
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

async function handleRetry() {
  if (!image.value) return
  actionLoading.value = 'retry'
  actionError.value = null
  try {
    image.value = await imagesApi.retryImage(image.value.id)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

async function handleReprocess() {
  if (!image.value) return
  actionLoading.value = 'reprocess'
  actionError.value = null
  try {
    image.value = await imagesApi.reprocessImage(image.value.id)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionLoading.value = null
  }
}

function startRename() {
  if (!image.value) return
  nameDraft.value = image.value.original_name
  renameError.value = null
  renaming.value = true
}

function cancelRename() {
  renaming.value = false
  renameError.value = null
}

async function handleRename() {
  if (!image.value) return
  const name = nameDraft.value.trim()
  if (!name) return
  if (name.length > NAME_MAX_LENGTH) {
    renameError.value = `Не больше ${NAME_MAX_LENGTH} символов.`
    return
  }
  if (name === image.value.original_name) return

  actionLoading.value = 'rename'
  actionError.value = null
  renameError.value = null
  try {
    // ADR-0006: original_name is the only editable field; the response is the full ImageDetail.
    image.value = await imagesApi.updateImage(image.value.id, { original_name: name })
    renaming.value = false
  } catch (e) {
    const problem = toApiProblem(e)
    renameError.value = problem.fields.original_name?.join(' ') ?? null
    actionError.value = problem.message
  } finally {
    actionLoading.value = null
  }
}

async function handleDelete() {
  if (!image.value) return
  actionLoading.value = 'delete'
  actionError.value = null
  try {
    // Soft delete (204). The gallery, search and dashboard counters load fresh on mount,
    // so there is no client-side list to invalidate.
    await imagesApi.deleteImage(image.value.id)
    await navigateTo('/gallery')
  } catch (e) {
    actionError.value = useApiError(e).message
    actionLoading.value = null
  }
}

onMounted(() => {
  loadImage()
  onTagSearch('')
  loadSimilar(Number(route.params.id))
})

// Opening a similar image reuses this page component: reload for the new id.
watch(
  () => route.params.id,
  (id) => {
    if (!id) return
    openConcept.value = null
    loadImage()
    loadSimilar(Number(id))
  },
)
</script>

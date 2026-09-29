<template>
  <section class="surface-card bulk-bar" aria-label="Действия с выбранными" data-testid="bulk-bar">
    <div class="flex flex-wrap items-center gap-2">
      <strong class="mr-2 text-sm text-gray-900" data-testid="bulk-count">
        Выбрано: {{ images.length }}
      </strong>

      <template v-if="!running">
        <UButton size="sm" variant="soft" icon="i-heroicons-tag" @click="openMode('add-tag')">Добавить тег</UButton>
        <UButton
          size="sm"
          variant="soft"
          icon="i-heroicons-minus-circle"
          :disabled="selectionTags.length === 0"
          @click="openMode('remove-tag')"
        >
          Убрать тег
        </UButton>
        <UButton
          size="sm"
          variant="soft"
          color="warning"
          icon="i-heroicons-arrow-path"
          :disabled="eligible('retry').length === 0"
          @click="run('retry')"
        >
          Повторить обработку ({{ eligible('retry').length }})
        </UButton>
        <UButton
          size="sm"
          variant="soft"
          color="neutral"
          icon="i-heroicons-sparkles"
          :disabled="eligible('reprocess').length === 0"
          @click="run('reprocess')"
        >
          Обработать заново ({{ eligible('reprocess').length }})
        </UButton>
        <UButton
          size="sm"
          variant="soft"
          color="neutral"
          icon="i-heroicons-arrow-down-tray"
          :loading="downloading"
          :disabled="images.length > 100"
          :title="images.length > 100 ? 'Не больше 100 фото за раз' : undefined"
          @click="download"
        >
          Скачать ({{ images.length }})
        </UButton>
        <UButton size="sm" variant="soft" color="error" icon="i-heroicons-trash" @click="openMode('delete')">
          Удалить
        </UButton>
        <UButton size="sm" variant="ghost" color="neutral" class="ml-auto" @click="$emit('clear')">
          Снять выделение
        </UButton>
      </template>

      <p v-else class="text-sm text-gray-600" role="status" aria-live="polite" data-testid="bulk-progress">
        {{ RUNNING_LABELS[running.kind] }}… {{ running.finished }} / {{ running.total }}
      </p>
    </div>

    <!-- Delete confirmation -->
    <div v-if="mode === 'delete' && !running" class="bulk-panel border-red-200 bg-red-50" role="group" aria-label="Подтверждение удаления">
      <p class="font-semibold text-red-900">
        Удалить {{ images.length }} {{ pluralRu(images.length, IMAGE_FORMS_ACC) }}?
      </p>
      <p class="mt-1 text-sm text-red-700">
        Они исчезнут из галереи, поиска и счётчиков. Их можно будет восстановить из корзины.
      </p>
      <div class="mt-3 flex gap-2">
        <UButton color="error" size="sm" @click="run('delete')">Удалить</UButton>
        <UButton variant="ghost" color="neutral" size="sm" @click="closeMode">Отмена</UButton>
      </div>
    </div>

    <!-- Add tag -->
    <div v-if="mode === 'add-tag' && !running" class="bulk-panel" role="group" aria-label="Добавление тега">
      <div class="flex flex-wrap items-end gap-2">
        <UFormField label="Тег" class="min-w-[240px] flex-1">
          <USelectMenu
            v-model="tagId"
            :items="tagOptions"
            value-key="value"
            ignore-filter
            placeholder="Выберите тег"
            class="w-full"
            @update:search-term="onTagSearch"
          />
        </UFormField>
        <UButton size="sm" :disabled="tagId === undefined || eligible('add-tag').length === 0" @click="run('add-tag')">
          Добавить к {{ eligible('add-tag').length }} {{ pluralRu(eligible('add-tag').length, IMAGE_FORMS_DAT) }}
        </UButton>
        <UButton variant="ghost" color="neutral" size="sm" @click="closeMode">Отмена</UButton>
      </div>
      <p v-if="tagId !== undefined && skipped('add-tag') > 0" class="mt-2 text-xs text-gray-500">
        У {{ skipped('add-tag') }} {{ pluralRu(skipped('add-tag'), IMAGE_FORMS_GEN) }} этот тег уже есть.
      </p>
    </div>

    <!-- Remove tag -->
    <div v-if="mode === 'remove-tag' && !running" class="bulk-panel" role="group" aria-label="Удаление тега">
      <div class="flex flex-wrap items-end gap-2">
        <UFormField label="Тег" class="min-w-[240px] flex-1">
          <USelect
            v-model="tagId"
            :items="selectionTags.map(tag => ({ label: tagOptionLabel(tag), value: tag.id }))"
            placeholder="Выберите тег"
            class="w-full"
          />
        </UFormField>
        <UButton size="sm" color="error" variant="soft" :disabled="eligible('remove-tag').length === 0" @click="run('remove-tag')">
          Убрать у {{ eligible('remove-tag').length }} {{ pluralRu(eligible('remove-tag').length, IMAGE_FORMS_GEN) }}
        </UButton>
        <UButton variant="ghost" color="neutral" size="sm" @click="closeMode">Отмена</UButton>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { ImageSummary, Tag, TagRef } from '~/types/api'
import { isEligible, runBulk, tagsOfSelection, type BulkActionKind, type BulkResult } from '~/utils/bulkActions'
import { toApiProblem } from '~/utils/errorHandler'
import { labelOf, pluralRu, TAG_TYPE_LABELS } from '~/utils/labels'

const props = defineProps<{
  /** The selected images, in page order. */
  images: readonly ImageSummary[]
}>()

const emit = defineEmits<{
  /** A bulk action ran; `message` says what happened, `failedIds` stay selected. */
  finished: [message: string, failedIds: number[], tone: 'success' | 'warning']
  clear: []
}>()

// «удалить 1 изображение / 2 изображения / 5 изображений», «к 1 изображению …», «у 1 изображения …»
const IMAGE_FORMS_ACC = ['изображение', 'изображения', 'изображений'] as const
const IMAGE_FORMS_DAT = ['изображению', 'изображениям', 'изображениям'] as const
const IMAGE_FORMS_GEN = ['изображения', 'изображений', 'изображений'] as const

const RUNNING_LABELS: Record<BulkActionKind, string> = {
  'delete': 'Удаление',
  'retry': 'Повтор обработки',
  'reprocess': 'Постановка в очередь',
  'add-tag': 'Добавление тега',
  'remove-tag': 'Удаление тега',
}

const imagesApi = useImages()
const tagsApi = useTags()

const mode = ref<'delete' | 'add-tag' | 'remove-tag' | null>(null)
const tagId = ref<number | undefined>()
const tagOptions = ref<{ label: string; value: number }[]>([])
const running = ref<{ kind: BulkActionKind; finished: number; total: number } | null>(null)
const downloading = ref(false)

/** ZIP of the originals (ADR-0014); the selection stays as it is. */
async function download() {
  downloading.value = true
  try {
    const filename = await imagesApi.downloadImages(props.images.map(image => image.id))
    emit('finished', `Архив ${filename} скачивается.`, props.images.map(image => image.id), 'success')
  } catch (e) {
    emit('finished', `Не удалось скачать: ${toApiProblem(e).message}`, props.images.map(image => image.id), 'warning')
  } finally {
    downloading.value = false
  }
}

const selectionTags = computed(() => tagsOfSelection(props.images))

function tagOptionLabel(tag: Pick<TagRef, 'name' | 'type'>): string {
  return `${tag.name} (${labelOf(TAG_TYPE_LABELS, tag.type)})`
}

function eligible(kind: BulkActionKind): ImageSummary[] {
  return props.images.filter(image => isEligible(kind, image, tagId.value))
}

function skipped(kind: BulkActionKind): number {
  return props.images.length - eligible(kind).length
}

function openMode(next: 'delete' | 'add-tag' | 'remove-tag') {
  mode.value = next
  tagId.value = undefined
  if (next === 'add-tag') void onTagSearch('')
}

function closeMode() {
  mode.value = null
  tagId.value = undefined
}

let tagSearchSeq = 0
async function onTagSearch(term: string) {
  const seq = ++tagSearchSeq
  try {
    const data = await tagsApi.listTags({ q: term.trim() || undefined, per_page: 20, status: 'active' })
    if (seq !== tagSearchSeq) return
    tagOptions.value = data.data.map((tag: Tag) => ({ value: tag.id, label: tagOptionLabel(tag) }))
  } catch {
    // The picker degrades to an empty list; the rest of the bar keeps working.
  }
}

const DONE_LABELS: Record<BulkActionKind, string> = {
  'delete': 'Удалено',
  'retry': 'Отправлено на повторную обработку',
  'reprocess': 'Поставлено в очередь на анализ',
  'add-tag': 'Тег добавлен',
  'remove-tag': 'Тег убран',
}

function summary(kind: BulkActionKind, result: BulkResult, skippedCount: number): string {
  const total = result.done.length + result.failed.length
  const parts = [`${DONE_LABELS[kind]}: ${result.done.length} из ${total}.`]
  if (skippedCount > 0) parts.push(`Пропущено: ${skippedCount} (действие к ним не применимо).`)
  if (result.failed.length > 0) {
    const reasons = [...new Set(result.failed.map(failure => toApiProblem(failure.error).message))]
    parts.push(`Не удалось: ${result.failed.length} — ${reasons.join(' ')}`)
  }
  return parts.join(' ')
}

async function run(kind: BulkActionKind) {
  const tag = tagId.value
  const targets = eligible(kind).map(image => image.id)
  const skippedCount = props.images.length - targets.length
  if (targets.length === 0) return

  const worker: Record<BulkActionKind, (id: number) => Promise<unknown>> = {
    'delete': id => imagesApi.deleteImage(id),
    'retry': id => imagesApi.retryImage(id),
    'reprocess': id => imagesApi.reprocessImage(id),
    'add-tag': id => imagesApi.addTag(id, tag!),
    'remove-tag': id => imagesApi.removeTag(id, tag!),
  }

  running.value = { kind, finished: 0, total: targets.length }
  try {
    const result = await runBulk(targets, worker[kind], (finished) => {
      if (running.value) running.value.finished = finished
    })
    closeMode()
    emit(
      'finished',
      summary(kind, result, skippedCount),
      result.failed.map(failure => failure.id),
      result.failed.length > 0 ? 'warning' : 'success',
    )
  } finally {
    running.value = null
  }
}
</script>

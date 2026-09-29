<template>
  <div class="space-y-6">
    <div class="page-heading">
      <span class="subtle-label">ОЧЕРЕДЬ ПРОВЕРКИ</span>
      <h1>Кандидаты в теги</h1>
      <p>Понятия, предложенные ИИ, которых ещё нет среди канонических тегов.</p>
    </div>

    <!-- Filters -->
    <div class="surface-card p-5">
      <UFormField label="Статус">
        <USelect v-model="selectedStatus" :items="statusOptions" class="w-full max-w-xs" />
      </UFormField>
    </div>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />
    <ErrorAlert v-if="actionError" :message="actionError" @dismiss="actionError = null" />

    <!-- Candidates List -->
    <div v-if="loading" class="text-center text-gray-600">Загрузка кандидатов…</div>

    <div v-else-if="candidates.length === 0" class="surface-card p-12 text-center">
      <p class="text-gray-600">По этому фильтру кандидатов нет.</p>
    </div>

    <div v-else class="space-y-4">
      <div v-for="candidate in candidates" :key="candidate.id" class="surface-card p-6">
        <div class="flex flex-col items-start justify-between gap-4 sm:flex-row">
          <div class="flex-1">
            <p class="text-2xl font-bold text-gray-900">{{ candidate.display_name }}</p>
            <p class="mt-1 text-sm text-gray-600">
              {{ labelOf(TAG_TYPE_LABELS, candidate.type) }} &middot; встречается на {{ candidate.occurrences_count }} {{ pluralRu(candidate.occurrences_count, IMAGE_FORMS_PREP) }}
            </p>

            <div v-if="candidate.closest_tag" class="mt-2 text-sm text-gray-600">
              Ближайший существующий тег: <strong>{{ candidate.closest_tag.name }}</strong>
              <span v-if="candidate.similarity != null"> (сходство {{ (candidate.similarity * 100).toFixed(0) }}%)</span>
            </div>

            <div v-if="candidate.sample_images.length" class="mt-3 flex gap-2">
              <img
                v-for="sample in candidate.sample_images"
                :key="sample.id"
                :src="sample.thumbnail_url ?? undefined"
                class="h-16 w-16 rounded object-cover"
                :alt="`Пример: изображение #${sample.id}`"
              />
            </div>

            <div v-if="candidate.status !== 'pending'" class="mt-3 rounded bg-blue-50 p-3 text-sm text-blue-900">
              Решение: <strong>{{ labelOf(CANDIDATE_RESOLUTION_LABELS, candidate.status) }}</strong>
              <span v-if="candidate.resolved_tag_id"> → тег #{{ candidate.resolved_tag_id }}</span>
            </div>
          </div>

          <div v-if="candidate.status === 'pending'" class="flex shrink-0 flex-wrap gap-2 sm:flex-col">
            <UButton
              color="success"
              variant="soft"
              size="sm"
              :loading="pendingAction(candidate.id) === 'approve'"
              @click="handleApprove(candidate)"
            >
              Создать тег
            </UButton>
            <UButton
              color="warning"
              variant="soft"
              size="sm"
              @click="openMerge(candidate)"
            >
              Объединить с…
            </UButton>
            <UButton
              color="error"
              variant="soft"
              size="sm"
              :loading="pendingAction(candidate.id) === 'reject'"
              @click="handleReject(candidate)"
            >
              Отклонить
            </UButton>
          </div>
        </div>

        <!-- Inline merge form -->
        <div v-if="mergeTargetId === candidate.id" class="mt-4 flex flex-wrap items-end gap-3 border-t pt-4">
          <UFormField label="Целевой тег" class="flex-1">
            <USelectMenu
              v-model="mergeTag"
              :items="mergeOptions"
              value-key="value"
              placeholder="Поиск тегов…"
              @update:search-term="onMergeSearch"
            />
          </UFormField>
          <UCheckbox v-model="mergeCreateAlias" label="Добавить как синоним" />
          <UButton :disabled="!mergeTag" :loading="pendingAction(candidate.id) === 'merge'" @click="handleMerge(candidate)">
            Объединить
          </UButton>
          <UButton variant="ghost" @click="() => { mergeTargetId = null }">Отмена</UButton>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2">
      <UButton v-if="pagination.page > 1" variant="ghost" @click="loadCandidates(pagination.page - 1)"> Назад </UButton>
      <span class="flex items-center text-sm text-gray-600"> Страница {{ pagination.page }} из {{ pagination.last_page }} </span>
      <UButton v-if="pagination.page < pagination.last_page" variant="ghost" @click="loadCandidates(pagination.page + 1)">
        Вперёд
      </UButton>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

import type { PaginationMeta, Tag, TagCandidate, TagCandidateStatus } from '~/types/api'
import { CANDIDATE_RESOLUTION_LABELS, CANDIDATE_STATUS_LABELS, labelOf, pluralRu, TAG_TYPE_LABELS } from '~/utils/labels'

// «встречается на 1 изображении / 2 изображениях / 5 изображениях»
const IMAGE_FORMS_PREP = ['изображении', 'изображениях', 'изображениях'] as const

const candidatesApi = useCandidates()
const tagsApi = useTags()

const candidates = ref<TagCandidate[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const pagination = ref<PaginationMeta | null>(null)
const selectedStatus = ref<TagCandidateStatus>('pending')
const actionInFlight = ref<{ id: number; action: 'approve' | 'merge' | 'reject' } | null>(null)

const mergeTargetId = ref<number | null>(null)
const mergeTag = ref<number | undefined>()
const mergeCreateAlias = ref(true)
const mergeOptions = ref<{ label: string; value: number }[]>([])

// The API has no "all statuses" filter: a request without `status` returns only
// `pending` (contract: default pending), so an "all" option would be misleading.
const statusOptions: { label: string; value: TagCandidateStatus }[] = [
  ...(['pending', 'created', 'merged', 'rejected'] as const).map((value) => ({
    label: CANDIDATE_STATUS_LABELS[value],
    value,
  })),
]

function pendingAction(id: number) {
  return actionInFlight.value?.id === id ? actionInFlight.value.action : null
}

async function loadCandidates(page = 1) {
  loading.value = true
  loadError.value = null
  try {
    const data = await candidatesApi.listCandidates({
      page,
      per_page: 20,
      status: selectedStatus.value,
    })
    candidates.value = data.data
    pagination.value = data.meta
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loading.value = false
  }
}

async function handleApprove(candidate: TagCandidate) {
  actionInFlight.value = { id: candidate.id, action: 'approve' }
  actionError.value = null
  try {
    await candidatesApi.approveCandidate(candidate.id)
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionInFlight.value = null
  }
}

async function handleReject(candidate: TagCandidate) {
  actionInFlight.value = { id: candidate.id, action: 'reject' }
  actionError.value = null
  try {
    await candidatesApi.rejectCandidate(candidate.id)
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionInFlight.value = null
  }
}

function openMerge(candidate: TagCandidate) {
  mergeTargetId.value = candidate.id
  mergeTag.value = undefined
  mergeCreateAlias.value = true
  onMergeSearch('')
}

async function onMergeSearch(query: string) {
  try {
    const data = await tagsApi.listTags({ q: query || undefined, per_page: 20, status: 'active' })
    mergeOptions.value = data.data.map((tag: Tag) => ({ value: tag.id, label: `${tag.name} (${labelOf(TAG_TYPE_LABELS, tag.type)})` }))
  } catch (e) {
    // Non-fatal: merge search box just stays empty.
  }
}

async function handleMerge(candidate: TagCandidate) {
  if (!mergeTag.value) return
  actionInFlight.value = { id: candidate.id, action: 'merge' }
  actionError.value = null
  try {
    await candidatesApi.mergeCandidate(candidate.id, {
      tag_id: mergeTag.value,
      create_alias: mergeCreateAlias.value,
    })
    mergeTargetId.value = null
    await loadCandidates(pagination.value?.page || 1)
  } catch (e) {
    actionError.value = useApiError(e).message
  } finally {
    actionInFlight.value = null
  }
}

watch(selectedStatus, () => loadCandidates())

onMounted(() => {
  loadCandidates()
})
</script>

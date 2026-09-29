<template>
  <div class="space-y-6">
    <div class="page-heading">
      <span class="subtle-label">ОЧЕРЕДЬ ПРОВЕРКИ</span>
      <h1>Кандидаты в теги</h1>
      <p>Понятия, предложенные ИИ, которых ещё нет среди канонических тегов.</p>
    </div>

    <!-- Filters -->
    <div class="surface-card flex flex-wrap items-end gap-4 p-5">
      <UFormField label="Статус" class="min-w-[180px]">
        <USelect v-model="filters.status" :items="statusOptions" class="w-full" />
      </UFormField>
      <UFormField label="Тип" class="min-w-[160px]">
        <USelect v-model="filters.type" :items="typeOptions" class="w-full" />
      </UFormField>
      <UFormField label="Поиск" class="min-w-[200px] flex-1">
        <UInput v-model="searchText" icon="i-heroicons-magnifying-glass" placeholder="Название понятия" class="w-full" />
      </UFormField>
      <UFormField label="Сортировка" class="min-w-[180px]">
        <USelect v-model="filters.sort" :items="sortOptions" class="w-full" />
      </UFormField>
    </div>

    <p v-if="isPending" class="text-xs text-gray-500" data-testid="hotkeys-help">
      Клавиши: <kbd>↓</kbd>/<kbd>↑</kbd> — следующий/предыдущий, <kbd>Enter</kbd> — создать тег, <kbd>R</kbd> — отклонить,
      <kbd>M</kbd> — объединить, <kbd>X</kbd> — выбрать.
    </p>

    <div v-if="notice" role="status" aria-live="polite" data-testid="candidates-notice">
      <UAlert :color="notice.tone" variant="soft" :title="notice.message" close @update:open="notice = null" />
    </div>
    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />

    <!-- Selection bar -->
    <div v-if="isPending && candidates.length" class="flex flex-wrap items-center gap-3">
      <UCheckbox :model-value="pageSelection" label="Выбрать все на странице" @update:model-value="toggleAll" />
      <span v-if="pagination" class="text-sm text-gray-500">Всего в очереди: {{ pagination.total }}</span>
    </div>
    <section
      v-if="selectedCandidates.length"
      class="surface-card bulk-bar sticky top-3 z-20 flex flex-wrap items-center gap-2"
      aria-label="Действия с выбранными кандидатами"
      data-testid="candidates-bulk-bar"
    >
      <strong class="mr-2 text-sm" data-testid="candidates-bulk-count">Выбрано: {{ selectedCandidates.length }}</strong>
      <template v-if="!bulkRunning">
        <UButton size="sm" color="success" variant="soft" @click="runBulkAction('approve')">
          Создать теги ({{ selectedCandidates.length }})
        </UButton>
        <UButton
          size="sm"
          color="warning"
          variant="soft"
          :disabled="strongMatches.length === 0"
          @click="runBulkAction('merge-closest')"
        >
          Объединить с похожими ({{ strongMatches.length }})
        </UButton>
        <UButton size="sm" color="error" variant="soft" @click="runBulkAction('reject')">
          Отклонить ({{ selectedCandidates.length }})
        </UButton>
        <UButton size="sm" variant="ghost" color="neutral" class="ml-auto" @click="clearSelection">Снять выделение</UButton>
      </template>
      <span v-else class="text-sm text-gray-600" role="status">{{ bulkRunning.label }}… {{ bulkRunning.finished }} / {{ bulkRunning.total }}</span>
    </section>

    <!-- Candidates List -->
    <div v-if="loading && !candidates.length" class="text-center text-gray-600">Загрузка кандидатов…</div>

    <div v-else-if="candidates.length === 0 && !loadError" class="surface-card p-12 text-center">
      <p class="text-gray-600">По этому фильтру кандидатов нет.</p>
    </div>

    <div v-else class="space-y-4">
      <CandidateCard
        v-for="(candidate, index) in candidates"
        :key="candidate.id"
        :ref="(el) => setCardRef(candidate.id, el)"
        :candidate="candidate"
        :draft="drafts.get(candidate.id) ?? draftFor(candidate)"
        :active="index === activeIndex"
        :selected="selected.has(candidate.id)"
        :merge-open="mergeOpenId === candidate.id"
        :busy="busy.get(candidate.id) ?? null"
        :error="errors.get(candidate.id) ?? null"
        @update:draft="setDraft(candidate.id, $event)"
        @activate="activeIndex = index"
        @toggle-select="(shift: boolean) => onToggle(candidate.id, shift)"
        @approve="approve(candidate)"
        @reject="reject(candidate)"
        @open-merge="openMerge(candidate)"
        @close-merge="mergeOpenId = null"
        @merge="(tagId: number, alias: boolean) => merge(candidate, tagId, alias)"
      />
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

import type { PaginationMeta, TagCandidate, TagCandidateStatus, TagType } from '~/types/api'
import { runBulk } from '~/utils/bulkActions'
import { approveRequest, draftError, draftFor, isStrongMatch, moderationKey, type CandidateDraft } from '~/utils/candidateModeration'
import { toApiProblem } from '~/utils/errorHandler'
import { CANDIDATE_STATUS_LABELS, TAG_TYPE_LABELS } from '~/utils/labels'
import { pageSelectionState, toggleSelection } from '~/utils/selection'
import { TAG_TYPES } from '~/utils/tagForm'

const PER_PAGE = 30
const ALL = 'all' as const
type CandidateSort = '-occurrences_count' | '-created_at' | '-similarity'
type Action = 'approve' | 'reject' | 'merge'

const candidatesApi = useCandidates()

const candidates = ref<TagCandidate[]>([])
const pagination = ref<PaginationMeta | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const notice = ref<{ message: string; tone: 'success' | 'warning' } | null>(null)

// The API has no "all statuses" filter: a request without `status` returns only
// `pending` (contract: default pending), so an "all" option would be misleading.
const filters = reactive<{ status: TagCandidateStatus; type: TagType | typeof ALL; sort: CandidateSort; q: string }>({
  status: 'pending',
  type: ALL,
  // The most frequent concepts first: resolving them tags the most images.
  sort: '-occurrences_count',
  q: '',
})
const searchText = ref('')
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(searchText, (text) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => (filters.q = text.trim()), 300)
})

const statusOptions = (['pending', 'created', 'merged', 'rejected'] as const).map((value) => ({ label: CANDIDATE_STATUS_LABELS[value], value }))
const typeOptions = [{ label: 'Все типы', value: ALL }, ...TAG_TYPES.map((type) => ({ label: TAG_TYPE_LABELS[type], value: type }))]
const sortOptions: { label: string; value: CandidateSort }[] = [
  { label: 'Сначала частые', value: '-occurrences_count' },
  { label: 'Сначала новые', value: '-created_at' },
  { label: 'Сначала похожие на теги', value: '-similarity' },
]

const isPending = computed(() => filters.status === 'pending')

// Per-card state, keyed by candidate id.
const drafts = reactive(new Map<number, CandidateDraft>())
const busy = reactive(new Map<number, Action>())
const errors = reactive(new Map<number, string>())
const mergeOpenId = ref<number | null>(null)
const activeIndex = ref(0)

const selected = ref<Set<number>>(new Set())
let selectionAnchor: number | null = null
const pageIds = computed(() => candidates.value.map((candidate) => candidate.id))
const selectedCandidates = computed(() => candidates.value.filter((candidate) => selected.value.has(candidate.id)))
const pageSelection = computed(() => pageSelectionState(selected.value, pageIds.value))
const strongMatches = computed(() => selectedCandidates.value.filter(isStrongMatch))
const bulkRunning = ref<{ label: string; finished: number; total: number } | null>(null)

function setDraft(id: number, draft: CandidateDraft) {
  drafts.set(id, draft)
}

function draftOf(candidate: TagCandidate): CandidateDraft {
  return drafts.get(candidate.id) ?? draftFor(candidate)
}

function onToggle(id: number, shift: boolean) {
  selected.value = toggleSelection(selected.value, pageIds.value, id, { shift, anchor: selectionAnchor })
  selectionAnchor = id
}

function toggleAll(value: boolean | 'indeterminate') {
  selected.value = value === true ? new Set(pageIds.value) : new Set()
  selectionAnchor = null
}

function clearSelection() {
  selected.value = new Set()
  selectionAnchor = null
}

let loadSeq = 0
async function loadCandidates(page = 1) {
  const seq = ++loadSeq
  loading.value = true
  loadError.value = null
  try {
    const data = await candidatesApi.listCandidates({
      page,
      per_page: PER_PAGE,
      status: filters.status,
      type: filters.type === ALL ? undefined : filters.type,
      q: filters.q || undefined,
      sort: filters.sort,
    })
    if (seq !== loadSeq) return
    candidates.value = data.data
    pagination.value = data.meta
    activeIndex.value = 0
    mergeOpenId.value = null
    clearSelection()
    drafts.clear()
    errors.clear()
  } catch (e) {
    if (seq === loadSeq) loadError.value = useApiError(e).message
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

/** A resolved candidate leaves the pending queue at once; the next page is fetched when the page runs dry. */
function resolved(ids: number[]) {
  if (!ids.length) return
  const gone = new Set(ids)
  const activeId = candidates.value[activeIndex.value]?.id
  candidates.value = candidates.value.filter((candidate) => !gone.has(candidate.id))
  selected.value = new Set([...selected.value].filter((id) => !gone.has(id)))
  if (mergeOpenId.value !== null && gone.has(mergeOpenId.value)) mergeOpenId.value = null
  if (pagination.value) pagination.value = { ...pagination.value, total: Math.max(0, pagination.value.total - ids.length) }
  // Keep the cursor on the same position so the next candidate is ready for the next key press.
  const stillThere = candidates.value.findIndex((candidate) => candidate.id === activeId)
  activeIndex.value = stillThere >= 0 ? stillThere : Math.min(activeIndex.value, Math.max(0, candidates.value.length - 1))
  if (candidates.value.length === 0 && (pagination.value?.total ?? 0) > 0) void loadCandidates(1)
}

function problemText(error: unknown): string {
  const problem = toApiProblem(error)
  if (problem.code === 'TAG_ALREADY_EXISTS') return 'Такой тег уже есть — объедините кандидата с ним.'
  const fields = Object.values(problem.fields).flat()
  return fields.length ? `${problem.message} ${fields.join(' ')}` : problem.message
}

async function act(candidate: TagCandidate, action: Action, run: () => Promise<unknown>, done: string) {
  if (busy.has(candidate.id)) return
  busy.set(candidate.id, action)
  errors.delete(candidate.id)
  try {
    await run()
    notice.value = { message: done, tone: 'success' }
    resolved([candidate.id])
  } catch (e) {
    errors.set(candidate.id, problemText(e))
    const code = toApiProblem(e).code
    if (code === 'TAG_ALREADY_EXISTS') mergeOpenId.value = candidate.id
    // Resolved elsewhere meanwhile: the queue on screen is stale, the card goes and the reason stays in the notice.
    if (code === 'TAG_CANDIDATE_ALREADY_RESOLVED' || code === 'TAG_CANDIDATE_NOT_FOUND') {
      notice.value = { message: `«${candidate.display_name}»: ${problemText(e)}`, tone: 'warning' }
      resolved([candidate.id])
    }
  } finally {
    busy.delete(candidate.id)
  }
}

function approve(candidate: TagCandidate) {
  const draft = draftOf(candidate)
  const invalid = draftError(draft)
  if (invalid) {
    errors.set(candidate.id, invalid)
    return
  }
  const name = draft.name.trim()
  return act(candidate, 'approve', () => candidatesApi.approveCandidate(candidate.id, approveRequest(candidate, draft)), `Тег «${name}» создан.`)
}

function reject(candidate: TagCandidate) {
  return act(candidate, 'reject', () => candidatesApi.rejectCandidate(candidate.id), `«${candidate.display_name}» отклонён.`)
}

function merge(candidate: TagCandidate, tagId: number, createAlias: boolean) {
  return act(
    candidate,
    'merge',
    () => candidatesApi.mergeCandidate(candidate.id, { tag_id: tagId, create_alias: createAlias }),
    `«${candidate.display_name}» объединён с тегом.`,
  )
}

function openMerge(candidate: TagCandidate) {
  mergeOpenId.value = mergeOpenId.value === candidate.id ? null : candidate.id
}

async function runBulkAction(kind: 'approve' | 'reject' | 'merge-closest') {
  const targets = kind === 'merge-closest' ? strongMatches.value : selectedCandidates.value
  const invalid = kind === 'approve' ? targets.filter((candidate) => draftError(draftOf(candidate))) : []
  invalid.forEach((candidate) => errors.set(candidate.id, draftError(draftOf(candidate))!))
  const runnable = targets.filter((candidate) => !invalid.includes(candidate))
  if (!runnable.length) return

  const labels = { 'approve': 'Создание тегов', 'reject': 'Отклонение', 'merge-closest': 'Объединение' }
  const byId = new Map(runnable.map((candidate) => [candidate.id, candidate]))
  const worker = (id: number) => {
    const candidate = byId.get(id)!
    if (kind === 'approve') return candidatesApi.approveCandidate(id, approveRequest(candidate, draftOf(candidate)))
    if (kind === 'reject') return candidatesApi.rejectCandidate(id)
    return candidatesApi.mergeCandidate(id, { tag_id: candidate.closest_tag!.id, create_alias: true })
  }

  bulkRunning.value = { label: labels[kind], finished: 0, total: runnable.length }
  try {
    const result = await runBulk(runnable.map((candidate) => candidate.id), worker, (finished) => {
      if (bulkRunning.value) bulkRunning.value.finished = finished
    })
    result.failed.forEach((failure) => errors.set(failure.id, problemText(failure.error)))
    const doneLabel = { 'approve': 'Создано тегов', 'reject': 'Отклонено', 'merge-closest': 'Объединено' }[kind]
    const parts = [`${doneLabel}: ${result.done.length} из ${runnable.length}.`]
    if (result.failed.length || invalid.length) parts.push(`Не выполнено: ${result.failed.length + invalid.length} — причина указана на карточках.`)
    notice.value = { message: parts.join(' '), tone: result.failed.length || invalid.length ? 'warning' : 'success' }
    resolved(result.done)
  } finally {
    bulkRunning.value = null
  }
}

// Keyboard: the active card follows the cursor keys and takes the action keys.
const cardRefs = new Map<number, Element>()
function setCardRef(id: number, el: unknown) {
  const element = (el as { $el?: Element } | null)?.$el
  if (element) cardRefs.set(id, element)
  else cardRefs.delete(id)
}

watch(activeIndex, (index) => {
  const id = candidates.value[index]?.id
  if (id !== undefined) cardRefs.get(id)?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
})

function onKeydown(event: KeyboardEvent) {
  if (!isPending.value || bulkRunning.value) return
  const key = moderationKey(event)
  const candidate = candidates.value[activeIndex.value]
  if (!key || !candidate) return
  event.preventDefault()
  if (key === 'next') activeIndex.value = Math.min(activeIndex.value + 1, candidates.value.length - 1)
  else if (key === 'prev') activeIndex.value = Math.max(activeIndex.value - 1, 0)
  else if (key === 'approve') void approve(candidate)
  else if (key === 'reject') void reject(candidate)
  else if (key === 'merge') openMerge(candidate)
  else if (key === 'select') onToggle(candidate.id, false)
}

watch(filters, () => loadCandidates())

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  loadCandidates()
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  clearTimeout(searchTimer)
})
</script>

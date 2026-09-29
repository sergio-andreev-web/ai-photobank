<template>
  <article
    class="surface-card candidate-card"
    :class="{ 'is-active': active, 'is-selected': selected }"
    :aria-label="`Кандидат ${candidate.display_name}`"
    data-testid="candidate-card"
    :data-candidate-id="candidate.id"
    @click="$emit('activate')"
  >
    <div class="flex flex-col gap-4 lg:flex-row">
      <label v-if="pending" class="pt-1" @click.stop>
        <input
          type="checkbox"
          class="h-4 w-4 accent-blue-600"
          :checked="selected"
          :aria-label="`Выбрать ${candidate.display_name}`"
          @click="$emit('toggleSelect', $event.shiftKey)"
        />
      </label>

      <div class="min-w-0 flex-1">
        <p class="text-xl font-bold text-gray-900">{{ candidate.display_name }}</p>
        <p class="mt-1 text-sm text-gray-600">
          {{ labelOf(TAG_TYPE_LABELS, candidate.type) }} &middot; встречается на {{ candidate.occurrences_count }}
          {{ pluralRu(candidate.occurrences_count, ['изображении', 'изображениях', 'изображениях']) }}
        </p>

        <div v-if="candidate.sample_images.length" class="mt-3 flex flex-wrap gap-2">
          <img
            v-for="sample in candidate.sample_images"
            :key="sample.id"
            :src="sample.thumbnail_url ?? undefined"
            class="h-16 w-16 rounded object-cover"
            :alt="`Пример: изображение #${sample.id}`"
          />
        </div>

        <!-- Closest existing tag: a strong match is offered as a one-click merge. -->
        <div
          v-if="candidate.closest_tag"
          class="mt-3 flex flex-wrap items-center gap-2 rounded-lg p-2 text-sm"
          :class="strong ? 'bg-amber-50 text-amber-900' : 'text-gray-600'"
          data-testid="closest-tag"
        >
          <span>
            {{ strong ? 'Похоже на существующий тег' : 'Ближайший существующий тег' }}
            <strong>{{ candidate.closest_tag.name }}</strong>
            <template v-if="candidate.similarity != null"> (сходство {{ Math.round(candidate.similarity * 100) }}%)</template>
          </span>
          <UButton
            v-if="pending && strong"
            size="xs"
            color="warning"
            variant="soft"
            :loading="busy === 'merge'"
            @click.stop="$emit('merge', candidate.closest_tag.id, true)"
          >
            Объединить с «{{ candidate.closest_tag.name }}»
          </UButton>
        </div>

        <div v-if="!pending" class="mt-3 rounded bg-blue-50 p-3 text-sm text-blue-900">
          Решение: <strong>{{ labelOf(CANDIDATE_RESOLUTION_LABELS, candidate.status) }}</strong>
          <span v-if="candidate.resolved_tag_id"> → тег #{{ candidate.resolved_tag_id }}</span>
        </div>

        <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>
      </div>

      <!-- Tag to create: name, type and parent are editable before it exists. -->
      <form v-if="pending" class="grid w-full gap-2 lg:w-[25rem]" novalidate @submit.prevent="$emit('approve')" @click.stop>
        <UFormField label="Название тега" :error="nameError ?? undefined">
          <UInput :model-value="draft.name" class="w-full" maxlength="255" @update:model-value="update({ name: String($event) })" />
        </UFormField>
        <div class="grid grid-cols-2 gap-2">
          <UFormField label="Тип">
            <USelect :model-value="draft.type" :items="typeOptions" class="w-full" @update:model-value="update({ type: $event as TagType })" />
          </UFormField>
          <UFormField label="Родитель">
            <USelectMenu
              :model-value="draft.parent_id ?? undefined"
              :items="parentOptions"
              value-key="value"
              ignore-filter
              placeholder="Без родителя"
              class="w-full"
              @update:model-value="update({ parent_id: $event ?? null })"
              @update:search-term="searchParents"
              @update:open="(open: boolean) => open && !parentOptions.length && searchParents('')"
            />
          </UFormField>
        </div>
        <div class="flex flex-wrap gap-2">
          <UButton type="submit" size="sm" color="success" variant="soft" :disabled="!!nameError" :loading="busy === 'approve'">
            Создать тег
          </UButton>
          <UButton size="sm" color="warning" variant="soft" @click="$emit('openMerge')">Объединить с…</UButton>
          <UButton size="sm" color="error" variant="soft" :loading="busy === 'reject'" @click="$emit('reject')">Отклонить</UButton>
        </div>
      </form>
    </div>

    <div v-if="mergeOpen && pending" class="mt-4 flex flex-wrap items-end gap-3 border-t pt-4" @click.stop>
      <UFormField label="Целевой тег" class="min-w-[240px] flex-1" data-testid="merge-target">
        <USelectMenu
          v-model="mergeTag"
          :items="mergeOptions"
          value-key="value"
          ignore-filter
          placeholder="Поиск тегов…"
          class="w-full"
          @update:search-term="searchMerge"
        />
      </UFormField>
      <UCheckbox v-model="mergeAlias" label="Добавить как синоним" />
      <UButton :disabled="!mergeTag" :loading="busy === 'merge'" @click="$emit('merge', mergeTag!, mergeAlias)">Объединить</UButton>
      <UButton variant="ghost" @click="$emit('closeMerge')">Отмена</UButton>
    </div>
  </article>
</template>

<script setup lang="ts">
import type { Tag, TagCandidate, TagType } from '~/types/api'
import { draftError, isStrongMatch, type CandidateDraft } from '~/utils/candidateModeration'
import { CANDIDATE_RESOLUTION_LABELS, labelOf, pluralRu, TAG_TYPE_LABELS } from '~/utils/labels'
import { TAG_TYPES } from '~/utils/tagForm'

const props = defineProps<{
  candidate: TagCandidate
  draft: CandidateDraft
  active: boolean
  selected: boolean
  mergeOpen: boolean
  busy: 'approve' | 'reject' | 'merge' | null
  error: string | null
}>()

const emit = defineEmits<{
  'update:draft': [draft: CandidateDraft]
  activate: []
  toggleSelect: [shift: boolean]
  approve: []
  reject: []
  openMerge: []
  closeMerge: []
  merge: [tagId: number, createAlias: boolean]
}>()

const tagsApi = useTags()

const pending = computed(() => props.candidate.status === 'pending')
const strong = computed(() => isStrongMatch(props.candidate))
const nameError = computed(() => draftError(props.draft))
const typeOptions = TAG_TYPES.map(type => ({ label: TAG_TYPE_LABELS[type], value: type }))

function update(patch: Partial<CandidateDraft>) {
  emit('update:draft', { ...props.draft, ...patch })
}

function tagOption(tag: Pick<Tag, 'id' | 'name' | 'type'>) {
  return { value: tag.id, label: `${tag.name} (${labelOf(TAG_TYPE_LABELS, tag.type)})` }
}

async function searchTags(term: string) {
  const data = await tagsApi.listTags({ q: term.trim() || undefined, per_page: 20, status: 'active' })
  return data.data.map(tagOption)
}

const parentOptions = ref<{ value: number; label: string }[]>([])
async function searchParents(term: string) {
  try {
    parentOptions.value = await searchTags(term)
  } catch {
    // The parent picker degrades to an empty list.
  }
}

const mergeTag = ref<number | undefined>()
const mergeAlias = ref(true)
const mergeOptions = ref<{ value: number; label: string }[]>([])
async function searchMerge(term: string) {
  try {
    mergeOptions.value = await searchTags(term)
  } catch {
    // Non-fatal: the merge box stays empty.
  }
}

watch(
  () => props.mergeOpen,
  (open) => {
    if (!open) return
    // Preselect the closest tag: the usual merge target.
    mergeTag.value = props.candidate.closest_tag?.id
    mergeAlias.value = true
    void searchMerge('').then(() => {
      const closest = props.candidate.closest_tag
      if (closest && !mergeOptions.value.some(option => option.value === closest.id)) {
        mergeOptions.value = [tagOption(closest), ...mergeOptions.value]
      }
    })
  },
  { immediate: true },
)
</script>

<template>
  <section class="mt-3 rounded-xl border border-blue-200 bg-blue-50/60 p-4" :aria-label="`Понятие ${concept.label}`" data-testid="concept-tagger">
    <div class="mb-3 flex items-start justify-between gap-3">
      <p class="text-sm text-gray-800">
        Понятие ИИ <strong>{{ concept.label }}</strong> ({{ labelOf(TAG_TYPE_LABELS, concept.type) }},
        {{ Math.round(concept.confidence * 100) }}%) — добавить фото тег:
      </p>
      <UButton size="xs" variant="ghost" color="neutral" icon="i-heroicons-x-mark" aria-label="Закрыть" @click="$emit('close')" />
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <form class="space-y-2" novalidate @submit.prevent="attachExisting">
        <p class="text-xs font-semibold uppercase text-gray-500">Существующий тег</p>
        <USelectMenu
          v-model="existingId"
          v-model:search-term="searchTerm"
          :items="options"
          value-key="value"
          ignore-filter
          placeholder="Выберите тег"
          class="w-full"
          data-testid="concept-existing"
          @update:search-term="search"
        />
        <UButton type="submit" size="sm" variant="soft" :disabled="existingId === undefined" :loading="busy === 'existing'">
          Добавить к фото
        </UButton>
      </form>

      <form class="space-y-2" novalidate @submit.prevent="createAndAttach">
        <p class="text-xs font-semibold uppercase text-gray-500">Новый тег</p>
        <UInput v-model="name" aria-label="Название нового тега" maxlength="255" class="w-full" />
        <USelect v-model="type" :items="typeOptions" aria-label="Тип нового тега" class="w-full" />
        <UButton type="submit" size="sm" color="success" variant="soft" :disabled="!!nameError" :loading="busy === 'new'">
          Создать тег и добавить
        </UButton>
        <p v-if="nameError && name.length" class="text-xs text-red-600">{{ nameError }}</p>
      </form>
    </div>

    <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>
  </section>
</template>

<script setup lang="ts">
import type { Concept, Tag, TagType } from '~/types/api'
import { toApiProblem } from '~/utils/errorHandler'
import { labelOf, TAG_TYPE_LABELS } from '~/utils/labels'
import { TAG_TYPES, validateTagName } from '~/utils/tagForm'

const props = defineProps<{
  imageId: number
  concept: Concept
  /** Tags already on the image: not offered again. */
  assignedTagIds: readonly number[]
}>()

const emit = defineEmits<{
  /** A tag was attached; the page reloads the image. */
  done: [message: string]
  close: []
}>()

const imagesApi = useImages()
const tagsApi = useTags()

const typeOptions = TAG_TYPES.map(value => ({ label: TAG_TYPE_LABELS[value], value }))
const existingId = ref<number | undefined>()
const searchTerm = ref(props.concept.label)
const options = ref<{ value: number; label: string }[]>([])
const name = ref(props.concept.label)
const type = ref<TagType>(props.concept.type)
const busy = ref<'existing' | 'new' | null>(null)
const error = ref<string | null>(null)
const nameError = computed(() => validateTagName(name.value))

let searchSeq = 0
async function search(term: string) {
  const seq = ++searchSeq
  try {
    const data = await tagsApi.listTags({ q: term.trim() || undefined, per_page: 20, status: 'active' })
    if (seq !== searchSeq) return
    options.value = data.data
      .filter((tag: Tag) => !props.assignedTagIds.includes(tag.id))
      .map((tag: Tag) => ({ value: tag.id, label: `${tag.name} (${labelOf(TAG_TYPE_LABELS, tag.type)})` }))
  } catch {
    // The picker stays empty; creating a new tag still works.
  }
}

async function attach(tagId: number, label: string) {
  await imagesApi.addTag(props.imageId, tagId)
  emit('done', `Тег «${label}» добавлен к фото.`)
}

async function attachExisting() {
  if (existingId.value === undefined) return
  busy.value = 'existing'
  error.value = null
  try {
    const label = options.value.find(option => option.value === existingId.value)?.label ?? ''
    await attach(existingId.value, label.replace(/ \([^)]*\)$/, ''))
  } catch (e) {
    error.value = toApiProblem(e).message
  } finally {
    busy.value = null
  }
}

async function createAndAttach() {
  if (nameError.value) return
  busy.value = 'new'
  error.value = null
  const tagName = name.value.trim()
  try {
    let tagId: number
    try {
      tagId = (await tagsApi.createTag({ name: tagName, type: type.value, parent_id: null })).id
    } catch (e) {
      // The tag is already in the catalog: use it instead of failing.
      const problem = toApiProblem(e)
      const existing = problem.details.existing_tag_id
      if (problem.code !== 'TAG_ALREADY_EXISTS' || typeof existing !== 'number') throw e
      tagId = existing
    }
    await attach(tagId, tagName)
  } catch (e) {
    error.value = toApiProblem(e).message
  } finally {
    busy.value = null
  }
}

onMounted(async () => {
  await search(props.concept.label)
  // No tag matches the label: show the whole catalog rather than an empty list.
  if (!options.value.length) {
    searchTerm.value = ''
    await search('')
  }
})
</script>

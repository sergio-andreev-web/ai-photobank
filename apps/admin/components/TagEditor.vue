<template>
  <section ref="root" class="surface-card p-6" aria-label="Редактор тега">
    <div class="mb-5 flex items-start justify-between gap-3">
      <div>
        <span class="subtle-label">{{ tag ? 'РЕДАКТИРОВАНИЕ' : 'НОВЫЙ ТЕГ' }}</span>
        <h2 class="section-title mt-1">{{ tag ? `Тег «${tag.name}»` : 'Новый канонический тег' }}</h2>
      </div>
      <UButton
        variant="ghost"
        color="neutral"
        icon="i-heroicons-x-mark"
        aria-label="Закрыть редактор"
        @click="$emit('close')"
      />
    </div>

    <ErrorAlert
      v-if="problem"
      class="mb-4"
      :title="errorTitle"
      :message="problem.message"
      :details="problemDetails"
      @dismiss="problem = null"
    />

    <form class="space-y-4" novalidate @submit.prevent="save">
      <UFormField label="Название" name="name" required :error="fieldError('name')">
        <UInput v-model="form.name" placeholder="Например: Экскаватор" class="w-full" autofocus />
      </UFormField>
      <UFormField label="Тип" name="type" required :error="fieldError('type')">
        <USelect v-model="form.type" :items="typeOptions" class="w-full" />
      </UFormField>
      <UFormField label="Родительский тег" name="parent_id" :error="fieldError('parent_id')">
        <USelect v-model="form.parent" :items="parentOptions" class="w-full" />
      </UFormField>
      <div class="flex items-center gap-3">
        <UButton type="submit" :disabled="!canSave" :loading="saving">{{ tag ? 'Сохранить' : 'Создать тег' }}</UButton>
        <UButton variant="ghost" color="neutral" @click="$emit('close')">Отмена</UButton>
      </div>
    </form>

    <template v-if="tag">
      <div class="mt-6 flex items-center justify-between gap-3 rounded-lg bg-gray-50 p-3">
        <div class="text-sm">
          <p class="font-medium text-gray-900">
            Статус:
            <UBadge :color="archived ? 'warning' : 'success'" variant="soft" size="sm" data-testid="tag-status">
              {{ archived ? 'В архиве' : 'Активен' }}
            </UBadge>
          </p>
          <p class="mt-0.5 text-xs text-gray-500">
            {{ archived
              ? 'Остаётся на своих изображениях, но к новым не назначается.'
              : 'Архивный тег перестаёт назначаться, но остаётся на уже отмеченных изображениях.' }}
          </p>
        </div>
        <UButton class="shrink-0 whitespace-nowrap" variant="soft" color="neutral" :loading="statusBusy" @click="toggleStatus">
          {{ archived ? 'Восстановить' : 'В архив' }}
        </UButton>
      </div>

      <section class="mt-6" aria-label="Синонимы">
        <h3 class="section-title">Синонимы</h3>
        <p class="mt-1 text-xs text-gray-500">Другие названия, по которым этот тег находится в поиске и сопоставляется с понятиями ИИ.</p>

        <ul v-if="tag.aliases.length" class="mt-3 flex flex-wrap gap-2" data-testid="alias-list">
          <li
            v-for="alias in tag.aliases"
            :key="alias.id"
            class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white py-1 pl-3 pr-1.5 text-sm text-gray-800"
          >
            <span>{{ alias.alias }}</span>
            <span v-if="alias.locale" class="text-xs uppercase text-gray-500">{{ alias.locale }}</span>
            <button
              type="button"
              class="flex h-5 w-5 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-900 disabled:opacity-50"
              :aria-label="`Удалить синоним ${alias.alias}`"
              :disabled="removingAliasId === alias.id"
              @click="removeAlias(alias)"
            >
              <UIcon name="i-heroicons-x-mark" />
            </button>
          </li>
        </ul>
        <p v-else class="mt-3 text-sm text-gray-500">Синонимов пока нет.</p>

        <form class="mt-4 space-y-3" novalidate @submit.prevent="addAlias">
          <div class="grid grid-cols-[minmax(0,1fr)_6rem] gap-3">
            <UFormField label="Синоним" name="alias" :error="aliasFieldError('alias')">
              <UInput v-model="aliasForm.alias" placeholder="Например: каска" class="w-full" />
            </UFormField>
            <UFormField label="Язык" name="locale" :error="aliasFieldError('locale')">
              <UInput v-model="aliasForm.locale" placeholder="ru" maxlength="10" class="w-full" />
            </UFormField>
          </div>
          <UButton type="submit" variant="soft" icon="i-heroicons-plus" :loading="addingAlias">Добавить синоним</UButton>
        </form>
      </section>
    </template>
  </section>
</template>

<script setup lang="ts">
import type { Tag, TagAlias, TagType } from '~/types/api'
import { describeProblem, toApiProblem } from '~/utils/errorHandler'
import type { ApiProblem } from '~/utils/errorHandler'
import { labelOf, TAG_TYPE_LABELS } from '~/utils/labels'
import { buildTagPatch, TAG_TYPES, validateAliasInput, validateTagName } from '~/utils/tagForm'
import { buildTagPaths, descendantIds } from '~/utils/tagTree'

// Reka UI selects cannot hold an empty value, so "no parent" needs its own sentinel.
const NO_PARENT = 'none' as const

const props = withDefaults(
  defineProps<{
    /** The tag being edited; null starts a new one. */
    tag: Tag | null
    /** The whole catalog: parent choices and conflict lookups. */
    tags: readonly Tag[]
    /** Parent preselected when a child is being added. */
    initialParentId?: number | null
  }>(),
  { initialParentId: null },
)

const emit = defineEmits<{
  created: [tag: Tag]
  /** The tag changed on the server (fields, status or aliases); `notice` says what happened. */
  changed: [tag: Tag, notice: string]
  /** The tag no longer exists on the server; the catalog needs a reload. */
  stale: []
  close: []
}>()

const tagsApi = useTags()

const root = ref<HTMLElement>()
const form = reactive<{ name: string; type: TagType; parent: number | typeof NO_PARENT }>({
  name: props.tag?.name ?? '',
  type: props.tag?.type ?? 'object',
  parent: props.tag ? (props.tag.parent_id ?? NO_PARENT) : (props.initialParentId ?? NO_PARENT),
})
const aliasForm = reactive({ alias: '', locale: '' })

const saving = ref(false)
const statusBusy = ref(false)
const addingAlias = ref(false)
const removingAliasId = ref<number | null>(null)

const problem = ref<ApiProblem | null>(null)
const errorTitle = ref('Ошибка')
const localErrors = reactive<{ alias: string | null; locale: string | null }>({ alias: null, locale: null })

// A blank name disables the submit button (and a disabled button ignores Enter), so once the
// field has been touched the reason is shown right under it.
const nameTouched = ref(false)
watch(() => form.name, () => { nameTouched.value = true })
const nameError = computed(() => (nameTouched.value ? validateTagName(form.name) : null))

const archived = computed(() => props.tag?.status === 'archived')
const parentId = computed(() => (form.parent === NO_PARENT ? null : form.parent))

const typeOptions = TAG_TYPES.map(type => ({ label: TAG_TYPE_LABELS[type], value: type }))

const tagsById = computed(() => new Map(props.tags.map(tag => [tag.id, tag])))

// A tag cannot become its own parent or a child of its descendant, and archived tags
// cannot be parents; the server enforces this too (422 TAG_PARENT_INVALID), the picker
// just doesn't offer choices that would be rejected. The current parent stays listed
// so an untouched form still shows it.
const parentOptions = computed(() => {
  const excluded = new Set<number>()
  if (props.tag) {
    excluded.add(props.tag.id)
    for (const id of descendantIds(props.tags, props.tag.id)) excluded.add(id)
  }
  const paths = buildTagPaths(props.tags)
  const options = props.tags
    .filter(tag => !excluded.has(tag.id) && (tag.status === 'active' || tag.id === props.tag?.parent_id))
    .map(tag => ({
      value: tag.id,
      label: `${paths.get(tag.id) ?? tag.name} (${labelOf(TAG_TYPE_LABELS, tag.type)})${tag.status === 'archived' ? ', в архиве' : ''}`,
    }))
    .sort((a, b) => a.label.localeCompare(b.label))
  return [{ label: 'Без родителя', value: NO_PARENT }, ...options]
})

const canSave = computed(() => {
  if (validateTagName(form.name)) return false
  if (!props.tag) return true
  return Object.keys(buildTagPatch(props.tag, { name: form.name, type: form.type, parent_id: parentId.value })).length > 0
})

const problemDetails = computed(() => {
  if (!problem.value) return undefined
  const known = ['name', 'type', 'parent_id', 'alias', 'locale']
  const text = describeProblem(problem.value, {
    knownFields: known,
    lookupTag: (id) => {
      const found = tagsById.value.get(id)
      return found ? `${found.name}, ${labelOf(TAG_TYPE_LABELS, found.type)}` : null
    },
  })
  return text || undefined
})

function fieldError(field: 'name' | 'type' | 'parent_id'): string | undefined {
  if (field === 'name' && nameError.value) return nameError.value
  return problem.value?.fields[field]?.join(' ')
}

function aliasFieldError(field: 'alias' | 'locale'): string | undefined {
  return localErrors[field] ?? problem.value?.fields[field]?.join(' ')
}

function fail(title: string, error: unknown) {
  const next = toApiProblem(error)
  errorTitle.value = title
  problem.value = next
  if (next.code === 'TAG_NOT_FOUND') emit('stale')
}

function clearErrors() {
  problem.value = null
  localErrors.alias = localErrors.locale = null
}

async function save() {
  clearErrors()
  if (validateTagName(form.name)) return

  saving.value = true
  try {
    if (props.tag) {
      const patch = buildTagPatch(props.tag, { name: form.name, type: form.type, parent_id: parentId.value })
      if (Object.keys(patch).length === 0) return
      const updated = await tagsApi.updateTag(props.tag.id, patch)
      emit('changed', updated, `Изменения тега «${updated.name}» сохранены.`)
    } else {
      const created = await tagsApi.createTag({ name: form.name.trim(), type: form.type, parent_id: parentId.value })
      emit('created', created)
    }
  } catch (e) {
    fail(props.tag ? 'Не удалось сохранить тег' : 'Не удалось создать тег', e)
  } finally {
    saving.value = false
  }
}

async function toggleStatus() {
  if (!props.tag) return
  clearErrors()
  statusBusy.value = true
  try {
    const restoring = archived.value
    const updated = await tagsApi.updateTag(props.tag.id, { status: restoring ? 'active' : 'archived' })
    emit('changed', updated, restoring ? `«${updated.name}» восстановлен.` : `«${updated.name}» перенесён в архив.`)
  } catch (e) {
    fail(archived.value ? 'Не удалось восстановить тег' : 'Не удалось перенести тег в архив', e)
  } finally {
    statusBusy.value = false
  }
}

async function addAlias() {
  if (!props.tag) return
  clearErrors()
  const checked = validateAliasInput(aliasForm)
  localErrors.alias = checked.alias
  localErrors.locale = checked.locale
  if (checked.alias || checked.locale) return

  addingAlias.value = true
  try {
    const created = await tagsApi.createAlias(props.tag.id, aliasForm.alias.trim(), aliasForm.locale.trim() || undefined)
    aliasForm.alias = ''
    aliasForm.locale = ''
    emit('changed', { ...props.tag, aliases: [...props.tag.aliases, created] }, `Синоним «${created.alias}» добавлен.`)
  } catch (e) {
    fail('Не удалось добавить синоним', e)
  } finally {
    addingAlias.value = false
  }
}

async function removeAlias(alias: TagAlias) {
  if (!props.tag) return
  clearErrors()
  removingAliasId.value = alias.id
  try {
    await tagsApi.deleteAlias(props.tag.id, alias.id)
    emit(
      'changed',
      { ...props.tag, aliases: props.tag.aliases.filter(existing => existing.id !== alias.id) },
      `Синоним «${alias.alias}» удалён.`,
    )
  } catch (e) {
    fail('Не удалось удалить синоним', e)
  } finally {
    removingAliasId.value = null
  }
}

onMounted(() => {
  // On narrow screens the editor sits above a long list; bring it into view.
  root.value?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
})
</script>

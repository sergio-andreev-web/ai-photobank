<template>
  <section class="rounded-xl border border-slate-200 p-5" aria-label="Люди на фото" data-testid="image-faces">
    <h3 class="section-title">Люди на фото</h3>
    <p v-if="!faces.length" class="mt-2 text-sm text-gray-500">Лиц не найдено.</p>
    <ul class="mt-3 space-y-3">
      <li v-for="face in faces" :key="face.id" class="flex flex-wrap items-center gap-3" data-testid="image-face">
        <FaceThumb :face="face" :size="56" />
        <div class="min-w-[10rem] flex-1 text-sm">
          <template v-if="face.person && (face.status === 'auto' || face.status === 'confirmed')">
            <NuxtLink :to="`/people/${face.person.id}`" class="font-semibold text-blue-600 hover:text-blue-700">{{ face.person.name }}</NuxtLink>
            <p class="text-xs text-gray-500">{{ FACE_STATUS_LABELS[face.status] }}</p>
          </template>
          <template v-else-if="face.person && face.status === 'suggested'">
            <p>Это <strong>{{ face.person.name }}</strong>?</p>
            <p class="text-xs text-gray-500">Сходство {{ Math.round((face.similarity ?? 0) * 100) }}%</p>
          </template>
          <p v-else class="text-gray-500">{{ FACE_STATUS_LABELS.unassigned }}</p>
        </div>

        <div class="flex flex-wrap gap-1">
          <UButton
            v-if="face.status === 'suggested' && face.person"
            size="xs"
            color="success"
            variant="soft"
            icon="i-heroicons-check"
            :loading="busy === face.id"
            @click="assign(face, { personId: face.person.id })"
          >
            Да
          </UButton>
          <UButton
            v-if="face.person"
            size="xs"
            color="error"
            variant="soft"
            icon="i-heroicons-x-mark"
            :loading="busy === -face.id"
            @click="reject(face)"
          >
            {{ face.status === 'suggested' ? 'Нет' : 'Это не он(а)' }}
          </UButton>
          <UButton v-if="!face.person && naming !== face.id" size="xs" variant="soft" icon="i-heroicons-user-plus" @click="startNaming(face)">
            Кто это?
          </UButton>
        </div>

        <form v-if="naming === face.id" class="flex w-full flex-wrap items-end gap-2" novalidate @submit.prevent="submitNaming(face)">
          <UFormField label="Человек из справочника" class="min-w-[12rem] flex-1">
            <USelectMenu
              v-model="pickedPerson"
              :items="personOptions"
              value-key="value"
              ignore-filter
              placeholder="Выберите"
              class="w-full"
              @update:search-term="searchPeople"
            />
          </UFormField>
          <span class="pb-2 text-xs text-gray-400">или</span>
          <UFormField label="Новый человек" class="min-w-[12rem] flex-1">
            <UInput v-model="newName" placeholder="Имя" maxlength="255" class="w-full" @update:model-value="() => { pickedPerson = undefined }" />
          </UFormField>
          <UButton type="submit" size="sm" :disabled="pickedPerson === undefined && !newName.trim()" :loading="busy === face.id">Запомнить</UButton>
          <UButton size="sm" variant="ghost" color="neutral" @click="() => { naming = null }">Отмена</UButton>
        </form>
      </li>
    </ul>
    <p v-if="error" class="mt-2 text-sm text-red-600" role="alert">{{ error }}</p>
  </section>
</template>

<script setup lang="ts">
import type { Face } from '~/types/api'
import { FACE_STATUS_LABELS } from '~/utils/labels'

const props = defineProps<{ imageId: number }>()
const emit = defineEmits<{ loaded: [faces: Face[]]; changed: [message: string] }>()

const peopleApi = usePeople()
const faces = ref<Face[]>([])
const busy = ref<number | null>(null)
const error = ref<string | null>(null)
const naming = ref<number | null>(null)
const pickedPerson = ref<number | undefined>()
const newName = ref('')
const personOptions = ref<{ label: string; value: number }[]>([])

async function load() {
  try {
    faces.value = await peopleApi.imageFaces(props.imageId)
    emit('loaded', faces.value)
  } catch {
    // Secondary block: the image page works without it.
    faces.value = []
    emit('loaded', [])
  }
}

let searchSeq = 0
async function searchPeople(term: string) {
  const seq = ++searchSeq
  try {
    const data = await peopleApi.listPeople({ per_page: 20, q: term.trim() || undefined })
    if (seq === searchSeq) personOptions.value = data.data.map((p) => ({ label: p.name, value: p.id }))
  } catch {
    // The picker stays empty; a new name still works.
  }
}

function startNaming(face: Face) {
  naming.value = face.id
  pickedPerson.value = undefined
  newName.value = ''
  void searchPeople('')
}

async function assign(face: Face, target: { personId: number } | { name: string }) {
  busy.value = face.id
  error.value = null
  try {
    const result = await peopleApi.assignFaces([face.id], target)
    naming.value = null
    emit('changed', `Запомнено: ${result.person.name}.${result.auto_count ? ` Автоматически подписано ещё фото: ${result.auto_count}.` : ''}`)
    await load()
  } catch (e) {
    error.value = useApiError(e).message
  } finally {
    busy.value = null
  }
}

function submitNaming(face: Face) {
  if (pickedPerson.value !== undefined) return assign(face, { personId: pickedPerson.value })
  if (newName.value.trim()) return assign(face, { name: newName.value })
}

async function reject(face: Face) {
  busy.value = -face.id
  error.value = null
  try {
    await peopleApi.rejectFace(face.id)
    emit('changed', 'Отмечено: это другой человек.')
    await load()
  } catch (e) {
    error.value = useApiError(e).message
  } finally {
    busy.value = null
  }
}

watch(() => props.imageId, load)
onMounted(load)
</script>

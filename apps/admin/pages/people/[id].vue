<template>
  <div class="space-y-6">
    <NuxtLink to="/people" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-700">
      <UIcon name="i-heroicons-arrow-left" /> Все люди
    </NuxtLink>

    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />
    <div v-if="notice" role="status" aria-live="polite" data-testid="person-notice">
      <UAlert :color="notice.tone" variant="soft" :title="notice.message" close @update:open="notice = null" />
    </div>

    <div v-if="person" class="surface-card flex flex-wrap items-center gap-5 p-6">
      <FaceThumb v-if="person.cover" :face="person.cover" :size="96" />
      <div class="min-w-[14rem] flex-1">
        <form v-if="renaming" class="flex flex-wrap items-end gap-2" novalidate @submit.prevent="rename">
          <UFormField label="Имя" class="min-w-[14rem] flex-1">
            <UInput v-model="nameDraft" maxlength="255" autofocus class="w-full" />
          </UFormField>
          <UButton type="submit" size="sm" :disabled="!nameDraft.trim()" :loading="busy === 'rename'">Сохранить</UButton>
          <UButton size="sm" variant="ghost" color="neutral" @click="() => { renaming = false }">Отмена</UButton>
        </form>
        <div v-else class="flex items-center gap-2">
          <h1 class="text-2xl font-bold text-gray-900" data-testid="person-name">{{ person.name }}</h1>
          <UButton size="xs" variant="ghost" color="neutral" icon="i-heroicons-pencil-square" aria-label="Переименовать" @click="startRename" />
        </div>
        <p class="mt-1 text-sm text-gray-500">
          {{ person.images_count }} фото · эталонов: {{ person.faces_count }}
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <UButton :to="`/gallery?person_id=${person.id}`" variant="soft" icon="i-heroicons-photo">Все фото</UButton>
        <UButton v-if="!confirmingDelete" variant="soft" color="error" icon="i-heroicons-trash" @click="() => { confirmingDelete = true }">
          Удалить
        </UButton>
      </div>
      <section v-if="confirmingDelete" class="w-full rounded-xl border border-red-200 bg-red-50 p-4" aria-label="Подтверждение удаления">
        <p class="font-semibold text-red-900">Удалить «{{ person.name }}»?</p>
        <p class="mt-1 text-sm text-red-700">
          Будут удалены человек и все его лица — биометрические данные (152-ФЗ). Фото останутся в библиотеке. Это необратимо.
        </p>
        <div class="mt-3 flex gap-2">
          <UButton color="error" size="sm" :loading="busy === 'delete'" @click="remove">Удалить навсегда</UButton>
          <UButton variant="ghost" color="neutral" size="sm" @click="() => { confirmingDelete = false }">Отмена</UButton>
        </div>
      </section>
    </div>

    <section
      v-for="section in sections"
      :key="section.status"
      class="surface-card space-y-3 p-6"
      :aria-label="section.title"
      :data-testid="`faces-${section.status}`"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="section-title">{{ section.title }} ({{ faces[section.status].length }})</h2>
          <p class="text-xs text-gray-500">{{ section.hint }}</p>
        </div>
        <UButton
          v-if="section.status === 'suggested' && faces.suggested.length"
          size="sm"
          color="success"
          variant="soft"
          icon="i-heroicons-check"
          :loading="busy === 'confirm-all'"
          @click="confirm(faces.suggested.map((face) => face.id), 'confirm-all')"
        >
          Подтвердить все
        </UButton>
      </div>
      <p v-if="!faces[section.status].length" class="text-sm text-gray-500">{{ section.empty }}</p>
      <div class="flex flex-wrap gap-4">
        <div v-for="face in faces[section.status]" :key="face.id" class="w-28 space-y-1" data-testid="person-face">
          <NuxtLink :to="`/gallery/${face.image.id}`" :title="face.image.original_name">
            <FaceThumb :face="face" :size="112" />
          </NuxtLink>
          <p v-if="face.similarity != null" class="text-xs text-gray-500">Сходство {{ Math.round(face.similarity * 100) }}%</p>
          <div class="flex gap-1">
            <UButton
              v-if="face.status !== 'confirmed'"
              size="xs"
              color="success"
              variant="soft"
              icon="i-heroicons-check"
              :aria-label="`Да, это ${person?.name}`"
              :loading="busy === `face-${face.id}`"
              @click="confirm([face.id], `face-${face.id}`)"
            />
            <UButton
              size="xs"
              color="error"
              variant="soft"
              icon="i-heroicons-x-mark"
              :aria-label="`Нет, это не ${person?.name}`"
              :loading="busy === `reject-${face.id}`"
              @click="reject(face)"
            />
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

import type { Face, FaceStatus, Person } from '~/types/api'

const route = useRoute()
const peopleApi = usePeople()
const personId = computed(() => Number(route.params.id))

const person = ref<Person | null>(null)
const faces = reactive<Record<'suggested' | 'auto' | 'confirmed', Face[]>>({ suggested: [], auto: [], confirmed: [] })
const loadError = ref<string | null>(null)
const notice = ref<{ message: string; tone: 'success' | 'warning' } | null>(null)
const busy = ref<string | null>(null)
const renaming = ref(false)
const nameDraft = ref('')
const confirmingDelete = ref(false)

const sections: { status: 'suggested' | 'auto' | 'confirmed'; title: string; hint: string; empty: string }[] = [
  { status: 'suggested', title: 'На подтверждение', hint: 'Похоже на этого человека, но не наверняка.', empty: 'Нечего подтверждать.' },
  { status: 'auto', title: 'Узнан автоматически', hint: 'Уверенное совпадение. Если ошибка — нажмите ✕.', empty: 'Пока нет.' },
  { status: 'confirmed', title: 'Эталоны', hint: 'Подтверждённые лица: по ним узнаются новые фото.', empty: 'Эталонов нет.' },
]

async function load() {
  loadError.value = null
  try {
    const statuses: FaceStatus[] = ['suggested', 'auto', 'confirmed']
    const [p, ...lists] = await Promise.all([
      peopleApi.getPerson(personId.value),
      ...statuses.map((status) => peopleApi.personFaces(personId.value, { status, per_page: 100 })),
    ])
    person.value = p as Person
    statuses.forEach((status, i) => { faces[status as 'suggested'] = (lists[i] as { data: Face[] }).data })
  } catch (e) {
    loadError.value = useApiError(e).message
  }
}

async function confirm(ids: number[], key: string) {
  busy.value = key
  try {
    const result = await peopleApi.assignFaces(ids, { personId: personId.value })
    notice.value = {
      message: `Подтверждено: ${result.assigned_count}.${result.auto_count ? ` Автоматически подписано ещё: ${result.auto_count}.` : ''}`,
      tone: 'success',
    }
    await load()
  } catch (e) {
    notice.value = { message: useApiError(e).message, tone: 'warning' }
  } finally {
    busy.value = null
  }
}

async function reject(face: Face) {
  busy.value = `reject-${face.id}`
  try {
    await peopleApi.rejectFace(face.id)
    notice.value = { message: 'Отмечено: это другой человек. Больше не предложим.', tone: 'success' }
    await load()
  } catch (e) {
    notice.value = { message: useApiError(e).message, tone: 'warning' }
  } finally {
    busy.value = null
  }
}

function startRename() {
  nameDraft.value = person.value?.name ?? ''
  renaming.value = true
}

async function rename() {
  busy.value = 'rename'
  try {
    person.value = await peopleApi.updatePerson(personId.value, { name: nameDraft.value.trim() })
    renaming.value = false
  } catch (e) {
    notice.value = { message: useApiError(e).message, tone: 'warning' }
  } finally {
    busy.value = null
  }
}

async function remove() {
  busy.value = 'delete'
  try {
    await peopleApi.deletePerson(personId.value)
    await navigateTo('/people')
  } catch (e) {
    notice.value = { message: useApiError(e).message, tone: 'warning' }
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div class="page-heading">
      <span class="subtle-label">РАСПОЗНАВАНИЕ ЛИЦ</span>
      <h1>Люди</h1>
      <p>Назовите человека один раз — дальше его фото будут подписываться автоматически.</p>
    </div>

    <div v-if="notice" role="status" aria-live="polite" data-testid="people-notice">
      <UAlert :color="notice.tone" variant="soft" :title="notice.message" close @update:open="notice = null" />
    </div>
    <ErrorAlert v-if="loadError" :message="loadError" @dismiss="loadError = null" />

    <!-- Unknown people: groups of similar unassigned faces -->
    <section class="space-y-3" aria-label="Новые люди" data-testid="new-people">
      <div class="flex items-end justify-between gap-3">
        <div>
          <span class="subtle-label">НОВЫЕ ЛЮДИ</span>
          <h2 class="section-title mt-1">Кто это?</h2>
          <p class="text-sm text-gray-500">Один и тот же человек встречается на нескольких фото. Уберите лишние лица и введите имя.</p>
        </div>
        <UButton size="sm" variant="ghost" color="neutral" icon="i-heroicons-arrow-path" :loading="loadingClusters" @click="loadClusters">
          Обновить
        </UButton>
      </div>

      <p v-if="!loadingClusters && !clusters.length" class="surface-card p-6 text-sm text-gray-600">
        Новых людей пока нет. Они появятся, когда одно лицо встретится хотя бы на двух фото.
      </p>

      <article
        v-for="(cluster, index) in clusters"
        :key="cluster.faces[0]!.id"
        class="surface-card p-5"
        data-testid="cluster-card"
      >
        <p class="mb-3 text-sm text-gray-600">
          Похоже, это один человек — на {{ cluster.images_count }} фото.
        </p>
        <div class="flex flex-wrap gap-3">
          <label
            v-for="face in cluster.faces"
            :key="face.id"
            class="relative cursor-pointer"
            :class="{ 'opacity-40': !isPicked(index, face.id) }"
          >
            <FaceThumb :face="face" :size="88" />
            <input
              type="checkbox"
              class="absolute left-1.5 top-1.5 h-4 w-4 accent-blue-600"
              :checked="isPicked(index, face.id)"
              :aria-label="`Лицо с фото ${face.image.original_name}`"
              @change="togglePick(index, face.id)"
            />
          </label>
        </div>
        <form class="mt-4 flex flex-wrap items-end gap-2" novalidate @submit.prevent="remember(index)">
          <UFormField label="Как зовут?" class="min-w-[240px] flex-1">
            <UInput v-model="names[index]" placeholder="Например: Иван Петров" maxlength="255" class="w-full" />
          </UFormField>
          <UButton
            type="submit"
            icon="i-heroicons-check"
            :loading="savingIndex === index"
            :disabled="!names[index]?.trim() || !pickedIds(index).length"
          >
            Запомнить ({{ pickedIds(index).length }})
          </UButton>
        </form>
      </article>
    </section>

    <!-- Directory -->
    <section class="space-y-3" aria-label="Справочник людей" data-testid="people-directory">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <span class="subtle-label">СПРАВОЧНИК</span>
          <h2 class="section-title mt-1">Известные люди</h2>
        </div>
        <UInput v-model="query" icon="i-heroicons-magnifying-glass" placeholder="Поиск по имени" class="w-64" aria-label="Поиск по имени" />
      </div>

      <p v-if="!loadingPeople && !people.length" class="surface-card p-6 text-sm text-gray-600">
        {{ query.trim() ? 'Никого не нашли.' : 'Пока никого. Назовите человека в блоке «Кто это?» выше.' }}
      </p>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <NuxtLink
          v-for="person in people"
          :key="person.id"
          :to="`/people/${person.id}`"
          class="surface-card flex items-center gap-4 p-4 transition-shadow hover:shadow-lg"
          data-testid="person-card"
        >
          <FaceThumb v-if="person.cover" :face="person.cover" :size="64" />
          <span v-else class="face-thumb flex items-center justify-center text-2xl text-slate-400" style="width: 64px; height: 64px">
            <UIcon name="i-heroicons-user" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate font-semibold text-gray-900">{{ person.name }}</p>
            <p class="text-sm text-gray-500">{{ person.images_count }} фото</p>
            <UBadge v-if="person.suggested_count" color="warning" variant="soft" size="sm" class="mt-1">
              На подтверждение: {{ person.suggested_count }}
            </UBadge>
          </div>
        </NuxtLink>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

import type { FaceCluster, Person } from '~/types/api'

const peopleApi = usePeople()

const clusters = ref<FaceCluster[]>([])
const people = ref<Person[]>([])
const query = ref('')
const names = ref<string[]>([])
// Faces left out of a group by the admin, per group index.
const excluded = ref<Set<number>[]>([])
const loadingClusters = ref(false)
const loadingPeople = ref(false)
const savingIndex = ref<number | null>(null)
const loadError = ref<string | null>(null)
const notice = ref<{ message: string; tone: 'success' | 'warning' } | null>(null)

function isPicked(index: number, faceId: number) {
  return !excluded.value[index]?.has(faceId)
}

function togglePick(index: number, faceId: number) {
  const next = new Set(excluded.value[index])
  if (!next.delete(faceId)) next.add(faceId)
  excluded.value[index] = next
}

function pickedIds(index: number): number[] {
  return (clusters.value[index]?.faces ?? []).map((face) => face.id).filter((id) => isPicked(index, id))
}

async function loadClusters() {
  loadingClusters.value = true
  try {
    clusters.value = await peopleApi.clusters()
    names.value = clusters.value.map(() => '')
    excluded.value = clusters.value.map(() => new Set())
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    loadingClusters.value = false
  }
}

let peopleSeq = 0
async function loadPeople() {
  const seq = ++peopleSeq
  loadingPeople.value = true
  try {
    const data = await peopleApi.listPeople({ per_page: 100, q: query.value.trim() || undefined })
    if (seq === peopleSeq) people.value = data.data
  } catch (e) {
    loadError.value = useApiError(e).message
  } finally {
    if (seq === peopleSeq) loadingPeople.value = false
  }
}

async function remember(index: number) {
  const name = names.value[index]?.trim()
  const ids = pickedIds(index)
  if (!name || !ids.length) return
  savingIndex.value = index
  try {
    const result = await peopleApi.assignFaces(ids, { name })
    const parts = [`«${result.person.name}» запомнен(а) по ${result.assigned_count} лицам.`]
    if (result.auto_count) parts.push(`Автоматически подписано ещё: ${result.auto_count}.`)
    if (result.suggested_count) parts.push(`На подтверждение: ${result.suggested_count}.`)
    notice.value = { message: parts.join(' '), tone: 'success' }
    await Promise.all([loadClusters(), loadPeople()])
  } catch (e) {
    notice.value = { message: useApiError(e).message, tone: 'warning' }
  } finally {
    savingIndex.value = null
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(query, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadPeople, 250)
})

onMounted(() => {
  loadClusters()
  loadPeople()
})
onBeforeUnmount(() => clearTimeout(searchTimer))
</script>

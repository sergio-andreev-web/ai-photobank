<template>
  <div
    ref="root"
    class="lightbox"
    role="dialog"
    aria-modal="true"
    :aria-label="`Просмотр: ${image.original_name}`"
    tabindex="-1"
    data-testid="lightbox"
    @click.self="$emit('close')"
  >
    <header class="lightbox-bar">
      <div class="min-w-0">
        <p class="truncate font-semibold" data-testid="lightbox-name">{{ image.original_name }}</p>
        <p class="text-xs text-white/70">
          <span data-testid="lightbox-counter">{{ index + 1 }} / {{ images.length }}</span>
          · {{ labelOf(IMAGE_STATUS_LABELS, image.status) }}
          <template v-if="image.tags.length"> · {{ image.tags.map(tag => tag.name).join(', ') }}</template>
        </p>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <UButton :to="`/gallery/${image.id}`" size="sm" color="neutral" variant="soft" icon="i-heroicons-arrow-top-right-on-square">
          Открыть карточку
        </UButton>
        <UButton size="sm" color="neutral" variant="soft" icon="i-heroicons-x-mark" aria-label="Закрыть просмотр" @click="$emit('close')" />
      </div>
    </header>

    <button
      type="button"
      class="lightbox-nav left-3"
      aria-label="Предыдущее изображение"
      :disabled="index === 0"
      @click="go(-1)"
    >
      <UIcon name="i-heroicons-chevron-left" />
    </button>

    <div class="lightbox-stage" @click.self="$emit('close')">
      <img v-if="src" :key="image.id" :src="src" :alt="image.original_name" class="lightbox-image" />
      <div v-else class="text-6xl text-white/40"><UIcon name="i-heroicons-photo" /></div>
    </div>

    <button
      type="button"
      class="lightbox-nav right-3"
      aria-label="Следующее изображение"
      :disabled="index === images.length - 1"
      @click="go(1)"
    >
      <UIcon name="i-heroicons-chevron-right" />
    </button>
  </div>
</template>

<script setup lang="ts">
import type { ImageSummary } from '~/types/api'
import { IMAGE_STATUS_LABELS, labelOf } from '~/utils/labels'

const props = defineProps<{
  /** Images of the current gallery page, in grid order. */
  images: readonly ImageSummary[]
  index: number
}>()

const emit = defineEmits<{
  'update:index': [index: number]
  close: []
}>()

const root = ref<HTMLElement>()
const image = computed(() => props.images[props.index]!)
// The preview variant fits a screen; the original can be tens of megabytes.
const src = computed(() => image.value.urls.preview ?? image.value.urls.thumbnail)

function go(delta: number) {
  const next = props.index + delta
  if (next >= 0 && next < props.images.length) emit('update:index', next)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowLeft') go(-1)
  else if (event.key === 'ArrowRight') go(1)
  else if (event.key === 'Escape') emit('close')
  else return
  event.preventDefault()
}

// Neighbours are fetched ahead, so paging through with the arrows does not wait for each preview.
watch(
  () => props.index,
  (index) => {
    for (const neighbour of [props.images[index - 1], props.images[index + 1]]) {
      const url = neighbour?.urls.preview
      if (url) new Image().src = url
    }
  },
  { immediate: true },
)

let previousFocus: Element | null = null
onMounted(() => {
  previousFocus = document.activeElement
  window.addEventListener('keydown', onKeydown)
  document.body.style.overflow = 'hidden'
  root.value?.focus()
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
  if (previousFocus instanceof HTMLElement) previousFocus.focus()
})
</script>

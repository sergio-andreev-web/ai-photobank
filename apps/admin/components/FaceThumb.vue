<template>
  <div
    class="face-thumb"
    :style="{ width: `${size}px`, height: `${size}px`, ...faceCropStyle(url, face.box) }"
    role="img"
    :aria-label="face.person ? `Лицо: ${face.person.name}` : 'Лицо'"
    :title="face.image.original_name"
  />
</template>

<script setup lang="ts">
import type { Face } from '~/types/api'
import { faceCropStyle } from '~/utils/faceCrop'

const props = withDefaults(defineProps<{ face: Face; size?: number }>(), { size: 96 })
// The preview is enough for a face and far lighter than the original.
const url = computed(() => props.face.image.urls.preview ?? props.face.image.urls.thumbnail)
</script>

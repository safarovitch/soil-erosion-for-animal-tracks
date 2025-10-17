<template>
  <div v-if="visible" class="fixed top-0 left-0 right-0 z-50">
    <div class="bg-blue-600 h-1 relative overflow-hidden">
      <div
        class="absolute top-0 left-0 h-full bg-blue-400 transition-all duration-300 ease-out"
        :style="{ width: progress + '%' }"
      ></div>
      <div class="absolute top-0 left-0 h-full w-full bg-gradient-to-r from-transparent via-white to-transparent opacity-30 animate-pulse"></div>
    </div>
    <div v-if="message" class="bg-blue-600 text-white text-sm px-4 py-2 flex items-center">
      <div class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent mr-2"></div>
      {{ message }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  progress: {
    type: Number,
    default: 0
  },
  message: {
    type: String,
    default: ''
  }
})
</script>

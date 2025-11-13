<template>
  <Transition name="toast">
    <div
      v-if="visible"
      :class="[
        'relative w-full max-w-md rounded-lg shadow-2xl p-4 flex items-start space-x-3 pointer-events-auto',
        typeClass
      ]"
    >
      <div class="flex-shrink-0 text-2xl">
        {{ icon }}
      </div>
      <div class="flex-1">
        <h4 class="font-bold text-sm mb-1">{{ title }}</h4>
        <p class="text-sm">{{ message }}</p>
        <p v-if="details" class="text-xs mt-1 opacity-75">{{ details }}</p>
      </div>
      <button
        @click="close"
        class="flex-shrink-0 text-xl hover:opacity-75"
      >
        ✕
      </button>
    </div>
  </Transition>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  type: {
    type: String,
    default: 'info', // info, success, warning, error
  },
  title: String,
  message: String,
  details: String,
  duration: {
    type: Number,
    default: 0, // 0 = persist until dismissed
  },
  show: Boolean,
})

const emit = defineEmits(['close'])

const visible = ref(props.show)
let timeout = null

const typeClass = computed(() => {
  const classes = {
    info: 'bg-blue-50 border-l-4 border-blue-500 text-blue-900',
    success: 'bg-green-50 border-l-4 border-green-500 text-green-900',
    warning: 'bg-yellow-50 border-l-4 border-yellow-500 text-yellow-900',
    error: 'bg-red-50 border-l-4 border-red-500 text-red-900',
  }
  return classes[props.type] || classes.info
})

const icon = computed(() => {
  const icons = {
    info: 'ℹ️',
    success: '✅',
    warning: '⚠️',
    error: '❌',
  }
  return icons[props.type] || icons.info
})

const close = () => {
  visible.value = false
  emit('close')
  if (timeout) {
    clearTimeout(timeout)
  }
}

watch(() => props.show, (newVal) => {
  visible.value = newVal
  
  if (newVal && props.duration > 0) {
    // Auto-close after duration
    if (timeout) clearTimeout(timeout)
    timeout = setTimeout(() => {
      close()
    }, props.duration)
  }
})

// Start auto-close timer if initially visible
if (visible.value && props.duration > 0) {
  timeout = setTimeout(() => {
    close()
  }, props.duration)
}
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}

.toast-enter-from {
  transform: translateX(100%);
  opacity: 0;
}

.toast-leave-to {
  transform: translateX(100%);
  opacity: 0;
}
</style>



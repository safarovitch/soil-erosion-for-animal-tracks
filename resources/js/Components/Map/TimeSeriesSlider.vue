<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Time Series</h3>

    <!-- Year Slider -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Year: {{ selectedYear }}
      </label>
      <input
        type="range"
        :min="startYear"
        :max="endYear"
        :value="selectedYear"
        @input="handleYearChange"
        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer slider"
      />
      <div class="flex justify-between text-xs text-gray-500 mt-1">
        <span>{{ startYear }}</span>
        <span>{{ endYear }}</span>
      </div>
    </div>

    <!-- Year Navigation -->
    <div class="flex space-x-2">
      <button
        @click="previousYear"
        :disabled="selectedYear <= startYear"
        class="flex-1 bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
      >
        ← Previous
      </button>
      <button
        @click="nextYear"
        :disabled="selectedYear >= endYear"
        class="flex-1 bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
      >
        Next →
      </button>
    </div>

    <!-- Quick Year Selection -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Quick Select
      </label>
      <div class="grid grid-cols-2 gap-2">
        <button
          v-for="year in quickYears"
          :key="year"
          @click="selectYear(year)"
          :class="[
            'px-3 py-2 rounded-md text-sm transition-colors',
            selectedYear === year
              ? 'bg-blue-600 text-white'
              : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
          ]"
        >
          {{ year }}
        </button>
      </div>
    </div>

    <!-- Year Range Selection -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Year Range
      </label>
      <div class="flex space-x-2">
        <select
          :value="startYear"
          @change="handleStartYearChange"
          class="flex-1 px-2 py-1 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option
            v-for="year in availableYears"
            :key="year"
            :value="year"
          >
            {{ year }}
          </option>
        </select>
        <span class="self-center text-gray-500">to</span>
        <select
          :value="endYear"
          @change="handleEndYearChange"
          class="flex-1 px-2 py-1 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option
            v-for="year in availableYears"
            :key="year"
            :value="year"
          >
            {{ year }}
          </option>
        </select>
      </div>
    </div>

    <!-- Animation Controls -->
    <div class="border-t pt-4">
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-medium text-gray-700">Auto Play</span>
        <button
          @click="toggleAutoPlay"
          :class="[
            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
            isAutoPlaying ? 'bg-blue-600' : 'bg-gray-200'
          ]"
        >
          <span
            :class="[
              'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
              isAutoPlaying ? 'translate-x-6' : 'translate-x-1'
            ]"
          />
        </button>
      </div>

      <div v-if="isAutoPlaying" class="flex items-center space-x-2">
        <label class="text-xs text-gray-600">Speed:</label>
        <select
          v-model="animationSpeed"
          class="px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option value="1000">Slow</option>
          <option value="500">Normal</option>
          <option value="200">Fast</option>
        </select>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'

// Props
const props = defineProps({
  selectedYear: {
    type: Number,
    required: true,
  },
  startYear: {
    type: Number,
    default: 2016,
  },
  endYear: {
    type: Number,
    default: 2024,
  },
})

// Emits
const emit = defineEmits(['update:selectedYear', 'year-change'])

// Reactive data
const isAutoPlaying = ref(false)
const animationSpeed = ref(500)
const animationInterval = ref(null)

// Computed properties
const availableYears = computed(() => {
  const years = []
  for (let year = props.startYear; year <= props.endYear; year++) {
    years.push(year)
  }
  return years
})

const quickYears = computed(() => {
  return [props.startYear, Math.floor((props.startYear + props.endYear) / 2), props.endYear]
})

// Methods
const handleYearChange = (event) => {
  const year = parseInt(event.target.value)
  emit('update:selectedYear', year)
  emit('year-change', year)
}

const previousYear = () => {
  if (props.selectedYear > props.startYear) {
    const year = props.selectedYear - 1
    emit('update:selectedYear', year)
    emit('year-change', year)
  }
}

const nextYear = () => {
  if (props.selectedYear < props.endYear) {
    const year = props.selectedYear + 1
    emit('update:selectedYear', year)
    emit('year-change', year)
  }
}

const selectYear = (year) => {
  emit('update:selectedYear', year)
  emit('year-change', year)
}

const handleStartYearChange = (event) => {
  const startYear = parseInt(event.target.value)
  emit('start-year-change', startYear)
}

const handleEndYearChange = (event) => {
  const endYear = parseInt(event.target.value)
  emit('end-year-change', endYear)
}

const toggleAutoPlay = () => {
  isAutoPlaying.value = !isAutoPlaying.value

  if (isAutoPlaying.value) {
    startAnimation()
  } else {
    stopAnimation()
  }
}

const startAnimation = () => {
  animationInterval.value = setInterval(() => {
    if (props.selectedYear < props.endYear) {
      nextYear()
    } else {
      // Loop back to start year
      emit('update:selectedYear', props.startYear)
      emit('year-change', props.startYear)
    }
  }, animationSpeed.value)
}

const stopAnimation = () => {
  if (animationInterval.value) {
    clearInterval(animationInterval.value)
    animationInterval.value = null
  }
}

// Watch for animation speed changes
watch(animationSpeed, () => {
  if (isAutoPlaying.value) {
    stopAnimation()
    startAnimation()
  }
})

// Cleanup on unmount
onUnmounted(() => {
  stopAnimation()
})
</script>

<style scoped>
.slider::-webkit-slider-thumb {
  appearance: none;
  height: 20px;
  width: 20px;
  border-radius: 50%;
  background: #3b82f6;
  cursor: pointer;
  border: 2px solid #ffffff;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.slider::-moz-range-thumb {
  height: 20px;
  width: 20px;
  border-radius: 50%;
  background: #3b82f6;
  cursor: pointer;
  border: 2px solid #ffffff;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
</style>
